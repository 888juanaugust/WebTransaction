<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The Numbering screen: a named format for one transaction type, made of
        // ordered tokens (year, month, counter, text…), its counter reset never,
        // daily, monthly or yearly.
        Schema::create('document_series', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('transaction_type', 40);
            $table->string('reset_rule', 10)->default('monthly');
            $table->unsignedTinyInteger('counter_digits')->default(4);
            $table->jsonb('pattern');
            $table->boolean('used_all_user')->default(true);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['transaction_type', 'name']);
        });

        Schema::create('document_series_users', function (Blueprint $table) {
            $table->foreignId('document_series_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['document_series_id', 'user_id']);
        });

        // One counter per series per reset period ('' when it never resets).
        // Advanced by a single INSERT … ON CONFLICT … RETURNING, so two users
        // saving at once never draw the same number.
        Schema::create('document_counters', function (Blueprint $table) {
            $table->foreignId('document_series_id')->constrained()->cascadeOnDelete();
            $table->string('period_key', 8)->default('');
            $table->unsignedBigInteger('last_value')->default(0);
            $table->primary(['document_series_id', 'period_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_counters');
        Schema::dropIfExists('document_series_users');
        Schema::dropIfExists('document_series');
    }
};
