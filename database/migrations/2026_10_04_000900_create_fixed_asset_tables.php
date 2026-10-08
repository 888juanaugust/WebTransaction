<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Asset categories carry the default accounts, method and life (A-02).
        Schema::create('asset_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->foreignId('asset_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('accumulated_depreciation_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('depreciation_expense_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('depreciation_method', 20)->default('straight_line');
            $table->unsignedSmallInteger('useful_life_months')->default(48);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Fiscal asset groups (golongan harta) for tax depreciation.
        Schema::create('fiscal_asset_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('depreciation_method', 20)->default('straight_line');
            $table->unsignedSmallInteger('useful_life_years')->default(4);
            $table->decimal('rate_percent', 6, 2)->default(25);
            $table->timestamps();
        });

        // Where assets sit: an address of the company, per branch.
        Schema::create('asset_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('address', 255)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('series_id')->nullable()->constrained('document_series')->nullOnDelete();
            $table->string('name', 255);
            $table->date('trans_date'); // purchase date
            $table->date('usage_date');
            $table->boolean('intangible')->default(false);
            $table->string('depreciation_method', 20)->default('straight_line');
            $table->foreignId('asset_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('accumulated_depreciation_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('depreciation_expense_account_id')->constrained('accounts')->restrictOnDelete();
            $table->decimal('quantity', 18, 4)->default(1);
            $table->unsignedSmallInteger('useful_life_months')->default(48);
            $table->bigInteger('salvage_value')->default(0);
            $table->foreignId('asset_category_id')->constrained('asset_categories')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('asset_locations')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->boolean('fiscal')->default(false);
            $table->foreignId('fiscal_asset_category_id')->nullable()->constrained('fiscal_asset_categories')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->bigInteger('cost')->default(0); // cached: Σ expenditures + Σ change expenditures
            $table->string('status', 12)->default('active'); // active | disposed
            $table->date('disposed_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('trans_date');
            $table->index('status');
        });

        // What was paid for the asset, from which accounts (the "expenditure accounts" tab).
        Schema::create('fixed_asset_expenditures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->date('trans_date')->nullable();
            $table->bigInteger('amount')->default(0);
        });

        // One month's depreciation of one asset; posted, never edited, idempotent per (asset, period).
        Schema::create('asset_depreciations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fixed_asset_id')->constrained()->cascadeOnDelete();
            $table->char('period', 6); // YYYYMM
            $table->date('trans_date'); // the month's last day
            $table->bigInteger('amount')->default(0);
            $table->bigInteger('accumulated_after')->default(0);
            $table->bigInteger('book_value_after')->default(0);
            $table->string('method', 20);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['fixed_asset_id', 'period']);
            $table->index('trans_date');
        });

        // Changes: data corrections, revaluations and added costs (A-05).
        Schema::create('asset_changes', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('series_id')->nullable()->constrained('document_series')->nullOnDelete();
            $table->foreignId('fixed_asset_id')->constrained()->restrictOnDelete();
            $table->string('change_type', 12)->default('data'); // data | revaluation
            $table->date('trans_date');
            $table->string('new_depreciation_method', 20)->nullable();
            $table->bigInteger('new_salvage_value')->nullable();
            $table->unsignedSmallInteger('new_useful_life_months')->nullable();
            $table->boolean('new_intangible')->nullable();
            $table->boolean('new_fiscal')->nullable();
            $table->foreignId('asset_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->bigInteger('amount')->default(0); // cached Σ expenditures
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('trans_date');
        });

        Schema::create('asset_change_expenditures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_change_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->bigInteger('amount')->default(0);
        });

        // Disposals: the asset (or part of it) leaves the books with a gain or loss (A-04).
        Schema::create('asset_disposals', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('series_id')->nullable()->constrained('document_series')->nullOnDelete();
            $table->foreignId('fixed_asset_id')->constrained()->restrictOnDelete();
            $table->date('trans_date');
            $table->decimal('quantity', 18, 4)->default(1);
            $table->foreignId('gain_loss_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('asset_locations')->nullOnDelete();
            $table->boolean('selling_asset')->default(false);
            $table->bigInteger('proceeds')->default(0);
            $table->foreignId('proceeds_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->bigInteger('cost_removed')->default(0);
            $table->bigInteger('depreciation_removed')->default(0);
            $table->bigInteger('gain_loss')->default(0); // positive gain, negative loss
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('trans_date');
        });

        // Transfers between locations (no journal).
        Schema::create('asset_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('series_id')->nullable()->constrained('document_series')->nullOnDelete();
            $table->date('trans_date');
            $table->foreignId('from_location_id')->constrained('asset_locations')->restrictOnDelete();
            $table->foreignId('to_location_id')->constrained('asset_locations')->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('trans_date');
        });

        Schema::create('asset_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_transfer_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('fixed_asset_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 18, 4)->default(1);
            $table->string('memo', 255)->nullable();
        });
    }

    public function down(): void
    {
        foreach (['asset_transfer_lines', 'asset_transfers', 'asset_disposals', 'asset_change_expenditures', 'asset_changes', 'asset_depreciations', 'fixed_asset_expenditures', 'fixed_assets', 'asset_locations', 'fiscal_asset_categories', 'asset_categories'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
