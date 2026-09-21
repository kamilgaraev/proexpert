<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('handover_packages', function (Blueprint $table): void {
            $table->foreignId('executive_document_set_id')->nullable()->constrained('executive_document_sets')->restrictOnDelete();
        });
        Schema::table('handover_package_documents', function (Blueprint $table): void {
            $table->foreignId('executive_document_version_id')->nullable()->constrained('executive_document_versions')->restrictOnDelete();
            $table->string('evidence_hash', 64)->nullable();
        });
        Schema::table('acceptance_signoffs', function (Blueprint $table): void {
            $table->jsonb('evidence_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('acceptance_signoffs', fn (Blueprint $table) => $table->dropColumn('evidence_snapshot'));
        Schema::table('handover_package_documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('executive_document_version_id');
            $table->dropColumn('evidence_hash');
        });
        Schema::table('handover_packages', fn (Blueprint $table) => $table->dropConstrainedForeignId('executive_document_set_id'));
    }
};
