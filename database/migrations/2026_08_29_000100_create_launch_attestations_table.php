<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somebody putting their name against a thing the system cannot see.
 *
 * Most of the launch checklist checks itself: whether the tax NPWP is set,
 * whether a price list has been published, whether any staff account still has
 * the seeded password. Those are facts in this database and there is no reason
 * to ask a person about them.
 *
 * A handful are not. Whether the PSE registration went through, whether a
 * lawyer actually read the terms of sale, whether the backup restore was
 * *practised* rather than merely written — nothing in here can tell. Those need
 * a person to say so.
 *
 * A statement is weaker evidence than a check, so it is stored like evidence
 * rather than like a preference: who said it and when, alongside whatever
 * reference they can give — the PB-UMKU number, the lawyer's name, the date
 * the restore was rehearsed. A bare boolean would let somebody tick six boxes
 * in four seconds and leave nothing to ask about afterwards.
 *
 * One row per item. Retracting deletes the row, and the audit log keeps the
 * fact that it was once claimed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('launch_attestations', function (Blueprint $table) {
            $table->id();

            // The check's key, from LaunchReadiness. Unique: a checklist item
            // is either attested or it is not.
            $table->string('kunci', 60)->unique();

            $table->foreignId('attested_by')->constrained('users');
            $table->timestamp('attested_at');

            // The evidence. Required by the screen rather than by the schema,
            // so a correction can clear it without a migration.
            $table->string('catatan', 300)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('launch_attestations');
    }
};
