<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Buyer logins live in their own table, separate from `users`.
         *
         * Sharing one table with staff and distinguishing by a role column is
         * how a buyer ends up one bad query away from the admin panel. Two
         * tables and two guards means a buyer session simply has no identity
         * on the staff guard — the isolation is structural, not conditional.
         */
        Schema::create('customer_users', function (Blueprint $table) {
            $table->id();

            // Every buyer login belongs to exactly one buyer company. This is
            // what scopes everything they can see.
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('telepon', 30)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();

            // Who at our end created this login, for the audit trail.
            $table->foreignId('created_by')->nullable()->constrained('users');

            $table->rememberToken();
            $table->timestamps();

            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_users');
    }
};
