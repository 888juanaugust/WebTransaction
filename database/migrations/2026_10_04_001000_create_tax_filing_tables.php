<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One export file handed to the tax office's application: which documents, which format, the totals.
        Schema::create('tax_filings', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10); // out (PPN keluaran) | in (PPN masukan)
            $table->string('format', 10); // coretax | legacy
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->date('from_date');
            $table->date('to_date');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('file_name', 120);
            $table->string('file_path', 255);
            $table->unsignedInteger('document_count')->default(0);
            $table->bigInteger('dpp_total')->default(0);
            $table->bigInteger('tax_total')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('tax_filing_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_filing_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 40);
            $table->unsignedBigInteger('document_id');
            $table->bigInteger('dpp')->default(0);
            $table->bigInteger('tax')->default(0);
            $table->index(['document_type', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_filing_documents');
        Schema::dropIfExists('tax_filings');
    }
};
