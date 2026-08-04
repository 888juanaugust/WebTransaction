<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Gapless, per-period document numbers for orders and invoices.
         *
         * Not a Postgres sequence: sequences deliberately leak numbers on
         * rollback, and an invoice register with holes in it is a question you
         * have to answer to an auditor. A counter row taken with
         * SELECT ... FOR UPDATE inside the issuing transaction means a
         * rolled-back invoice releases its number too.
         *
         * The trade is throughput — issuing serialises on this row — which is
         * the right trade at this volume.
         */
        Schema::create('document_counters', function (Blueprint $table) {
            $table->id();

            // order | invoice
            $table->string('scope', 30);

            // Numbering restarts each period, e.g. '202608'.
            $table->string('period', 12);

            $table->unsignedBigInteger('next_value')->default(1);

            $table->timestamps();

            $table->unique(['scope', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_counters');
    }
};
