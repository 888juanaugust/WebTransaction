<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The standard's Users screen: Operator or Administrator, a
            // phone, and an active flag. An administrator passes every access
            // check; an operator is limited by their access groups (phase 1).
            $table->string('access_type', 20)->default('operator')->after('password');
            $table->string('phone', 30)->nullable()->after('access_type');
            $table->boolean('is_active')->default(true)->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['access_type', 'phone', 'is_active']);
        });
    }
};
