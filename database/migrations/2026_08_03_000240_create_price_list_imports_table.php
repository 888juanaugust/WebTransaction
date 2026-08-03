<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_list_imports', function (Blueprint $table) {
            $table->id();
            $table->string('original_filename');

            // The raw uploaded file is stored forever, before anything parses it.
            $table->string('stored_path');
            $table->string('checksum', 64)->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users');

            // uploaded | parsing | parsed | failed | published | discarded
            $table->string('status', 20)->default('uploaded');

            // The only way to opt into deactivating SKUs missing from the file.
            $table->boolean('is_full_replacement')->default(false);

            $table->date('effective_from')->nullable();

            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('blocker_count')->default(0);
            $table->unsignedInteger('note_count')->default(0);

            // Five-bucket diff preview, computed before anything is published.
            $table->jsonb('diff')->nullable();
            $table->text('parse_error')->nullable();

            // Set once the human approves and the version is published.
            $table->foreignId('price_list_version_id')->nullable()->constrained('price_list_versions');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();

            // Records the second confirmation demanded by the safety brake.
            $table->text('brake_acknowledgement')->nullable();

            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_list_imports');
    }
};
