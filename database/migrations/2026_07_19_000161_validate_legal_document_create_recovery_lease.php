<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        do {
            $rows = DB::table('legal_archive_documents')
                ->whereIn('source_create_status', ['pending', 'failed'])
                ->where(function ($query): void {
                    $query->whereNull('create_operation_id')->orWhereNull('source_create_retry_action');
                })
                ->orderBy('id')
                ->limit(500)
                ->get(['id', 'source_create_status', 'create_operation_id']);
            foreach ($rows as $row) {
                $hasReadyVersion = DB::table('legal_archive_document_versions')
                    ->where('document_id', $row->id)
                    ->where('processing_status', 'ready')
                    ->exists();
                DB::table('legal_archive_documents')->where('id', $row->id)->update([
                    'create_operation_id' => $row->create_operation_id ?: (string) Str::uuid(),
                    'source_create_status' => 'failed',
                    'source_create_attempt_token' => null,
                    'source_create_lease_expires_at' => null,
                    'source_create_retry_action' => $hasReadyVersion ? 'retry_finalize' : 'retry_upload',
                    'source_create_failure_fingerprint' => DB::raw("COALESCE(source_create_failure_fingerprint, encode(sha256(('legacy-create-recovery:' || id::text)::bytea), 'hex'))"),
                    'source_create_failed_at' => DB::raw('COALESCE(source_create_failed_at, CURRENT_TIMESTAMP)'),
                ]);
            }
        } while ($rows->isNotEmpty());

        DB::statement('ALTER TABLE legal_archive_documents VALIDATE CONSTRAINT legal_docs_create_retry_action_check');
        DB::statement('ALTER TABLE legal_archive_documents VALIDATE CONSTRAINT legal_docs_create_lease_coherence_check');
    }

    public function down(): void
    {
        throw new RuntimeException('legal_document_create_recovery_lease_validation_is_forward_only');
    }
};
