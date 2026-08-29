<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two org-side additions: the packer's seat, and what the sellers are paid.
 *
 * `users.warehouse_id` binds a Gudang (storage) account to the one warehouse
 * it works. The application enforces the rest — the role requires it, other
 * roles ignore it, and one warehouse holds at most one active packer account,
 * because "who packed this" should have exactly one answer.
 *
 * Commission is deliberately NOT a stored ledger. Following the same rule as
 * debt aging — derived arithmetic, never stored state — komisi is computed
 * from settled invoices at report time. What must be stored is only what the
 * arithmetic cannot re-derive: the rate a person was on at a given date, and
 * the target they were given for a given month. Both tables are append-only
 * by convention: a rate change is a new row with a later `berlaku_mulai`,
 * never an UPDATE, so last year's commission report prints the same forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->nullable()
                ->constrained('warehouses')->restrictOnDelete();
        });

        Schema::create('commission_rates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            // Basis points (1/100 of a percent): 150 = 1,50%. An integer,
            // because DECIMAL rates invite float arithmetic on money.
            $table->unsignedInteger('basis_poin');

            $table->date('berlaku_mulai');

            $table->foreignId('set_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            // One rate per person per effective date; the latest date at or
            // before the settlement date wins.
            $table->unique(['user_id', 'berlaku_mulai']);
        });

        Schema::create('sales_targets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('tahun');
            $table->unsignedTinyInteger('bulan');
            $table->bigInteger('target_rupiah');

            $table->foreignId('set_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'tahun', 'bulan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_targets');
        Schema::dropIfExists('commission_rates');
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('warehouse_id'));
    }
};
