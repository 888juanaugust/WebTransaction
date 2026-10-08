<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use App\Models\Sales\SalesInvoice;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The older e-Faktur CSV: an FK row per invoice, an LT row for the buyer and
 * an OF row per line, so a filing made under it can be reproduced. An invoice
 * deducting down payments is flagged as their settlement (FG_UANG_MUKA).
 */
final class LegacyCsvWriter
{
    /** @param  Collection<int, SalesInvoice>  $invoices */
    public function write(Collection $invoices): string
    {
        $cfg = config('pajak.legacy');
        $rows = [$cfg['header'], $cfg['lt'], $cfg['of']];

        foreach ($invoices as $invoice) {
            $buyer = TaxParty::fromParty($invoice->customer);
            $date = CarbonImmutable::parse($invoice->trans_date);
            // An invoice that deducts taxed down payments is their settlement (pelunasan): its own DPP and VAT are what
            // is left after the down payments', which are given beside them.
            $invoice->loadMissing('downPayments');
            $dpDpp = (int) $invoice->downPayments->sum('dpp_amount');
            $dpTax = (int) $invoice->downPayments->sum('tax_amount');
            $settles = $dpTax !== 0 || $dpDpp !== 0;
            $rows[] = [
                'FK', $cfg['kd_jenis_transaksi'], $cfg['fg_pengganti'], preg_replace('/\D/', '', (string) $invoice->nsfp) ?: '',
                (string) $date->month, (string) $date->year, $date->format('d/m/Y'),
                $buyer->idNumber ?: '000000000000000', $buyer->name, $buyer->address,
                (string) ((int) $invoice->dpp_total - $dpDpp), (string) ((int) $invoice->tax_total - $dpTax), '0', '',
                $settles ? $cfg['fg_uang_muka_pelunasan'] : '0', (string) $dpDpp, (string) $dpTax, '0', $invoice->number,
            ];
            $rows[] = ['LT', $buyer->idNumber ?: '000000000000000', $buyer->name, $buyer->address, '', '', '', '', '', '', '', '', '', ''];
            foreach ($invoice->lines as $line) {
                $gross = (int) $line->amount + (int) $line->discount_amount;
                $rows[] = [
                    'OF', (string) ($line->item?->item_tax_code ?: ''), (string) ($line->item?->name ?? ''),
                    $this->decimal($line->unit_price), $this->decimal($line->quantity), (string) $gross,
                    (string) (int) $line->discount_amount, (string) (int) $line->dpp_amount, (string) (int) $line->tax_amount, '0', '0',
                ];
            }
        }

        $out = fopen('php://memory', 'r+');
        foreach ($rows as $row) {
            fputcsv($out, array_map(self::cell(...), $row), ',', '"', '\\');
        }
        rewind($out);
        $csv = stream_get_contents($out) ?: '';
        fclose($out);

        return $csv;
    }

    private function decimal(string|int|float|null $value): string
    {
        // On the decimal string, never a float: a unit price keeps every digit it was stored with.
        return (string) BigDecimal::of(trim((string) ($value ?? '')) === '' ? '0' : (string) $value)->toScale(4, RoundingMode::HalfUp)->strippedOfTrailingZeros();
    }

    /** A spreadsheet opening the file reads a cell starting with = + - @ (or a tab or return) as a formula: such text is quoted. */
    private static function cell(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($value) ? "'".$value : $value;
    }
}
