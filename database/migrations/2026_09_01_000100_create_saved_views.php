<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A filter somebody set up once and wants back tomorrow.
 *
 * "Faktur jatuh tempo lebih dari 60 hari, urut dari yang terbesar" is a
 * question asked every week, and rebuilding it from six controls every time is
 * how people stop asking it. So the arrangement gets a name.
 *
 * What is stored is the *question*, never its answer: which dataset, which
 * filters, which sort, which columns. No rows, no figures, no totals. A saved
 * view opened next month reads next month's data — a cached answer would go
 * quietly stale and be believed anyway.
 *
 * Deliberately not region-scoped. A view is a saved arrangement of controls,
 * and the data it opens is scoped when it runs, by the same rules as every
 * other screen. Stamping a region on the question would mean an Owner who
 * switches region loses their own saved views.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_views', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('nama', 80);

            // Which explorer dataset this belongs to. A string rather than an
            // enum column: datasets are added in code, and a migration per
            // new one would be a schema change for a menu entry.
            $table->string('dataset', 40);

            $table->json('filters')->nullable();

            // "column:direction", as the table itself holds it.
            $table->string('urutan', 80)->nullable();

            $table->string('pencarian', 120)->nullable();

            /*
             * Shared views are visible to everyone who can open that dataset
             * — the point being that the person who works out the right
             * filter should not have to explain it six times. Only the owner
             * may delete their own, shared or not.
             */
            $table->boolean('dibagikan')->default(false);

            $table->timestamps();

            // One name per person per dataset: saving twice under the same
            // name updates rather than quietly making a second identical entry.
            $table->unique(['user_id', 'dataset', 'nama']);
            $table->index(['dataset', 'dibagikan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_views');
    }
};
