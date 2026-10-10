<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The year-end lock, the month-close reminders, a contact person's birthday and its reminders. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_year_closes', function (Blueprint $table): void {
            $table->id();
            $table->date('fiscal_year_start')->unique();
            $table->date('fiscal_year_end');
            $table->timestamp('closed_at');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('books_reminders', function (Blueprint $table): void {
            $table->id();
            $table->char('period', 7); // YYYY-MM, the month the reminder was about
            $table->jsonb('sent_to')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unique('period');
        });
        Schema::table('customer_contacts', function (Blueprint $table): void {
            $table->date('birth_date')->nullable();
        });
        Schema::create('birthday_notices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_contact_id')->constrained('customer_contacts')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('kind', 8); // before | day
            $table->jsonb('sent_to')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unique(['customer_contact_id', 'year', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('birthday_notices');
        Schema::table('customer_contacts', function (Blueprint $table): void {
            $table->dropColumn('birth_date');
        });
        Schema::dropIfExists('books_reminders');
        Schema::dropIfExists('fiscal_year_closes');
    }
};
