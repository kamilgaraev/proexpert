<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('one_c_exchange_mappings', function (Blueprint $table): void {
            $table->string('external_type', 80)->nullable()->after('scope');
            $table->string('local_display_name')->nullable()->after('local_id');
            $table->string('status', 32)->default('active')->after('local_display_name');
            $table->unsignedTinyInteger('confidence_score')->nullable()->after('status');
            $table->string('source', 32)->default('manual')->after('confidence_score');
            $table->boolean('duplicate_warning')->default(false)->after('source');
            $table->jsonb('safe_payload_preview')->nullable()->after('duplicate_warning');
            $table->foreignId('approved_by')->nullable()->after('safe_payload_preview')->constrained('users')->nullOnDelete();
            $table->timestampTz('verified_at')->nullable()->after('approved_by');
            $table->timestampTz('archived_at')->nullable()->after('verified_at');

            $table->index(['organization_id', 'scope', 'status'], 'one_c_mapping_status_index');
            $table->index(['organization_id', 'scope', 'local_type', 'local_id', 'status'], 'one_c_mapping_local_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('one_c_exchange_mappings', function (Blueprint $table): void {
            $table->dropIndex('one_c_mapping_status_index');
            $table->dropIndex('one_c_mapping_local_status_index');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn([
                'external_type',
                'local_display_name',
                'status',
                'confidence_score',
                'source',
                'duplicate_warning',
                'safe_payload_preview',
                'verified_at',
                'archived_at',
            ]);
        });
    }
};
