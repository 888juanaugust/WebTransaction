<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bilyet giro — the postdated cheque Indonesian wholesale actually runs on.
 *
 * A customer settles a Rp 40 million invoice by handing over a piece of paper
 * dated sixty days out. Until this table existed the system had two ways to
 * record that, both wrong: call it a payment, and the books claim money that
 * is not in the bank while the customer's credit limit frees up; or record
 * nothing, and a drawer full of paper worth a quarter of the receivables
 * appears nowhere at all.
 *
 * A giro is neither cash nor an ordinary receivable. It is **a promise with a
 * date on it that can fail**, and the failing is the point: a giro tolak —
 * bounced, usually for insufficient funds — is the single most common way a
 * wholesaler loses money to a customer who looked like they were paying.
 *
 * So it gets its own account on each side of the balance sheet. Piutang Giro
 * is what customers owe us on paper we hold; Utang Giro is what we owe on
 * paper somebody else holds. Both sit beside their ordinary counterparts
 * rather than inside them, because "backed by a signed instrument with a due
 * date" and "backed by an invoice and goodwill" are different risks and the
 * neraca should say which is which.
 *
 * One table, two directions. The lifecycle is identical read from either end —
 * outstanding, then cleared or bounced or handed back — and splitting it into
 * `giro_masuk` and `giro_keluar` would mean two copies of the same state
 * machine, kept in step by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('giros', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 30)->unique();

            // masuk (from a customer) | keluar (to a supplier)
            $table->string('arah', 10);

            /*
             * Exactly one of these is set, decided by `arah`. Not a polymorphic
             * pair: a giro's counterparty is a customer or a supplier, both of
             * which are real tables with real foreign keys, and losing that
             * to a `counterparty_type` string would cost every join in the
             * reports for nothing.
             */
            $table->foreignId('company_id')->nullable()->constrained('companies');
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers');

            /*
             * The invoice or bill this covers, when it covers exactly one.
             *
             * Often it does not: a customer hands over one giro against three
             * months of invoices. That case is left null deliberately —
             * clearing then records an unmatched payment, which finance
             * allocates through the screen that already exists for exactly
             * this. Inventing a giro-to-many-invoices table would be a second
             * allocation mechanism competing with the working one.
             */
            $table->foreignId('invoice_id')->nullable()->constrained('invoices');
            $table->foreignId('supplier_bill_id')->nullable()->constrained('supplier_bills');

            /*
             * What is printed on the paper. The warkat number is the giro's
             * real identity — ours is an internal reference — and entering the
             * same one twice would double an asset, so the pair is unique.
             *
             * Scoped to the issuing bank rather than global: warkat numbers are
             * printed per bank and six digits, so two banks colliding over the
             * years is ordinary rather than remarkable.
             */
            $table->string('bank_penerbit', 60);
            $table->string('nomor_warkat', 40);

            $table->bigInteger('nilai_rupiah');

            $table->date('tanggal_terima');

            /*
             * The date printed on the giro: the first day it may be banked.
             * Everything about a giro's worklist hangs off this.
             */
            $table->date('tanggal_jatuh_tempo');

            /*
             * When we actually banked it. A date rather than a state, because
             * it is a fact about the giro and not a stage of its life — the
             * money has not moved and the books have not changed. It answers
             * "have I forgotten to bank this", which is a different question
             * from "has it cleared".
             *
             * Only ever set on a giro masuk. On one we issued, the supplier
             * banks it and we find out when the bank debits us.
             */
            $table->date('tanggal_setor')->nullable();

            // beredar | cair | ditolak | dibatalkan
            $table->string('status', 20)->default('beredar');

            $table->date('tanggal_selesai')->nullable();

            /*
             * Why it bounced, in the words the bank used — "saldo tidak
             * cukup", "tanda tangan tidak sesuai", "rekening ditutup". Which
             * one it was decides whether this is a customer with a cash flow
             * problem or a customer to stop trading with.
             */
            $table->text('alasan_selesai')->nullable();

            /*
             * The payment this became when it cleared, so the money is
             * traceable in both directions.
             */
            $table->foreignId('payment_entry_id')->nullable()->constrained('payment_entries');
            $table->foreignId('supplier_payment_entry_id')->nullable()
                ->constrained('supplier_payment_entries');

            $table->text('catatan')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('resolved_by')->nullable()->constrained('users');

            $table->timestamps();

            $table->unique(['bank_penerbit', 'nomor_warkat']);
            $table->index(['arah', 'status', 'tanggal_jatuh_tempo']);
            $table->index(['company_id', 'status']);
            $table->index(['supplier_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('giros');
    }
};
