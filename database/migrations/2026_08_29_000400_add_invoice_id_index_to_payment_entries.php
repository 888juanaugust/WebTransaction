<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Found by measurement, not by reading: at one seeded year of trading
 * (4,200 invoices, 3,400 payments) the receivables ageing took 1.1s,
 * because its per-invoice "paid so far" subselect could only use the
 * existing (company_id, invoice_id) composite — which cannot seek on
 * invoice_id alone — so Postgres scanned the whole payment ledger once
 * per invoice, 4,200 times. Every consumer of "what has come off this
 * invoice" (settlement, ageing, the statement, the portal) asks by
 * invoice_id; the index the question deserves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_entries', function (Blueprint $table) {
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::table('payment_entries', function (Blueprint $table) {
            $table->dropIndex(['invoice_id']);
        });
    }
};
