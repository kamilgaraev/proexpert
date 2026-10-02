<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_assistant_document_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('background_ocr_enabled')->default(false);
            $table->string('scope', 20)->default('new');
            $table->unsignedBigInteger('limit_minor')->default(0);
            $table->unsignedBigInteger('reserved_minor')->default(0);
            $table->unsignedBigInteger('spent_minor')->default(0);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->unsignedBigInteger('last_file_id')->default(0);
            $table->unsignedBigInteger('scanned_count')->default(0);
            $table->timestampTz('scan_completed_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_assistant_document_settings');
    }
};
