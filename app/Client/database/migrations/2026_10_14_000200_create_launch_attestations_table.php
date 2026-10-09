<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The readiness items a person attests: one row per item, with who, when and the note. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('launch_attestations', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 60)->unique();
            $table->foreignId('attested_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('attested_at');
            $table->string('note', 300);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('launch_attestations');
    }
};
