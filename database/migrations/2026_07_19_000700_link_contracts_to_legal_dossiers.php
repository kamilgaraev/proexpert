<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->foreignId('legal_archive_document_id')
                ->nullable()
                ->unique()
                ->constrained('legal_archive_documents')
                ->nullOnDelete();
            $table->string('dossier_creation_key', 191)->nullable();
            $table->unique(['organization_id', 'dossier_creation_key'], 'contracts_dossier_creation_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->dropUnique('contracts_dossier_creation_key_unique');
            $table->dropForeign(['legal_archive_document_id']);
            $table->dropUnique(['legal_archive_document_id']);
            $table->dropColumn(['legal_archive_document_id', 'dossier_creation_key']);
        });
    }
};
