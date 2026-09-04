<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chasing the money — the step between "this invoice is late" and "write it
 * off".
 *
 * The system could already tell you a debt was 90 days old and could remove it
 * at the end; it had nothing for the eleven weeks in between, which is where
 * the money is actually recovered or lost. Somebody rings the shop, the shop
 * says Friday, and until now that lived in a notebook.
 *
 * **This table records what was said, never what was paid.** A contact is an
 * event about a conversation: who rang, how, what came of it, and the janji
 * bayar if one was made. Money still moves only through `payment_entries` —
 * the ledger invariant is not relaxed for collections, and a promise
 * deliberately has no effect on any balance, credit check or age. It is a
 * note about the future, and treating it as anything more is how a register
 * fills up with debts everyone believes are handled.
 *
 * Whether a promise was **kept** is not stored either. It is derived the same
 * way debt aging is: compare the promised amount and date against what the
 * payment ledger actually received. A stored "kept" flag would be a second
 * opinion about settlement, and the day it disagreed with the ledger somebody
 * would act on the wrong one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_contacts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();

            /*
             * Denormalised from the invoice on purpose. Every read of this
             * table is "what is happening with this customer" or "what does
             * this seat have to chase", and both would otherwise join
             * invoices for a column that cannot change: an invoice never
             * moves to another customer.
             */
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();

            // Who made the contact. Restricted rather than cascading: a
            // leaver's collection history is evidence of what was chased.
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            $table->string('cara', 20);      // telepon, whatsapp, kunjungan, email
            $table->string('hasil', 30);     // janji_bayar, minta_tempo, tidak_terhubung, sengketa, sudah_bayar

            /*
             * The promise, when there is one. Nullable because most contacts
             * are not promises — "could not reach them" is the commonest
             * outcome of a collections round and is worth recording precisely
             * because it explains why nothing moved.
             */
            $table->date('janji_tanggal')->nullable();
            $table->bigInteger('janji_rupiah')->nullable();

            $table->text('catatan')->nullable();

            // When the conversation happened, which is not always when it was
            // typed in — a sales rings from the road and records it that
            // evening.
            $table->timestamp('dihubungi_pada');

            /*
             * Region-scoped like the invoice it chases. Not merely inherited
             * through the join: a Sales pinned to one region must not see
             * another region's collection notes, and the scope has to be on
             * the row for the global scope to do that structurally.
             */
            $table->foreignId('region_id')->constrained('regions')->restrictOnDelete();

            $table->timestamps();

            // The worklist's two questions: what is outstanding for this
            // invoice, and which promises come due.
            $table->index(['invoice_id', 'dihubungi_pada']);
            $table->index(['janji_tanggal']);
            $table->index(['company_id', 'dihubungi_pada']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_contacts');
    }
};
