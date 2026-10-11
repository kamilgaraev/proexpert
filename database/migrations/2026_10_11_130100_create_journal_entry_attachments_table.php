<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entry_attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('journal_entry_id')->constrained('construction_journal_entries')->restrictOnDelete();
            $table->string('storage_key', 1024)->unique();
            $table->string('file_name');
            $table->string('mime_type', 64);
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->jsonb('uploaded_by');
            $table->timestampTz('created_at');
            $table->index(['journal_entry_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_attachments');
    }
};
