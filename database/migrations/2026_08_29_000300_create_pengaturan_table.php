<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator-supplied values, entered on a screen instead of over SSH.
 *
 * The bank account on the faktur, the company identity, the tax seller —
 * all of it used to live in `.env`, which meant finishing setup required a
 * terminal. Now the Owner types it into Pengaturan perusahaan; `.env` keys
 * remain as fallbacks for anything never saved here.
 *
 * Deliberately not region-scoped: there is one company, whichever region's
 * books a document lands in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pengaturan', function (Blueprint $table) {
            $table->id();
            $table->string('kunci', 60)->unique();
            $table->text('nilai')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengaturan');
    }
};
