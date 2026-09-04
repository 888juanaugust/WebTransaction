<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One transfer, several fakturs.
 *
 * `payment_entries.invoice_id` could name exactly one bill, which is not how
 * a customer on 30-day terms pays. They transfer one figure at the end of the
 * month covering four fakturs and part of a fifth. Finance had two ways to
 * record that, both wrong: split the transfer into four entries, which then
 * do not tie to the single line on the bank statement the reconciliation desk
 * ticks against; or point the whole amount at one faktur.
 *
 * The second was worse than untidy. Allocating a Rp 50.000.000 payment to a
 * Rp 12.000.000 faktur marked that faktur paid, left its outstanding at
 * **negative thirty-eight million**, left the customer's other fakturs at
 * their full amount, and dropped the entry out of the unmatched queue — so
 * the remaining Rp 38.000.000 of the customer's money was on no invoice, in
 * no queue, and quietly netting against their exposure.
 *
 * So the link between money and bills becomes a row of its own, with its own
 * rupiah. The entry stays the record of *money arriving* — one entry, one
 * bank line, which is what makes reconciliation possible — and allocation
 * becomes the separate question of what that money settles.
 *
 * **Append-only, like the ledger it belongs to.** An allocation is never
 * edited or deleted; taking one back inserts its negative. That is the same
 * rule as `payment_entries` and for the same reason: the history of what
 * finance believed at each point is evidence, and a disputed application
 * months later is answerable only if the wrong answer is still there.
 *
 * The backfill matters as much as the table. Every existing entry carrying an
 * `invoice_id` gets one allocation for its full amount, so the moment this
 * migration lands `amountPaid()` returns exactly what it returned before —
 * a schema change that quietly restates last month's receivables would be
 * indistinguishable from a bug.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payment_entry_id')->constrained('payment_entries')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();

            /*
             * How much of this entry settles this invoice. BIGINT rupiah,
             * never a float or a decimal — invariant 6.
             *
             * Signed, because taking an allocation back is a negative row
             * rather than a delete. Every sum over this column is therefore
             * the net position, and there is no "is it still valid" flag for
             * a query to forget.
             */
            $table->bigInteger('amount_rupiah');

            /*
             * Who applied it. Nullable and restricted: settlement applies a
             * customer deposit with no person behind it — the money is the
             * actor — and a leaver's allocations are evidence of what they
             * decided, so the row outlives the account.
             */
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();

            // The reversing row points at what it takes back, so a pair is
            // legible without inferring it from equal and opposite amounts.
            $table->foreignId('reverses_allocation_id')->nullable()
                ->constrained('payment_allocations')->restrictOnDelete();

            $table->text('catatan')->nullable();

            /*
             * Region-scoped like everything else that touches a region's
             * books. Not merely inherited through the invoice: the global
             * scope can only keep one region's staff out of another's rows
             * if the column is on the row.
             */
            $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();

            $table->timestamps();

            // The two reads: what has settled this invoice, and what is left
            // unallocated on this entry.
            $table->index(['invoice_id']);
            $table->index(['payment_entry_id']);
        });

        /*
         * Backfill: every entry already pointed at an invoice becomes one
         * allocation for its whole amount.
         *
         * Reversal entries carry negative amounts and are copied as they are,
         * so a reversed payment stays reversed. `paid_at` is used for
         * created_at rather than now(), because when the money was applied is
         * a fact about the payment, not about the day this migration ran.
         *
         * Region comes from the entry, which is already scoped — the
         * allocation belongs to the same books.
         */
        DB::statement(<<<'SQL'
            INSERT INTO payment_allocations
                (payment_entry_id, invoice_id, amount_rupiah, actor_id,
                 catatan, region_id, created_at, updated_at)
            SELECT id, invoice_id, amount_rupiah, actor_id,
                   'Dipindahkan dari pencatatan lama (satu pembayaran, satu faktur).',
                   region_id, paid_at, paid_at
            FROM payment_entries
            WHERE invoice_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
