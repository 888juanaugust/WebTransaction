<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Access Groups: a named set of per-screen rights, assigned to users.
        Schema::create('access_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            // 'preferences' follows the Restrictions tab of Preferences; 'time_window' limits the group to a time of day.
            $table->string('restriction_type', 20)->default('preferences');
            $table->time('restricted_from')->nullable();
            $table->time('restricted_until')->nullable();
            $table->text('memo')->nullable();
            $table->timestamps();
        });

        Schema::create('access_group_users', function (Blueprint $table) {
            $table->foreignId('access_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['access_group_id', 'user_id']);
        });

        // One row per screen the group may open; the five rights of the standard.
        Schema::create('access_group_rights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('access_group_id')->constrained()->cascadeOnDelete();
            $table->string('menu_key', 80);
            $table->boolean('can_view')->default(false);
            $table->boolean('can_create')->default(false);
            $table->boolean('can_update')->default(false);
            $table->boolean('can_delete')->default(false);
            $table->boolean('can_print')->default(false);
            $table->unique(['access_group_id', 'menu_key']);
        });

        // Rights that are not tied to one screen: see cost, change selling prices, open a closed period…
        Schema::create('access_group_special_rights', function (Blueprint $table) {
            $table->foreignId('access_group_id')->constrained()->cascadeOnDelete();
            $table->string('right', 60);
            $table->primary(['access_group_id', 'right']);
        });

        // A per-user exception to what the groups grant: explicit allow or deny on one right of one screen.
        Schema::create('user_right_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('menu_key', 80);
            $table->string('right', 20);
            $table->boolean('allowed');
            $table->unique(['user_id', 'menu_key', 'right']);
        });

        Schema::table('users', function (Blueprint $table) {
            // Filament's authenticator-app second factor, shown as "2FA" on the Users screen.
            $table->text('app_authentication_secret')->nullable()->after('remember_token');
            $table->text('app_authentication_recovery_codes')->nullable()->after('app_authentication_secret');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['app_authentication_secret', 'app_authentication_recovery_codes']));
        Schema::dropIfExists('user_right_overrides');
        Schema::dropIfExists('access_group_special_rights');
        Schema::dropIfExists('access_group_rights');
        Schema::dropIfExists('access_group_users');
        Schema::dropIfExists('access_groups');
    }
};
