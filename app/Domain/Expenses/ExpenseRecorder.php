<?php

declare(strict_types=1);

namespace App\Domain\Expenses;

use App\Domain\Accounting\AccountCode;
use App\Domain\Accounting\AccountType;
use App\Domain\Accounting\DocumentPoster;
use App\Domain\Audit\AuditLogger;
use App\Domain\Documents\DocumentNumberGenerator;
use App\Models\Account;
use App\Models\Expense;
use App\Models\Supplier;
use App\Models\User;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Recording money that went out on something other than goods.
 *
 * The gap this fills is larger than it looks. `JournalDraft::manual()` has
 * existed since the ledger was built and nothing ever called it, which meant
 * rent, wages, fuel and the courier had no way into the books at all. A laba
 * rugi that shows revenue and cost of sales and almost no overhead is not a
 * conservative one — it is wrong, and it overstates the profit that PPh is
 * calculated on.
 *
 * Three guards, each protecting something that would be quiet if it broke.
 */
class ExpenseRecorder
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly DocumentPoster $poster,
        private readonly AuditLogger $audit,
    ) {}

    public function record(
        DateTimeInterface $tanggal,
        string $accountCode,
        PaidFrom $paidFrom,
        int $amountRupiah,
        string $keterangan,
        User $actor,
        ?Supplier $supplier = null,
        ?string $referensi = null,
        ?string $catatan = null,
    ): Expense {
        $this->assertMayRecord($actor);

        if ($amountRupiah <= 0) {
            throw new DomainException('Nilai beban harus lebih dari nol.');
        }

        if (trim($keterangan) === '') {
            throw new DomainException('Beban harus punya keterangan — nanti tidak ada yang ingat.');
        }

        $account = $this->assertPostableExpenseAccount($accountCode);
        $date = Carbon::parse($tanggal)->startOfDay();

        return DB::transaction(function () use (
            $date, $account, $paidFrom, $amountRupiah, $keterangan, $actor, $supplier, $referensi, $catatan
        ) {
            $expense = Expense::create([
                'nomor' => $this->numbers->nextExpenseNumber($date),
                'tanggal' => $date,
                'account_id' => $account->id,
                'dibayar_dari' => $paidFrom,
                'amount_rupiah' => $amountRupiah,
                'keterangan' => trim($keterangan),
                'supplier_id' => $supplier?->id,
                'referensi' => $referensi,
                'catatan' => $catatan,
                'created_by' => $actor->id,
            ]);

            $this->poster->expenseRecorded($expense->refresh(), $actor);

            $this->audit->log(
                action: 'expense_recorded',
                subject: $expense,
                newValue: [
                    'nomor' => $expense->nomor,
                    'akun' => $account->kode,
                    'dibayar_dari' => $paidFrom->value,
                    'amount_rupiah' => $amountRupiah,
                    'keterangan' => trim($keterangan),
                ],
                actor: $actor,
            );

            return $expense->refresh();
        });
    }

    /**
     * Undo one, by writing its opposite.
     *
     * Never an edit and never a delete. The original stays on the ledger and
     * the correction stands beside it, which is both what the append-only rule
     * requires and what somebody asking "why did this change" needs to see.
     */
    public function reverse(Expense $expense, User $actor, string $alasan): Expense
    {
        $this->assertMayRecord($actor);

        if ($expense->isReversal()) {
            throw new DomainException(
                "Beban {$expense->nomor} sudah merupakan koreksi; tidak bisa dikoreksi lagi."
            );
        }

        if ($expense->isReversed()) {
            throw new DomainException("Beban {$expense->nomor} sudah dikoreksi.");
        }

        if (trim($alasan) === '') {
            throw new DomainException('Koreksi harus menyebutkan alasannya.');
        }

        return DB::transaction(function () use ($expense, $actor, $alasan) {
            $reversal = Expense::create([
                'nomor' => $this->numbers->nextExpenseNumber(now()),
                // Dated today, not back on the original's date: the correction
                // happened now, and back-dating it would move a figure in a
                // month that may already have been closed and reported.
                'tanggal' => now()->startOfDay(),
                'account_id' => $expense->account_id,
                'dibayar_dari' => $expense->dibayar_dari,
                'amount_rupiah' => $expense->amount_rupiah,
                'keterangan' => "Koreksi {$expense->nomor}: {$alasan}",
                'supplier_id' => $expense->supplier_id,
                'referensi' => $expense->referensi,
                'created_by' => $actor->id,
            ]);

            $reversal->forceFill(['reverses_expense_id' => $expense->id])->save();

            $this->poster->expenseRecorded($reversal->refresh(), $actor, reverse: true);

            $this->audit->log(
                action: 'expense_reversed',
                subject: $expense,
                newValue: ['koreksi' => $reversal->nomor, 'alasan' => $alasan],
                actor: $actor,
            );

            return $reversal->refresh();
        });
    }

    /**
     * Which accounts an expense may be booked to.
     *
     * Operating expense only, and that excludes two things that would each be
     * silently damaging:
     *
     * - **Harga Pokok Penjualan.** It is derived entirely from stock movements
     *   at the cost frozen on each one. A hand-entered debit would put a figure
     *   into gross margin that no goods back, and the inventory tie — the check
     *   that proves Persediaan equals the sum of what is on the shelves — would
     *   stop meaning anything.
     * - **Anything that is not an expense at all.** Buying a vehicle is not a
     *   cost of August; it is an asset, and it belongs to the fixed asset
     *   register rather than to this screen. Refusing it here is what stops
     *   this becoming a general-purpose journal entry form by accident.
     */
    private function assertPostableExpenseAccount(string $accountCode): Account
    {
        $account = Account::byCode($accountCode);

        if ($account->tipe !== AccountType::Beban) {
            throw new DomainException(
                "Akun {$account->kode} ({$account->nama}) bukan akun beban."
            );
        }

        if (! $account->dapat_diposting) {
            throw new DomainException("Akun {$account->kode} adalah akun induk, bukan akun posting.");
        }

        $hpp = Account::byCode(AccountCode::HARGA_POKOK_PENJUALAN);

        if ($account->id === $hpp->id || (int) $account->parent_id === (int) $hpp->parent_id) {
            throw new DomainException(
                'Harga pokok penjualan datang dari pergerakan stok, bukan dari input manual.'
            );
        }

        return $account;
    }

    private function assertMayRecord(User $actor): void
    {
        if (! $actor->role()->canPostJournals()) {
            throw new DomainException('Anda tidak berhak mencatat beban.');
        }
    }
}
