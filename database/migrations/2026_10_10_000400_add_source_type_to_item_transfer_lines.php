<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A received transfer's lines name what they received the way every pulled line does (type and id), so the
 * send's processed quantities are worked out from them: deleting or changing the receipt gives quantity back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('item_transfer_lines', function (Blueprint $table) {
            $table->string('source_line_type', 60)->nullable()->after('processed_quantity');
        });
        DB::table('item_transfer_lines')->whereNotNull('source_line_id')->update(['source_line_type' => 'item_transfer_line']);
    }

    public function down(): void
    {
        Schema::table('item_transfer_lines', function (Blueprint $table) {
            $table->dropColumn('source_line_type');
        });
    }
};
