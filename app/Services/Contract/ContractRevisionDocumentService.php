<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentFile;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Jobs\GenerateContractRevisionDocument;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Files\LegalDocumentFileService;
use App\Services\LegalArchive\Files\LegalDocumentVersionAttempt;
use App\Services\LegalArchive\Files\LegalDocumentVersionLeaseLost;
use App\Services\LegalArchive\Files\VersionInput;
use App\Services\Storage\FileService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ContractRevisionDocumentService
{
    public function __construct(private readonly ContractDocumentExporter $exporter, private readonly FileService $storage, private readonly LegalDocumentFileService $files) {}

    public function request(int $revisionId, int $actorId): int
    {
        $revision = DB::table('contract_builder_revisions')->where('id', $revisionId)->firstOrFail();
        $contractId = DB::table('contract_builder_instances')->where('id', $revision->instance_id)->value('contract_id');
        $contract = Contract::findOrFail($contractId);
        $document = $contract->legalArchiveDocument()->where('organization_id', $contract->organization_id)->first();
        $id = DB::transaction(function () use ($revision, $contract, $document, $actorId): int {
            Contract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('contract_revision_documents')->where('revision_id', $revision->id)->where('organization_id', $contract->organization_id)->first();
            if ($existing !== null) {
                if ($existing->document_id === null && $document !== null && (int) $document->organization_id === (int) $contract->organization_id) {
                    DB::table('contract_revision_documents')->where('id', $existing->id)->update(['document_id' => $document->id, 'updated_at' => now()]);
                }
                return (int) $existing->id;
            }

            return DB::table('contract_revision_documents')->insertGetId([
                'revision_id' => $revision->id, 'organization_id' => $contract->organization_id,
                'document_id' => $document?->id, 'created_by' => $actorId, 'operation_id' => (string) Str::uuid(),
                'content_hash' => $revision->content_hash, 'status' => $document === null ? 'failed' : 'pending',
                'last_error' => $document === null ? 'dossier_missing' : null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        if ($document !== null) {
            $this->enqueue($id);
        }

        return $id;
    }

    public function generate(int $id): bool
    {
        $token = bin2hex(random_bytes(32));
        $row = DB::transaction(function () use ($id, $token): ?object {
            $row = DB::table('contract_revision_documents')->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($row->status === 'ready') {
                return $row;
            }
            if ($row->status === 'processing' && $row->lease_until !== null && now()->lt($row->lease_until)) {
                return null;
            }
            DB::table('contract_revision_documents')->where('id', $id)->update([
                'status' => 'processing', 'attempt_token' => $token, 'lease_until' => now()->addMinutes(3), 'last_error' => null, 'updated_at' => now(),
            ]);

            return DB::table('contract_revision_documents')->where('id', $id)->firstOrFail();
        });
        if ($row === null) {
            return false;
        }
        if ($row->status === 'ready') {
            return true;
        }
        $temporary = null;
        try {
            $revision = DB::table('contract_builder_revisions')->where('id', $row->revision_id)->firstOrFail();
            $contractId = DB::table('contract_builder_instances')->where('id', $revision->instance_id)->value('contract_id');
            $contract = Contract::where('organization_id', $row->organization_id)->findOrFail($contractId);
            $document = LegalArchiveDocument::where('organization_id', $row->organization_id)->findOrFail($row->document_id);
            if ((int) $contract->legalArchiveDocument()->value('id') !== (int) $document->id
                || !hash_equals($row->content_hash, $revision->content_hash) || $document->lifecycle_status === 'archived') {
                throw new RuntimeException('contract_document_source_unavailable');
            }
            $organization = Organization::findOrFail($row->organization_id);
            if ($row->storage_path === null) {
                $html = (new ContractRevisionDocumentRenderer)->render($revision);
                $bytes = $this->exporter->render($html, 'docx');
                if (strlen($bytes) > 20 * 1024 * 1024) {
                    throw new RuntimeException('contract_document_too_large');
                }
                $hash = hash('sha256', $bytes);
                $path = $this->storage->putContent($bytes, 'contracts/'.$contractId.'/builder/documents/'.$row->revision_id, $hash.'.docx', 'private', $organization);
                if (!is_string($path) || $path === '') {
                    throw new RuntimeException('contract_document_storage_failed');
                }
                DB::transaction(function () use ($row, $document, $token, $path, $hash, $bytes): void {
                    $this->assertOwned($row, $document, $token);
                    DB::table('contract_revision_documents')->where('id', $row->id)->update(['storage_path' => $path, 'file_hash' => $hash, 'size' => strlen($bytes), 'updated_at' => now()]);
                });
                $row = DB::table('contract_revision_documents')->where('id', $id)->firstOrFail();
            }
            $stream = $this->storage->disk($organization)->readStream($row->storage_path);
            if (!is_resource($stream)) {
                throw new RuntimeException('contract_document_storage_missing');
            }
            try {
                $bytes = stream_get_contents($stream, 20 * 1024 * 1024 + 1);
            } finally {
                fclose($stream);
            }
            if ($bytes === false || strlen($bytes) !== (int) $row->size || !hash_equals($row->file_hash, hash('sha256', $bytes))) {
                throw new RuntimeException('contract_document_hash_mismatch');
            }
            $temporary = tempnam(sys_get_temp_dir(), 'most-contract-docx-');
            if ($temporary === false || file_put_contents($temporary, $bytes) !== strlen($bytes)) {
                throw new RuntimeException('contract_document_temporary_file');
            }
            $file = DB::transaction(function () use ($row, $document, $token): LegalArchiveDocumentFile {
                LegalArchiveDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
                $this->assertOwned($row, $document, $token);

                return LegalArchiveDocumentFile::firstOrCreate(['document_id' => $document->id, 'organization_id' => $row->organization_id, 'role' => 'primary'],
                    ['title' => $document->title, 'sort_order' => 0, 'is_required' => true]);
            });
            $attempt = new LegalDocumentVersionAttempt($row->operation_id, $token,
                fn (LegalArchiveDocument $locked, string $ownedToken) => $this->assertOwned($row, $locked, $ownedToken),
                heartbeatCallback: function (string $ownedToken) use ($row): void {
                    DB::table('contract_revision_documents')->where('id', $row->id)->where('attempt_token', $ownedToken)->where('status', 'processing')
                        ->update(['lease_until' => now()->addMinutes(3), 'updated_at' => now()]);
                },
                completionCallback: function (LegalArchiveDocumentVersion $version, string $ownedToken) use ($row, $document): void {
                    $this->assertOwned($row, $document, $ownedToken);
                    if ($version->processing_status !== 'ready' || (int) $version->document_id !== (int) $row->document_id) {
                        throw new RuntimeException('contract_document_version_not_ready');
                    }
                    DB::table('contract_revision_documents')->where('id', $row->id)->update([
                        'status' => 'ready', 'document_version_id' => $version->id, 'last_error' => null, 'attempt_token' => null, 'lease_until' => null, 'updated_at' => now(),
                    ]);
                });
            $this->files->addVersion($file, new UploadedFile($temporary, 'contract-'.$contractId.'-revision-'.$revision->revision_number.'.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true), new VersionInput(
                versionLabel: trans_message('contract_templates.document_revision', ['number' => $revision->revision_number]),
                uploadedByUserId: (int) $row->created_by, makeCurrent: false,
                metadata: ['source' => 'contract_revision', 'contract_id' => (int) $contractId, 'contract_revision_id' => (int) $row->revision_id,
                    'contract_revision_number' => (int) $revision->revision_number, 'contract_content_hash' => $row->content_hash],
            ), $attempt);

            return true;
        } catch (Throwable $error) {
            DB::table('contract_revision_documents')->where('id', $id)->where('attempt_token', $token)->update([
                'status' => 'failed', 'last_error' => 'generation_failed', 'attempt_token' => null, 'lease_until' => null, 'updated_at' => now(),
            ]);
            Log::error('contract_revision_document_failed', ['generation_id' => $id, 'revision_id' => $row->revision_id, 'exception' => $error::class]);
            throw $error;
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function state(User $actor, int $organizationId, int $contractId, int $number): array
    {
        $revision = app(ContractBuilderInstanceService::class)->read($actor, $organizationId, $contractId, $number);
        $contract = Contract::findOrFail($contractId);
        $row = DB::table('contract_revision_documents')->where('revision_id', $revision['id'])->where('organization_id', $contract->organization_id)->first();
        $canRetry = (int) $contract->organization_id === $organizationId && app(AuthorizationService::class)->can($actor, 'contracts.edit', ['organization_id' => $organizationId]);
        $status = $row?->status ?? 'not_requested';
        if ($status === 'processing' && $row->lease_until !== null && now()->gt($row->lease_until)) {
            $status = 'failed';
        }
        if ($status === 'pending' && now()->subMinutes(10)->gt($row->updated_at)) {
            $status = 'failed';
        }
        $result = ['status' => $status, 'revision_number' => $number, 'can_retry' => $canRetry && in_array($status, ['failed', 'not_requested'], true), 'document_id' => null, 'document_version_id' => null];
        if ($row?->document_version_id !== null) {
            $document = LegalArchiveDocument::where('organization_id', $contract->organization_id)->find($row->document_id);
            if ($document !== null) {
                try {
                    app(LegalDocumentAuthorizer::class)->authorize($actor, $document, 'view');
                    $result['document_id'] = (int) $document->id;
                    $result['document_version_id'] = (int) $row->document_version_id;
                } catch (AuthorizationException) {
                }
            } else {
                $result['status'] = 'unavailable';
                $result['can_retry'] = false;
            }
        }

        return $result;
    }

    public function retry(User $actor, int $organizationId, int $contractId, int $number): array
    {
        $state = $this->state($actor, $organizationId, $contractId, $number);
        if (!$state['can_retry']) {
            throw new AuthorizationException;
        }
        $revision = app(ContractBuilderInstanceService::class)->read($actor, $organizationId, $contractId, $number);
        DB::transaction(function () use ($revision, $actor): void {
            $id = $this->request((int) $revision['id'], (int) $actor->id);
            DB::table('contract_revision_documents')->where('id', $id)->whereNotNull('document_id')->where(function ($query): void {
                $query->where('status', 'failed')->orWhere(function ($expired): void {
                    $expired->where('status', 'processing')->where('lease_until', '<', now());
                })->orWhere(function ($pending): void {
                    $pending->where('status', 'pending')->where('updated_at', '<', now()->subMinutes(10));
                });
            })->update(['status' => 'pending', 'last_error' => null, 'updated_at' => now()]);
        });

        return $this->state($actor, $organizationId, $contractId, $number);
    }

    private function assertOwned(object $row, LegalArchiveDocument $document, string $token): void
    {
        $current = DB::table('contract_revision_documents')->where('id', $row->id)->lockForUpdate()->firstOrFail();
        if ($current->status !== 'processing' || !is_string($current->attempt_token) || !hash_equals($current->attempt_token, $token)
            || (int) $document->id !== (int) $current->document_id || (int) $document->organization_id !== (int) $current->organization_id
            || $current->lease_until === null || now()->gt($current->lease_until) || $document->trashed() || $document->lifecycle_status === 'archived') {
            throw new LegalDocumentVersionLeaseLost;
        }
    }

    private function enqueue(int $id): void
    {
        DB::afterCommit(static function () use ($id): void {
            try {
                GenerateContractRevisionDocument::dispatch($id);
            } catch (Throwable $error) {
                DB::table('contract_revision_documents')->where('id', $id)->where('status', 'pending')->update(['status' => 'failed', 'last_error' => 'queue_unavailable', 'updated_at' => now()]);
                Log::error('contract_document_enqueue_failed', ['generation_id' => $id, 'exception' => $error::class]);
            }
        });
    }
}
