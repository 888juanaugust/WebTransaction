<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What the surat jalan and the surat pengantar print about the shipment: the vehicle, the packages, a note. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->string('shipping_note', 255)->nullable();
            $table->string('vehicle', 60)->nullable();
            $table->string('plate_number', 20)->nullable();
            $table->jsonb('packages')->nullable(); // {koli, kresek, ikat, palet}
            $table->string('goods_description', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table): void {
            $table->dropColumn(['shipping_note', 'vehicle', 'plate_number', 'packages', 'goods_description']);
        });
    }
};
