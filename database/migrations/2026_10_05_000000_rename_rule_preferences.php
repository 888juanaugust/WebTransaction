<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The business rules became generic: the approval rule is renamed, the three rules this release never read are dropped. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('preferences')->where('key', 'rules.marketing_approval_required')->update(['key' => 'rules.sales_order_approval']);
        DB::table('preferences')->whereIn('key', ['rules.split_across_warehouses', 'rules.store_visits', 'rules.commission_scheme'])->delete();
    }

    public function down(): void
    {
        DB::table('preferences')->where('key', 'rules.sales_order_approval')->update(['key' => 'rules.marketing_approval_required']);
    }
};
