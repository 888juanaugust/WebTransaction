<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_list_versions', function (Blueprint $table) {
            $table->id();
            $table->date('effective_from');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users');

            // The raw uploaded file is kept forever.
            $table->string('source_file_path')->nullable();
            $table->text('note')->nullable();

            // draft | published | superseded
            $table->string('status', 20)->default('draft');

            $table->timestamps();

            $table->index(['status', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_list_versions');
    }
};
