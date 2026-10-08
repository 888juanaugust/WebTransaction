<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What an invoice deducts of a down payment is kept split, as every taxed amount is: the share of the down
 * payment's price, of its tax base and of its VAT. The VAT return and the e-Faktur settlement row read these.
 */
return new class extends Migration
{
    private const TABLES = [
        'sales_invoice_down_payments' => ['sales_down_payment_id', 'sales_down_payments'],
        'purchase_invoice_down_payments' => ['purchase_down_payment_id', 'purchase_down_payments'],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => [$key, $parent]) {
            Schema::table($table, function (Blueprint $t) {
                $t->bigInteger('net_amount')->default(0);
                $t->bigInteger('dpp_amount')->default(0);
                $t->bigInteger('tax_amount')->default(0);
            });
            // The same split the invoice posting has always made: in proportion to the down payment's own totals.
            DB::statement("update {$table} u set
                net_amount = case when d.total > 0 then round(u.amount::numeric * d.subtotal / d.total) else u.amount end,
                dpp_amount = case when d.total > 0 then round(u.amount::numeric * d.dpp_total / d.total) else 0 end
                from {$parent} d where d.id = u.{$key}");
            DB::statement("update {$table} set tax_amount = amount - net_amount");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['net_amount', 'dpp_amount', 'tax_amount']));
        }
    }
};
