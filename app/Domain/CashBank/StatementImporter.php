<?php

declare(strict_types=1);

namespace App\Domain\CashBank;

use App\Domain\Audit\Auditor;
use App\Domain\Imports\SpreadsheetReader;
use App\Models\CashBank\BankStatement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reads a bank's export (CSV, or an Excel workbook) into statement lines. Banks name columns
 * differently, so the header is matched by meaning: a date, a description,
 * and either one signed amount (with an optional DB/CR type column) or a
 * debit and a credit column; a balance and a reference when present.
 */
final class StatementImporter
{
    private const DATE = ['date', 'tanggal', 'tgl', 'trans_date', 'transaction date', 'posting date', 'tanggal transaksi'];

    private const DESCRIPTION = ['description', 'keterangan', 'memo', 'remark', 'remarks', 'uraian', 'narrative', 'details'];

    private const REFERENCE = ['reference', 'ref', 'no', 'no.', 'nomor', 'cheque', 'cheque no', 'no cek', 'ref no'];

    private const AMOUNT = ['amount', 'mutasi', 'nilai', 'jumlah', 'nominal'];

    private const DEBIT = ['debit', 'debet', 'keluar', 'out', 'withdrawal', 'db'];

    private const CREDIT = ['credit', 'kredit', 'masuk', 'in', 'deposit', 'cr'];

    private const TYPE = ['type', 'tipe', 'db/cr', 'd/k', 'dk', 'dc'];

    private const BALANCE = ['balance', 'saldo', 'running balance'];

    public function import(int $bankAccountId, string $path, ?string $originalName = null, ?int $userId = null): BankStatement
    {
        $rows = $this->rows($path);
        if ($rows === []) {
            throw new RuntimeException(__('The file holds no statement lines.'));
        }

        return DB::transaction(function () use ($bankAccountId, $path, $originalName, $userId, $rows): BankStatement {
            $dates = array_column($rows, 'trans_date');
            sort($dates);
            $statement = BankStatement::query()->create([
                'bank_account_id' => $bankAccountId,
                'from_date' => $dates[0],
                'to_date' => $dates[array_key_last($dates)],
                'source_file_name' => $originalName ?? basename($path),
                'source_file_path' => $path,
                'line_count' => count($rows),
                'imported_by' => $userId ?? auth()->id(),
            ]);
            foreach ($rows as $i => $row) {
                $statement->lines()->create($row + ['bank_account_id' => $bankAccountId, 'sort' => $i]);
            }
            Auditor::log('bank_statement_imported', $statement, $statement->source_file_name, ['lines' => count($rows)], $statement->to_date?->toDateString());

            return $statement;
        });
    }

    /** @return list<array{trans_date: string, description: ?string, reference: ?string, amount: int, balance: ?int}> */
    public function rows(string $path): array
    {
        $columns = null;
        $rows = [];
        foreach (SpreadsheetReader::rows($path) as $cells) {
            if (implode('', array_map(fn ($c) => trim((string) $c), $cells)) === '') {
                continue;
            }
            if ($columns === null) {
                $columns = $this->columns($cells);
                if ($columns === null) {
                    continue; // a title row above the header
                }
                if (! isset($columns['date']) || (! isset($columns['amount']) && ! isset($columns['debit']) && ! isset($columns['credit']))) {
                    throw new RuntimeException(__('The header needs a date column and an amount, or debit and credit, column.'));
                }

                continue;
            }
            $row = $this->row($cells, $columns);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** @return array<string, int>|null column role → index, or null when this is not the header */
    private function columns(array $cells): ?array
    {
        $map = [];
        foreach ($cells as $i => $cell) {
            $name = strtolower(trim((string) $cell));
            foreach (['date' => self::DATE, 'description' => self::DESCRIPTION, 'reference' => self::REFERENCE, 'amount' => self::AMOUNT, 'debit' => self::DEBIT, 'credit' => self::CREDIT, 'type' => self::TYPE, 'balance' => self::BALANCE] as $role => $names) {
                if (in_array($name, $names, true) && ! isset($map[$role])) {
                    $map[$role] = $i;
                    break;
                }
            }
        }

        return isset($map['date']) ? $map : null;
    }

    private function row(array $cells, array $columns): ?array
    {
        $date = $this->date((string) ($cells[$columns['date']] ?? ''));
        if ($date === null) {
            return null; // a footer or a subtotal row
        }
        if (isset($columns['amount'])) {
            $amount = $this->number($cells[$columns['amount']] ?? '');
            if (isset($columns['type'])) {
                $type = strtolower(trim((string) ($cells[$columns['type']] ?? '')));
                if (in_array($type, ['db', 'd', 'debit', 'debet', 'k', 'keluar', 'out'], true)) {
                    $amount = -abs($amount);
                } elseif (in_array($type, ['cr', 'c', 'credit', 'kredit', 'm', 'masuk', 'in'], true)) {
                    $amount = abs($amount);
                }
            }
        } else {
            $amount = $this->number($cells[$columns['credit']] ?? '') - $this->number($cells[$columns['debit']] ?? '');
        }
        if ($amount === 0) {
            return null;
        }

        return [
            'trans_date' => $date,
            'description' => isset($columns['description']) ? mb_substr(trim((string) $cells[$columns['description']]), 0, 255) : null,
            'reference' => isset($columns['reference']) ? mb_substr(trim((string) $cells[$columns['reference']]), 0, 80) ?: null : null,
            'amount' => $amount,
            'balance' => isset($columns['balance']) && trim((string) $cells[$columns['balance']]) !== '' ? $this->number($cells[$columns['balance']]) : null,
        ];
    }

    private function date(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y', 'd.m.Y', 'd M Y', 'd/m/Y H:i', 'Y-m-d H:i:s'] as $format) {
            try {
                return CarbonImmutable::createFromFormat($format, $text)->toDateString();
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /** "1.234.567,00", "1,234,567.00", "-1234567", "(1.234)" and "1234567" all read as rupiah; cents are dropped. */
    private function number(mixed $text): int
    {
        $text = trim((string) $text);
        if ($text === '') {
            return 0;
        }
        $negative = str_starts_with($text, '-') || str_starts_with($text, '(') || str_ends_with($text, '-') || str_ends_with($text, ')');
        $clean = preg_replace('/[^\d.,]/', '', $text) ?? '';
        $lastDot = strrpos($clean, '.');
        $lastComma = strrpos($clean, ',');
        if ($lastDot !== false && $lastComma !== false) {
            $integer = substr($clean, 0, max($lastDot, $lastComma)); // the later separator starts the decimals
        } elseif ($lastComma !== false) {
            // one kind of separator: a single one not followed by a group of three is the decimal mark
            $integer = substr_count($clean, ',') === 1 && strlen($clean) - $lastComma - 1 !== 3 ? substr($clean, 0, $lastComma) : $clean;
        } elseif ($lastDot !== false) {
            $integer = substr_count($clean, '.') === 1 && strlen($clean) - $lastDot - 1 !== 3 ? substr($clean, 0, $lastDot) : $clean;
        } else {
            $integer = $clean;
        }
        $digits = preg_replace('/\D/', '', $integer) ?? '';
        $value = $digits === '' ? 0 : (int) $digits;

        return $negative ? -$value : $value;
    }
}
