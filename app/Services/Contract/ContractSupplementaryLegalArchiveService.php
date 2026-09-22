<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentParty;
use App\BusinessModules\Features\LegalArchive\Models\LegalDocumentPartySnapshotSet;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\ContractBuilderException;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\Storage\FileService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ContractSupplementaryLegalArchiveService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly ContractSupplementaryDocumentService $documents,
        private readonly ContractSupplementaryDocumentComposer $composer,
        private readonly ContractRevisionArchivePublisher $publisher,
        private readonly LegalDocumentAuthorizer $access,
        private readonly FileService $files,
    ) {}

    public function prepare(User $actor, int $organizationId, int $contractId, int $documentId, string $hash): array
    {
        $documentData = $this->documents->show($actor, $organizationId, $contractId, $documentId);
        if (!$this->canPrepare($actor, $organizationId)) {
            throw new AuthorizationException;
        }
        $binding = DB::transaction(function () use ($actor, $organizationId, $contractId, $documentId, $hash, $documentData): object {
            Contract::whereKey($contractId)->lockForUpdate()->firstOrFail();
            $row = DB::table('contract_supplementary_documents')->where('contract_id', $contractId)->where('id', $documentId)->lockForUpdate()->firstOrFail();
            $own = array_filter(json_decode($row->parties, true, 512, JSON_THROW_ON_ERROR), static fn (array $party): bool => (int) ($party['linked_organization_id'] ?? 0) === $organizationId);
            if (count($own) !== 1 || !hash_equals($row->content_hash, $hash) || $documentData['changes'] === []) {
                throw new ContractBuilderException('contracts.confirmation_conflict', 409);
            }
            $existing = DB::table('contract_supplementary_legal_bindings')->where('document_id', $row->id)->where('organization_id', $organizationId)->first();
            if ($existing !== null) {
                return $existing;
            }
            $preview = $this->documents->preview($actor, $organizationId, $contractId, $documentId);
            $bytes = (new ContractDocumentExporter)->render($preview['html'], 'pdf');
            $fileHash = hash('sha256', $bytes);
            $organization = Organization::findOrFail($organizationId);
            $filename = 'contract-'.$contractId.'-supplementary-'.$documentId.'.pdf';
            $path = $this->files->putContent($bytes, 'contracts/'.$contractId.'/builder/legal', $fileHash.'.pdf', 'private', $organization);
            if ($path === false) {
                throw new ContractBuilderException('contracts.builder_asset_storage_failed', 503);
            }
            $id = DB::table('contract_supplementary_legal_bindings')->insertGetId([
                'document_id' => $row->id, 'organization_id' => $organizationId, 'created_by' => $actor->id,
                'title' => trans_message('contracts.supplementary_legal_title', ['contract' => $contractId, 'number' => $row->number]),
                'filename' => $filename, 'storage_path' => $path, 'content_hash' => $hash, 'file_hash' => $fileHash,
                'size' => strlen($bytes), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return DB::table('contract_supplementary_legal_bindings')->where('id', $id)->firstOrFail();
        });
        if ($binding->document_version_id === null) {
            $temporary = null;
            try {
                $organization = Organization::findOrFail($organizationId);
                $stream = $this->files->disk($organization)->readStream($binding->storage_path);
                if (!is_resource($stream)) {
                    throw new \RuntimeException('contract_supplementary_bundle_missing');
                }
                try {
                    $bytes = stream_get_contents($stream, 20 * 1024 * 1024 + 1);
                } finally {
                    fclose($stream);
                }
                if ($bytes === false || strlen($bytes) !== (int) $binding->size || !hash_equals($binding->file_hash, hash('sha256', $bytes))) {
                    throw new \RuntimeException('contract_supplementary_bundle_hash_mismatch');
                }
                $temporary = tempnam(sys_get_temp_dir(), 'most-supplementary-');
                if ($temporary === false || file_put_contents($temporary, $bytes) !== strlen($bytes)) {
                    throw new \RuntimeException('contract_supplementary_bundle_temporary_file');
                }
                $metadata = [
                    'contract_id' => $contractId, 'contract_supplementary_document_id' => (int) $binding->document_id,
                    'contract_content_hash' => $binding->content_hash, 'contract_supplementary_binding_id' => (int) $binding->id,
                ];
                $archive = $this->publisher->publish($organizationId, (int) $binding->created_by, [
                    'title' => $binding->title, 'document_type' => 'contract', 'status' => 'draft',
                    'type_profile_code' => 'contract.work', 'confidentiality_level' => 'internal', 'direction' => 'internal',
                    'metadata' => $metadata, 'version_metadata' => $metadata,
                    'create_operation_key' => 'contract-supplementary-'.$binding->document_id.'-org-'.$organizationId,
                ], new UploadedFile($temporary, $binding->filename, 'application/pdf', null, true));
                $version = LegalArchiveDocumentVersion::query()->where('document_id', $archive->id)->where('organization_id', $organizationId)
                    ->where('content_hash', $binding->file_hash)->where('processing_status', 'ready')->orderBy('id')->firstOrFail();
                DB::transaction(function () use ($binding, $archive, $version, $documentData): void {
                    $locked = DB::table('contract_supplementary_legal_bindings')->where('id', $binding->id)->lockForUpdate()->firstOrFail();
                    if ($locked->document_version_id !== null && (int) $locked->document_version_id !== (int) $version->id) {
                        throw new \RuntimeException('contract_supplementary_binding_conflict');
                    }
                    LegalArchiveDocument::whereKey($archive->id)->lockForUpdate()->firstOrFail();
                    $snapshot = LegalDocumentPartySnapshotSet::where('document_version_id', $version->id)->first();
                    if ($snapshot === null) {
                        $snapshot = LegalDocumentPartySnapshotSet::create([
                            'organization_id' => $binding->organization_id, 'document_id' => $archive->id, 'document_version_id' => $version->id,
                            'captured_at' => now(), 'captured_by_user_id' => $binding->created_by,
                        ]);
                        foreach ($documentData['parties'] as $party) {
                            LegalDocumentParty::create([
                                'organization_id' => $binding->organization_id, 'document_id' => $archive->id,
                                'document_version_id' => $version->id, 'snapshot_set_id' => $snapshot->id,
                                'party_organization_id' => $party['linked_organization_id'],
                                'party_role' => $party['side'] === 'first' ? 'customer' : 'contractor',
                                'legal_name' => $party['legal_name'] ?? $party['name'], 'tax_number' => $party['inn'] ?? null,
                                'registration_number' => $party['ogrn'] ?? null, 'legal_address' => $party['legal_address'] ?? null,
                                'data_source' => ($party['linked_organization_id'] ?? null) === null ? 'manual' : 'organization', 'snapshot' => $party,
                            ]);
                        }
                    }
                    DB::table('contract_supplementary_legal_bindings')->where('id', $binding->id)->update([
                        'document_archive_id' => $archive->id, 'document_version_id' => $version->id, 'last_error' => null, 'updated_at' => now(),
                    ]);
                });
            } catch (\Throwable $exception) {
                DB::table('contract_supplementary_legal_bindings')->where('id', $binding->id)->whereNull('document_version_id')
                    ->update(['last_error' => 'archive_preparation_failed', 'updated_at' => now()]);
                Log::error('Contract supplementary archive preparation failed', [
                    'contract_id' => $contractId, 'document_id' => $binding->document_id,
                    'organization_id' => $organizationId, 'exception' => $exception::class,
                ]);
                throw new ContractBuilderException('contracts.supplementary_archive_failed', 503);
            } finally {
                if (is_string($temporary) && is_file($temporary)) {
                    unlink($temporary);
                }
            }
        }

        return $this->state($actor, $organizationId, $contractId, $documentId);
    }

    public function state(User $actor, int $organizationId, int $contractId, int $documentId): array
    {
        $this->documents->show($actor, $organizationId, $contractId, $documentId);
        $canPrepare = $this->canPrepare($actor, $organizationId);
        $empty = ['can_prepare' => $canPrepare, 'binding' => null];
        if (!$this->authorization->can($actor, 'legal_archive.view', ['organization_id' => $organizationId])) {
            return $empty;
        }
        $binding = DB::table('contract_supplementary_legal_bindings')->where('document_id', $documentId)->where('organization_id', $organizationId)->first();
        if ($binding === null) {
            return $empty;
        }
        if ($binding->document_version_id === null) {
            return ['can_prepare' => $canPrepare, 'binding' => ['status' => $binding->last_error === null ? 'preparing' : 'failed', 'document_id' => $documentId]];
        }
        $archive = LegalArchiveDocument::query()->where('organization_id', $organizationId)->findOrFail($binding->document_archive_id);
        try {
            $this->access->authorize($actor, $archive, 'view');
        } catch (AuthorizationException) {
            return ['can_prepare' => false, 'binding' => null];
        }
        $version = LegalArchiveDocumentVersion::query()->where('document_id', $archive->id)->where('organization_id', $organizationId)->findOrFail($binding->document_version_id);

        return ['can_prepare' => false, 'binding' => [
            'status' => 'ready', 'document_id' => $documentId, 'content_hash' => $binding->content_hash,
            'archive_document_id' => (int) $archive->id, 'document_version_id' => (int) $version->id,
            'file_hash' => $binding->file_hash, 'version_number' => $version->version_number,
            'is_current' => (bool) $version->is_current, 'version_status' => $version->status,
        ]];
    }

    private function canPrepare(User $actor, int $organizationId): bool
    {
        return (int) $actor->current_organization_id === $organizationId
            && $this->authorization->can($actor, 'contracts.edit', ['organization_id' => $organizationId])
            && $this->authorization->can($actor, 'legal_archive.create', ['organization_id' => $organizationId])
            && $this->authorization->can($actor, 'legal_archive.view', ['organization_id' => $organizationId]);
    }
}
