<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A returned line is good (back to saleable stock) or damaged (to the branch's damaged-goods warehouse). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('return_claim_lines', function (Blueprint $table): void {
            $table->string('condition', 12)->default('good');
        });
    }

    public function down(): void
    {
        Schema::table('return_claim_lines', function (Blueprint $table): void {
            $table->dropColumn('condition');
        });
    }
};
