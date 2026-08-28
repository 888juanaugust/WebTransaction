<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Biaya ekspedisi — a sales' own spending on the road, claimed and verified.
 *
 * The sales types what they spent and on what; finance checks it by hand and
 * their approval posts an ordinary expense document into the books. The row
 * here is the claim and its verdict; the expense the approval creates is the
 * accounting fact, and the pointer between them is how an auditor walks from
 * "sales said" to "the books show".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_expense_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();
            $table->foreignId('sales_user_id')->constrained('users')->restrictOnDelete();
            $table->date('tanggal');
            $table->bigInteger('amount_rupiah');

            // What the money was for — the thing finance verifies, and the
            // thing nobody remembers three weeks later unless it is written.
            $table->text('keterangan');

            $table->string('status')->default('diajukan');
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('keputusan_catatan')->nullable();
            $table->foreignId('expense_id')->nullable()->constrained('expenses')->restrictOnDelete();

            $table->timestamps();

            $table->index(['sales_user_id', 'status']);
            $table->index(['region_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_expense_claims');
    }
};
