<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A record of every backup attempt, including the ones that failed.
 *
 * The commonest way a backup system fails is silently. Cron stops firing, the
 * credentials expire, the disk fills, somebody rotates the key — and nothing
 * anywhere says so, because nothing was watching. Six months later the machine
 * dies and the last good copy is from before the business had customers.
 *
 * So every run writes a row whether it worked or not, and the dashboard reads
 * the newest one. **Failures are rows too**: a table that only records
 * successes cannot tell "we have not run since Tuesday" from "we have failed
 * every night since Tuesday", and those need different phone calls.
 *
 * This table is itself in the backup, which is circular and harmless — its
 * value is on the running system, not in the restored copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            // running | verified | failed. There is deliberately no plain
            // "succeeded": an artefact that has not been read back is a
            // hypothesis, so the only success state is the verified one.
            $table->string('status', 20)->default('running');

            $table->string('disk', 40);
            $table->string('database_path')->nullable();
            $table->string('files_path')->nullable();

            /*
             * Whether this copy actually left the machine. A run to a local
             * disk is recorded false and the health check treats it as
             * unprotected — the machine is the thing that fails, so a copy
             * sitting beside the original protects against a dropped table and
             * nothing else.
             */
            $table->boolean('offsite')->default(false);

            $table->bigInteger('database_bytes')->default(0);
            $table->bigInteger('files_bytes')->default(0);

            // Plaintext bytes recovered when the artefact was read back. Zero
            // on anything that never reached verification.
            $table->bigInteger('verified_bytes')->default(0);

            $table->unsignedInteger('duration_seconds')->nullable();

            $table->text('catatan')->nullable();
            $table->text('error')->nullable();

            $table->timestamps();

            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
