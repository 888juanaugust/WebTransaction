<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nota kredit — money going back to a customer.
 *
 * The Syarat Penjualan has described a returns process since it was written and
 * the system has had no way to record one. Staff faced with a returned carton
 * have had two options, both bad: edit the invoice, which the system rightly
 * refuses, or write the customer's account down by hand somewhere the books
 * never see.
 *
 * A credit note is the document that makes it recordable. Two kinds, and the
 * difference is whether goods move:
 *
 *   - **retur barang** — the goods came back. Stock goes up, cost of sales
 *     comes down, and the customer owes less.
 *   - **potongan** — nothing came back. A price was wrong, or something
 *     arrived damaged and was written off rather than returned. Only the
 *     money moves.
 *
 * Same rules as every other money document here: figures are snapshotted from
 * the invoice being credited rather than re-resolved, tax is computed per line,
 * and posting is terminal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 30)->unique();

            /*
             * Always against an invoice. A credit with nothing behind it is
             * a discount somebody gave away after the fact, and if that is
             * what happened it belongs on the next order's price, not here.
             */
            $table->foreignId('invoice_id')->constrained('invoices');
            $table->foreignId('company_id')->constrained('companies');

            // retur_barang | potongan
            $table->string('jenis', 20);

            $table->date('tanggal');

            /*
             * Mandatory. This is money leaving on somebody's say-so, and
             * "why" is the first question anybody will ask of it later.
             */
            $table->text('alasan');

            // Where returned goods land. Null for a potongan — nothing arrives.
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses');

            // Summed from the line snapshots at posting; never editable after.
            $table->bigInteger('subtotal_rupiah')->default(0);
            $table->bigInteger('dpp_rupiah')->default(0);
            $table->bigInteger('ppn_rupiah')->default(0);
            $table->bigInteger('total_rupiah')->default(0);

            /*
             * What the returned goods cost us, at the cost they left at rather
             * than today's average. Stored so the reversal of cost of sales is
             * reconstructible from the document without replaying the ledger.
             */
            $table->bigInteger('hpp_rupiah')->default(0);

            $table->string('kode_transaksi', 2)->nullable();

            /*
             * Under the PPN rules a return is evidenced by a nota retur that
             * the *buyer* issues to the seller. This records its number when
             * one is received. Confirm the current Coretax treatment with the
             * accountant before relying on it — see docs/MAP.md.
             */
            $table->string('nomor_nota_retur', 60)->nullable();

            $table->string('status', 20)->default('draft');

            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->timestamp('posted_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'tanggal']);
            $table->index(['invoice_id', 'status']);
        });

        Schema::create('credit_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_note_id')->constrained('credit_notes')->cascadeOnDelete();

            /*
             * The order line being credited. Null only for a potongan that is
             * not about a specific line — a settlement on the whole invoice.
             */
            $table->foreignId('order_line_id')->nullable()->constrained('order_lines');

            $table->string('sku', 50);
            $table->unsignedInteger('urutan')->default(0);
            $table->string('deskripsi')->nullable();

            /*
             * Both units, same as an order line. Suppliers and customers talk
             * in cartons; stock is counted in pieces; and a return of "two
             * cartons" has to mean the same number of pieces it shipped as.
             */
            $table->string('ordered_unit', 8)->nullable();
            $table->integer('ordered_qty')->default(0);
            $table->integer('qty_per_ctn_snapshot')->default(1);
            $table->integer('qty_base')->default(0);

            // Snapshotted from the order line, never re-resolved.
            $table->bigInteger('unit_price_rupiah')->default(0);
            $table->bigInteger('line_total_rupiah')->default(0);
            $table->bigInteger('dpp_rupiah')->default(0);
            $table->bigInteger('ppn_rupiah')->default(0);

            // The frozen cost of the goods coming back. Zero for a potongan.
            $table->bigInteger('unit_cost_rupiah')->default(0);
            $table->bigInteger('line_cost_rupiah')->default(0);

            $table->timestamps();

            $table->index(['credit_note_id', 'urutan']);
            $table->index('order_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_lines');
        Schema::dropIfExists('credit_notes');
    }
};
