<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The images the public home page shows: promo slides and photos, on the public disk, with an optional date range. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_images', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 10);
            $table->jsonb('title')->nullable();
            $table->jsonb('text')->nullable();
            $table->string('image_path', 255);
            $table->string('link', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('show_from')->nullable();
            $table->date('show_until')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['kind', 'is_active', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_images');
    }
};
