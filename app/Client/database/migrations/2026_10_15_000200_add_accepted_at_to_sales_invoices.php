<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The day an invoice's clock starts: the approval of the order behind it, stamped when the invoice is made. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->date('accepted_at')->nullable()->index()->after('due_date');
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table): void {
            $table->dropColumn('accepted_at');
        });
    }
};
