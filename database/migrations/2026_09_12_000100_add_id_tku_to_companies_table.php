<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The buyer's ID TKU, for the Coretax XML.
 *
 * Coretax identifies the buyer's *place of business*, not only the buyer:
 * the NITKU / ID TKU is the 16-digit NPWP plus a six-digit branch suffix,
 * `000000` for the head office. Most customers are their own head office and
 * the writer derives that from the NPWP; a customer buying through a
 * registered branch has a different suffix that only they can tell us, and
 * this is where it goes. Nullable, because "not told" and "head office" are
 * the same thing to the writer and different things to the person reading
 * the form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('id_tku', 22)->nullable()->after('npwp');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('id_tku');
        });
    }
};
