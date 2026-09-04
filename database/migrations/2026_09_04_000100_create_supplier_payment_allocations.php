<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The payables twin of `payment_allocations`, and the same defect on the
 * other side of the books.
 *
 * `supplier_payment_entries.supplier_bill_id` could name exactly one bill,
 * and paying a supplier once a month against everything they have sent is
 * more common than a customer paying us that way — it is the ordinary shape
 * of a trade account.
 *
 * Recording a Rp 42.000.000 transfer against a Rp 12.000.000 bill marked that
 * bill paid and left it at **minus thirty million**, while the Rp 30.000.000
 * bill it also covered stayed fully open. The supplier's *total* came out
 * right, which is what made it quiet: `outstandingFor()` netted to zero and
 * looked correct. Per bill it was nonsense — one bill claiming we overpaid,
 * another claiming we still owe — so Umur hutang went on ageing a bill that
 * had been settled, and "which bills are still to pay" had no answer at all.
 *
 * Same shape as the receivable side, deliberately: an entry is money leaving,
 * one row per bank line so the reconciliation desk can tick it, and an
 * allocation is the separate question of which debt it discharges. Append-only
 * — undoing is a negative row.
 *
 * The backfill copies every existing entry's `supplier_bill_id` as a
 * full-amount allocation, so `amountPaid()` returns exactly what it returned
 * before. A schema change that silently restated last month's payables would
 * be indistinguishable from a bug.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payment_allocations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('supplier_payment_entry_id')
                ->constrained('supplier_payment_entries')->cascadeOnDelete();
            $table->foreignId('supplier_bill_id')
                ->constrained('supplier_bills')->restrictOnDelete();

            // BIGINT rupiah, signed: a reversal is a negative row, so every
            // sum over this column is the net position.
            $table->bigInteger('amount_rupiah');

            // Nullable and restricted, like the entries: a giro clearing has
            // no person behind it, and a leaver's decisions are evidence.
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();

            $table->foreignId('reverses_allocation_id')->nullable()
                ->constrained('supplier_payment_allocations')->restrictOnDelete();

            $table->text('catatan')->nullable();

            $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();

            $table->timestamps();

            $table->index(['supplier_bill_id']);
            $table->index(['supplier_payment_entry_id']);
        });

        DB::statement(<<<'SQL'
            INSERT INTO supplier_payment_allocations
                (supplier_payment_entry_id, supplier_bill_id, amount_rupiah, actor_id,
                 catatan, region_id, created_at, updated_at)
            SELECT id, supplier_bill_id, amount_rupiah, actor_id,
                   'Dipindahkan dari pencatatan lama (satu pembayaran, satu tagihan).',
                   region_id, paid_at, paid_at
            FROM supplier_payment_entries
            WHERE supplier_bill_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_allocations');
    }
};
