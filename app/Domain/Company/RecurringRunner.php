<?php

declare(strict_types=1);

namespace App\Domain\Company;

use App\Domain\Access\BranchLimit;
use App\Domain\Access\Hak;
use App\Domain\Access\HakAkses;
use App\Domain\Access\MenuKey;
use App\Domain\Audit\Auditor;
use App\Domain\Numbering\NumberGenerator;
use App\Domain\Numbering\TransactionType;
use App\Domain\Posting\DocumentRepository;
use App\Domain\Shared\Format;
use App\Models\CashBank\CashPayment;
use App\Models\CashBank\CashReceipt;
use App\Models\Company\RecurringTransaction;
use App\Models\GeneralLedger\JournalVoucher;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Turns a recurring transaction into its document, dated the run date,
 * numbered from the type's default series, posted through the repository;
 * then moves the schedule on. Idempotent per date: a run never repeats a
 * date already run.
 */
final class RecurringRunner
{
    public function __construct(private readonly DocumentRepository $docs, private readonly NumberGenerator $numbers) {}

    /**
     * Every active schedule due on or before the date. A schedule that fails is reported and the others still run.
     *
     * @return array{made: list<string>, failed: list<string>}
     */
    public function runDue(\DateTimeInterface|string|null $on = null, ?int $userId = null): array
    {
        $on = CarbonImmutable::parse($on ?? today());
        $made = [];
        $failed = [];
        foreach (RecurringTransaction::query()->due($on)->orderBy('next_run_on')->get() as $recurring) {
            try {
                while ($recurring->status === 'active' && $recurring->next_run_on->lte($on)) {
                    $document = $this->run($recurring, $recurring->next_run_on, $userId);
                    $made[] = "{$recurring->name} → {$document->number}";
                    $recurring->refresh();
                }
            } catch (Throwable $e) {
                report($e);
                $failed[] = "{$recurring->name}: {$e->getMessage()}";
            }
        }

        return ['made' => $made, 'failed' => $failed];
    }

    /** One run of one schedule on a date; the schedule advances by its frequency. */
    public function run(RecurringTransaction $recurring, \DateTimeInterface|string|null $on = null, ?int $userId = null): Model
    {
        if ($recurring->status !== 'active') {
            throw new RuntimeException(__(':name is :status.', ['name' => $recurring->name, 'status' => $recurring->status]));
        }
        $on = CarbonImmutable::parse($on ?? $recurring->next_run_on);
        $userId ??= auth()->id() ?? $recurring->created_by; // the scheduled run makes it as the schedule's author

        $this->assertMayMake($recurring, $userId);

        return DB::transaction(function () use ($recurring, $on, $userId): Model {
            // Two runs at once (the morning schedule and "Run everything due") make one document per date.
            $locked = RecurringTransaction::query()->whereKey($recurring->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'active' || ($locked->last_run_on !== null && $locked->last_run_on->gte($on))) {
                throw new RuntimeException(__(':name has already run for :date.', ['name' => $recurring->name, 'date' => Format::date($on)]));
            }
            $recurring->setRawAttributes($locked->getAttributes(), true);
            $document = $this->make($recurring, $on, $userId);
            $this->docs->created($document);

            $next = $recurring->nextAfter(CarbonImmutable::instance($recurring->next_run_on)->lte($on) ? CarbonImmutable::instance($recurring->next_run_on) : $on);
            $done = $recurring->end_on !== null && $next->gt($recurring->end_on);
            $recurring->forceFill(['last_run_on' => $on, 'next_run_on' => $next, 'run_count' => $recurring->run_count + 1, 'status' => $done ? 'done' : 'active'])->saveQuietly();
            Auditor::log('recurring_run', $recurring, $recurring->name, ['document' => $document->getMorphClass().':'.$document->getKey(), 'on' => $on->toDateString()], $on->toDateString());

            return $document;
        });
    }

    /** The document is made as a user (the one running it, or the schedule's author) who may make that kind of document. */
    private function assertMayMake(RecurringTransaction $recurring, ?int $userId): void
    {
        $user = $userId !== null ? User::query()->find($userId) : null;
        // The schedule runs as a person: one who has left, or who may not book in its branch, makes nothing.
        $branch = $recurring->template['branch_id'] ?? null;
        if ($user !== null && (! $user->is_active || ! BranchLimit::allows($user, filled($branch) ? (int) $branch : null))) {
            throw new RuntimeException(__(':name runs as :user, who is no longer active or not in its branch; it is not run.', ['user' => $user->name, 'name' => $recurring->name]));
        }
        $screen = match ($recurring->transaction_type) {
            'journal_voucher' => MenuKey::JournalVouchers,
            'cash_payment' => MenuKey::Payments,
            'cash_receipt' => MenuKey::Receipts,
            default => null,
        };
        if ($user !== null && $screen !== null && ! app(HakAkses::class)->allows($user, $screen, Hak::Create)) {
            throw new RuntimeException(__(':user may not make :documents; :name is not run.', ['user' => $user->name, 'documents' => $screen->label(), 'name' => $recurring->name]));
        }
    }

    private function make(RecurringTransaction $recurring, CarbonImmutable $on, ?int $userId): Model
    {
        $t = $recurring->template;
        $lines = $t['lines'] ?? [];
        $header = ['trans_date' => $on->toDateString(), 'description' => $t['description'] ?? $recurring->name, 'branch_id' => $t['branch_id'] ?? null, 'created_by' => $userId];

        switch ($recurring->transaction_type) {
            case 'journal_voucher':
                $doc = JournalVoucher::query()->create($header + ['number' => $this->number(TransactionType::JournalVoucher, $on, $userId)]);
                foreach (array_values($lines) as $i => $line) {
                    $doc->lines()->create(['sort' => $i, 'account_id' => $line['account_id'], 'debit' => (int) ($line['debit'] ?? 0), 'credit' => (int) ($line['credit'] ?? 0), 'memo' => $line['memo'] ?? null]);
                }
                break;
            case 'cash_payment':
                $doc = CashPayment::query()->create($header + ['number' => $this->number(TransactionType::CashBankVoucher, $on, $userId), 'bank_account_id' => $t['bank_account_id'], 'payee' => $t['payee'] ?? null]);
                foreach (array_values($lines) as $i => $line) {
                    $doc->lines()->create(['sort' => $i, 'account_id' => $line['account_id'], 'amount' => (int) ($line['amount'] ?? 0), 'memo' => $line['memo'] ?? null]);
                }
                break;
            case 'cash_receipt':
                $doc = CashReceipt::query()->create($header + ['number' => $this->number(TransactionType::CashBankVoucher, $on, $userId), 'bank_account_id' => $t['bank_account_id'], 'payer' => $t['payer'] ?? null]);
                foreach (array_values($lines) as $i => $line) {
                    $doc->lines()->create(['sort' => $i, 'account_id' => $line['account_id'], 'amount' => (int) ($line['amount'] ?? 0), 'memo' => $line['memo'] ?? null]);
                }
                break;
            default:
                throw new RuntimeException(__('Recurring :transaction_type is not supported.', ['transaction_type' => $recurring->transaction_type]));
        }
        $doc->refreshTotal();

        return $doc;
    }

    private function number(TransactionType $type, CarbonImmutable $on, ?int $userId): string
    {
        $user = $userId ? User::query()->find($userId) : null;
        $series = $this->numbers->defaultSeries($type, $user) ?? throw new RuntimeException(__('No number series for :type.', ['type' => $type->getLabel()]));

        return $this->numbers->next($series, $on);
    }
}
