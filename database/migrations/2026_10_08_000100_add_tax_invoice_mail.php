<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tax invoices are emailed: a customer may name the address its tax
 * invoices go to, an invoice keeps the Coretax PDF uploaded for it, and
 * every send (queued, sent, failed, skipped) is a row of its own in an
 * append-only log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', fn (Blueprint $t) => $t->string('tax_invoice_email', 150)->nullable());
        Schema::table('sales_invoices', fn (Blueprint $t) => $t->string('coretax_pdf_path', 255)->nullable());
        Schema::create('tax_invoice_mails', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sales_invoice_id')->constrained()->restrictOnDelete();
            $t->string('serial', 40);
            $t->string('recipient', 150);
            $t->json('attachments');
            $t->string('status', 10); // queued | sent | failed | skipped
            $t->text('error')->nullable();
            $t->boolean('resend')->default(false);
            $t->foreignId('request_id')->nullable()->constrained('tax_invoice_mails')->restrictOnDelete(); // the queued row an outcome answers
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->nullable();
            $t->index(['sales_invoice_id', 'serial', 'status']);
        });
        DB::unprepared('CREATE TRIGGER tax_invoice_mails_append_only BEFORE UPDATE OR DELETE ON tax_invoice_mails FOR EACH ROW EXECUTE FUNCTION ledger_append_only();');
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_invoice_mails');
        Schema::table('sales_invoices', fn (Blueprint $t) => $t->dropColumn('coretax_pdf_path'));
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('tax_invoice_email'));
    }
};
