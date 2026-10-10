<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Central's roles claim a group by a stable key, so the owner may rename the group freely. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_groups', function (Blueprint $table): void {
            $table->string('role_key', 30)->nullable()->unique()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('access_groups', function (Blueprint $table): void {
            $table->dropColumn('role_key');
        });
    }
};
