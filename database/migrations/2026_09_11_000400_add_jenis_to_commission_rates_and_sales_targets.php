<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commission on more than a seat's own customers.
 *
 * `commission_rates` and `sales_targets` were one rate and one target per
 * *person*, always meaning the seat kind: a sales or marketing seat paid on
 * the customers it holds. The owner asked for three more kinds — a
 * supervisor paid on a whole cabang's collected sales, a manager paid on
 * every cabang's, and the import purchaser paid on settled import bills —
 * and a person can hold more than one at once (a sales seat who is also the
 * cabang's supervisor). So the kind joins the key, and a supervisor's rate
 * names its cabang.
 *
 * The cabang column is `cabang_id`, not `region_id`, on purpose: a
 * `region_id` column means "this row belongs to that region's books" and
 * pulls the region scope with it, whereas a rate belongs to the Owner and
 * merely *points at* the cabang it is paid on.
 *
 * Existing rows are the seat kind, which the default makes them without a
 * backfill. The unique keys widen rather than change: the same person, the
 * same kind, the same start date is still the one thing that cannot exist
 * twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_rates', function (Blueprint $table) {
            $table->string('jenis', 20)->default('penjualan')->after('user_id');
            $table->foreignId('cabang_id')->nullable()->after('jenis')
                ->constrained('regions')->restrictOnDelete();

            $table->dropUnique(['user_id', 'berlaku_mulai']);
            $table->unique(['user_id', 'jenis', 'berlaku_mulai']);
        });

        Schema::table('sales_targets', function (Blueprint $table) {
            $table->string('jenis', 20)->default('penjualan')->after('user_id');

            $table->dropUnique(['user_id', 'tahun', 'bulan']);
            $table->unique(['user_id', 'jenis', 'tahun', 'bulan']);
        });
    }

    public function down(): void
    {
        Schema::table('sales_targets', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'jenis', 'tahun', 'bulan']);
            $table->unique(['user_id', 'tahun', 'bulan']);
            $table->dropColumn('jenis');
        });

        Schema::table('commission_rates', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'jenis', 'berlaku_mulai']);
            $table->unique(['user_id', 'berlaku_mulai']);
            $table->dropConstrainedForeignId('cabang_id');
            $table->dropColumn('jenis');
        });
    }
};
