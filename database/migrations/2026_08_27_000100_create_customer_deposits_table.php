<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uang muka pelanggan — money in before anything is owed.
 *
 * A new customer with no credit line pays half up front. A distributor secures
 * a slow-moving special order with a deposit. Both are ordinary B2B, and until
 * now the only way to record either was a payment entry with no invoice on it.
 *
 * That mis-states the balance sheet in a way that is easy to miss and hard to
 * unpick. An unallocated payment posts **Cr Piutang Usaha**, so a customer who
 * owes nothing and has paid ten million shows a receivable of minus ten
 * million. The neraca then reports both that customers owe us less than they
 * do, and that we owe nobody anything — when in fact we are holding their cash
 * and have delivered nothing for it. Those are opposite sides of the sheet.
 *
 * So a deposit is a liability with its own account, and the two look nothing
 * alike in the books:
 *
 *   Pembayaran belum cocok   Dr Bank / Cr Piutang Usaha   a debt exists, we
 *                                                         cannot yet say which
 *   Uang muka                Dr Bank / Cr Uang Muka       no debt exists yet
 *
 * The distinction is which question is open. An unmatched payment is money
 * against a debt we know exists and have not identified; a deposit is money
 * against a debt that has not been incurred. Staff will confuse the two, so
 * the screens say which is which rather than assuming.
 *
 * ## Why applications are their own table
 *
 * One deposit rarely clears against one invoice. A five-million deposit taken
 * on an order that ships in three drops settles three invoices, and a refund
 * of whatever is left is a fourth event. Money is an append-only ledger here
 * (invariant 1), so each of those is a row and the deposit's remaining balance
 * is `jumlah_rupiah` less the sum of them — a cached column that must always be
 * reconstructible, same rule as stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_deposits', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 30)->unique();

            $table->foreignId('company_id')->constrained('companies');

            /*
             * Optional, and the reason it is optional matters. A deposit is
             * usually taken against a specific order — that is the whole point
             * of asking for one — but the order does not always exist yet. A
             * customer transfers a round number to open an account before
             * anybody has typed the first line.
             */
            $table->foreignId('order_id')->nullable()->constrained('orders');

            $table->date('tanggal');

            $table->bigInteger('jumlah_rupiah');

            // kas | bank — which account the money actually landed in.
            $table->string('diterima_di', 10);

            // Their transfer reference or the cash receipt number.
            $table->string('referensi', 60)->nullable();

            /*
             * Cached, and reconstructible: both must equal the sum of the
             * movements of that jenis. The reconciliation proves it.
             */
            $table->bigInteger('terpakai_rupiah')->default(0);
            $table->bigInteger('dikembalikan_rupiah')->default(0);

            // held | closed — closed once nothing is left, however it left.
            $table->string('status', 20)->default('held');

            $table->text('catatan')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index('tanggal');
        });

        Schema::create('customer_deposit_movements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_deposit_id')->constrained('customer_deposits')->cascadeOnDelete();

            // pakai | kembali
            $table->string('jenis', 10);

            // Always positive. The jenis says where it went; a signed amount
            // would let a typo add money to a deposit nobody paid.
            $table->bigInteger('jumlah_rupiah');

            // Set on an application, null on a refund.
            $table->foreignId('invoice_id')->nullable()->constrained('invoices');

            /*
             * An application creates a payment entry so the invoice settles
             * through the machinery that already exists — the same rows the
             * statement, the ageing report and the portal already read. Kept
             * here so the two can never drift apart unnoticed.
             */
            $table->foreignId('payment_entry_id')->nullable()->constrained('payment_entries');

            $table->date('tanggal');
            $table->text('catatan')->nullable();

            $table->foreignId('actor_id')->constrained('users');
            $table->timestamp('created_at')->nullable();

            $table->index(['customer_deposit_id', 'jenis']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_deposit_movements');
        Schema::dropIfExists('customer_deposits');
    }
};
