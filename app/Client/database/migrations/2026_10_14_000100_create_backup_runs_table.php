<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Every backup attempt, verified or failed: a table of successes cannot tell "nothing ran" from "everything failed". */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('status', 20)->default('running');
            $table->string('disk', 40);
            $table->string('database_path', 255)->nullable();
            $table->string('files_path', 255)->nullable();
            $table->boolean('offsite')->default(false);
            $table->unsignedBigInteger('database_bytes')->default(0);
            $table->unsignedBigInteger('files_bytes')->default(0);
            $table->unsignedBigInteger('verified_bytes')->default(0);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->text('note')->nullable();
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
