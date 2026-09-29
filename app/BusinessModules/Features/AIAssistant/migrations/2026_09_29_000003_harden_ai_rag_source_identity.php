<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_rag_sources', function (Blueprint $table): void {
            $table->unsignedBigInteger('identity_project_id')->default(0)->after('project_id');
            $table->string('identity_part_key', 64)->default('');
            $table->string('source_version', 64)->nullable()->after('checksum');
            $table->timestampTz('last_reconciled_at')->nullable()->after('indexed_at');
            $table->dropUnique('ai_rag_sources_unique_entity');
            $table->unique(
                ['organization_id', 'identity_project_id', 'source_type', 'entity_type', 'entity_id', 'identity_part_key'],
                'ai_rag_sources_unique_scope_entity'
            );
            $table->index(['organization_id', 'identity_project_id', 'source_type'], 'ai_rag_sources_identity_scope_idx');
        });
        DB::table('ai_rag_sources')->whereNotNull('project_id')->update(['identity_project_id' => DB::raw('project_id')]);
    }

    public function down(): void
    {
        Schema::table('ai_rag_sources', function (Blueprint $table): void {
            $table->dropIndex('ai_rag_sources_identity_scope_idx');
            $table->dropUnique('ai_rag_sources_unique_scope_entity');
            $table->unique(
                ['organization_id', 'source_type', 'entity_type', 'entity_id'],
                'ai_rag_sources_unique_entity'
            );
            $table->dropColumn(['identity_project_id', 'identity_part_key', 'source_version', 'last_reconciled_at']);
        });
    }
};
