<?php

declare(strict_types=1);

use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Balances brought in from the old books.
 *
 * Going live does not start from zero: customers already owe money for sales
 * the old system made, and suppliers are already owed for goods already on
 * the shelf. Those have to exist here as documents — an invoice that ages,
 * gets chased and gets paid through the normal ledger; a bill that gets
 * paid through the normal supplier ledger — without pretending this system
 * sold or bought anything.
 *
 * Three things make that possible:
 *
 * - `invoices.order_id` becomes nullable. An opening invoice has no order:
 *   no lines, no shipment, no price snapshot. Every reader that walks from
 *   an invoice to its order already tolerates null (the faktur PDF, the
 *   credit-note issuer, settlement's order advance) or joins `orders`
 *   inner, which drops these rows from sales and KPI figures — correctly,
 *   since they are not this system's sales.
 * - `saldo_awal` on both tables, so commission and the tax export can leave
 *   them out by name rather than by inference.
 * - The `3-8000 Saldo Awal Konversi` equity account, seeded here by the
 *   same idempotent seeder every account addition has used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->change();
            $table->boolean('saldo_awal')->default(false);
        });

        Schema::table('supplier_bills', function (Blueprint $table) {
            $table->boolean('saldo_awal')->default(false);
        });

        (new ChartOfAccountsSeeder)->run();
    }

    public function down(): void
    {
        Schema::table('supplier_bills', fn (Blueprint $table) => $table->dropColumn('saldo_awal'));

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('saldo_awal');
            // Not reverted to NOT NULL: opening invoices may exist by now.
        });

        // Accounts are not dropped. See the original chart migration.
    }
};
