<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every document, balance and master row learns which region it belongs to.
 *
 * Added nullable, backfilled to the one region the previous migration created,
 * then made NOT NULL. Doing it in that order is what lets this run against a
 * database with live data in it: a NOT NULL column added in one step would need
 * a default, and a default region_id is exactly the thing that silently files a
 * Surabaya invoice in the Jakarta books six months from now.
 *
 * WHAT IS NOT HERE, AND WHY
 * -------------------------
 * **Line tables.** `order_lines`, `journal_lines`, `credit_note_lines` and the
 * rest are reached only through their head, so the head's region is theirs. A
 * region on both is two columns that can disagree, and the day they do, one of
 * them is wrong and no query tells you which.
 *
 * **The catalogue.** `products`, `price_list_*`, `price_tiers` are group-wide.
 * A part is the same part in every region, and one shared price list is what
 * keeps the project's rule that pricing resolves in exactly one function.
 *
 * **`accounts`.** One chart of accounts, shared. Balances are per region
 * because `journal_entries` carries the region — so 1-1100 Piutang Usaha means
 * the same thing everywhere and still reports separately. Duplicating the chart
 * per region would mean every new account had to be created several times, and
 * `AccountCode`'s constants would stop being able to name one row.
 *
 * **`users`.** Gets a region, but nullable, and nullable means something: an
 * account with no region sees every region. That is the Owner.
 *
 * **`audit_logs`.** Gets a region for filtering, nullable because the actions
 * that belong to no region — a staff account being created, a backup — still
 * have to be recorded.
 */
return new class extends Migration
{
    /**
     * Document heads, balances and masters.
     *
     * @var list<string>
     */
    private const SCOPED = [
        // Masters. A warehouse, a customer and a supplier belong to one region.
        'warehouses',
        'companies',
        'suppliers',

        // The books.
        'journal_entries',
        'accounting_periods',
        'document_counters',

        // Stock, in all its forms.
        'stock_movements',
        'stock_levels',
        'stock_reservations',
        'stock_transfers',
        'stock_opnames',
        'product_costs',

        // Selling.
        'orders',
        'invoices',
        'payment_entries',
        'credit_notes',
        'carts',
        'customer_deposits',
        'giros',
        'virtual_accounts',
        'faktur_exports',

        // Buying.
        'purchase_orders',
        'goods_receipts',
        'supplier_bills',
        'purchase_returns',
        'supplier_credit_notes',
        'supplier_payment_entries',
        'landed_costs',

        // Money and things owned.
        'expenses',
        'fixed_assets',
        'bank_reconciliations',
    ];

    /**
     * Unique keys that were group-wide and have to become per-region.
     *
     * A warehouse coded GD-01 in Jakarta and another coded GD-01 in Surabaya
     * are two different warehouses, and refusing the second is refusing the
     * region. The counters are the load-bearing one: without the region in the
     * key, two regions share a sequence and neither register reads in order.
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    private const REKEY = [
        'warehouses' => ['warehouses_kode_unique', ['kode']],
        'suppliers' => ['suppliers_kode_unique', ['kode']],
        'companies' => ['companies_kode_unique', ['kode']],
        'document_counters' => ['document_counters_scope_period_unique', ['scope', 'period']],
        'accounting_periods' => ['accounting_periods_tahun_bulan_unique', ['tahun', 'bulan']],
        'stock_levels' => ['stock_levels_sku_warehouse_id_unique', ['sku', 'warehouse_id']],
        'product_costs' => ['product_costs_sku_unique', ['sku']],
        'carts' => ['carts_customer_user_id_unique', ['customer_user_id']],
    ];

    public function up(): void
    {
        $utama = (int) DB::table('regions')->orderBy('id')->value('id');

        if ($utama === 0) {
            throw new RuntimeException('No region exists to file the existing data under.');
        }

        foreach (self::SCOPED as $tabel) {
            Schema::table($tabel, function (Blueprint $table) {
                $table->foreignId('region_id')->nullable()->after('id');
            });

            DB::table($tabel)->update(['region_id' => $utama]);

            Schema::table($tabel, function (Blueprint $table) use ($tabel) {
                $table->unsignedBigInteger('region_id')->nullable(false)->change();
                $table->foreign('region_id')->references('id')->on('regions');
                $table->index('region_id', "{$tabel}_region_id_index");
            });
        }

        foreach (self::REKEY as $tabel => [$lama, $kolom]) {
            Schema::table($tabel, function (Blueprint $table) use ($lama, $kolom) {
                $table->dropUnique($lama);
                $table->unique(['region_id', ...$kolom]);
            });
        }

        /*
         * Null here is not "unknown", it is "every region" — the Owner. Which
         * is why this one stays nullable while the thirty-two above do not.
         */
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('region_id')->nullable()->after('role')->constrained('regions');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('region_id')->nullable()->after('actor_role')->constrained('regions');
            $table->index('region_id');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('region_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('region_id');
        });

        foreach (array_reverse(self::REKEY, preserve_keys: true) as $tabel => [$lama, $kolom]) {
            Schema::table($tabel, function (Blueprint $table) use ($kolom) {
                $table->dropUnique(['region_id', ...$kolom]);
            });
        }

        foreach (array_reverse(self::SCOPED) as $tabel) {
            Schema::table($tabel, function (Blueprint $table) use ($tabel) {
                $table->dropIndex("{$tabel}_region_id_index");
                $table->dropConstrainedForeignId('region_id');
            });
        }

        foreach (self::REKEY as $tabel => [$lama, $kolom]) {
            Schema::table($tabel, function (Blueprint $table) use ($lama, $kolom) {
                $table->unique($kolom, $lama);
            });
        }
    }
};
