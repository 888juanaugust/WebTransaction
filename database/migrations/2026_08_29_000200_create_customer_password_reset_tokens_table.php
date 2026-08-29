<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reset tokens for buyers, in their own table.
 *
 * Staff and buyers are separate guards against separate tables, and the
 * reset tokens keep that separation: a buyer whose email happens to match a
 * staff account must never be able to burn — or worse, use — the staff
 * side's token. Same shape as Laravel's own password_reset_tokens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_password_reset_tokens');
    }
};
