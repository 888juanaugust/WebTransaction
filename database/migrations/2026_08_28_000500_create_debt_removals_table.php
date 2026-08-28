<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penghapusan piutang — a debt settled outside the system, needing two keys.
 *
 * The marketing in charge says the customer paid them directly; finance says
 * the money is real. Neither statement alone moves the books. The row is the
 * paperwork between those two statements: who claimed it, for how much, what
 * finance decided, and — once approved — which payment entry actually
 * settled the invoice. The books themselves never reference this table; the
 * payment entry the approval posts is the accounting fact, and this is the
 * authorisation trail behind it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debt_removals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();

            // What marketing says was paid — at most the outstanding balance
            // when they said it. Approval re-checks against the balance then.
            $table->bigInteger('amount_rupiah');

            // Marketing's account of the money: where it was handed over,
            // when, in what form. This is what finance verifies.
            $table->text('alasan');

            $table->string('status')->default('diajukan');
            $table->foreignId('initiated_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('keputusan_catatan')->nullable();

            // The entry the approval posted. The ledger row is the money;
            // this is the pointer that lets an auditor walk from claim to
            // cash without guessing.
            $table->foreignId('payment_entry_id')->nullable()->constrained('payment_entries')->restrictOnDelete();

            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['region_id', 'status']);
        });

        // One live claim per invoice. Two marketings cannot race the same
        // debt, and a rejected claim frees the invoice for a corrected one.
        DB::statement(
            "CREATE UNIQUE INDEX debt_removals_one_pending_per_invoice
             ON debt_removals (invoice_id) WHERE status = 'diajukan'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_removals');
    }
};
