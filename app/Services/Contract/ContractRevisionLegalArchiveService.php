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

final class ContractRevisionLegalArchiveService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly ContractBuilderInstanceService $instances,
        private readonly ContractRevisionArchivePublisher $publisher,
        private readonly LegalDocumentAuthorizer $access,
        private readonly FileService $files,
    ) {}

    public function prepare(User $actor, int $organizationId, int $contractId, int $number, string $hash): array
    {
        $revisionData = $this->instances->read($actor, $organizationId, $contractId, $number);
        if (!$this->canPrepare($actor, $organizationId)) {
            throw new AuthorizationException;
        }
        $binding = DB::transaction(function () use ($actor, $organizationId, $contractId, $number, $hash): object {
            Contract::whereKey($contractId)->lockForUpdate()->firstOrFail();
            $preview = $this->instances->preview($actor, $organizationId, $contractId, $number);
            $revision = $preview['revision'];
            $own = array_filter($revision['parties'], static fn (array $party): bool => (int) ($party['linked_organization_id'] ?? 0) === $organizationId);
            if (count($own) !== 1 || !hash_equals($revision['content_hash'], $hash)) {
                throw new ContractBuilderException('contracts.confirmation_conflict', 409);
            }
            $existing = DB::table('contract_revision_legal_bindings')->where('revision_id', $revision['id'])->where('organization_id', $organizationId)->first();
            if ($existing !== null) {
                return $existing;
            }
            $current = DB::table('contract_builder_instances')->where('contract_id', $contractId)->value('current_revision_id');
            if ((int) $current !== $revision['id']) {
                throw new ContractBuilderException('contracts.confirmation_conflict', 409);
            }
            $manifest = '<section><h2>'.e(trans_message('contracts.revision_bundle_manifest')).'</h2><p>'.e($hash).'</p><ul>';
            foreach ($revision['attachments'] as $asset) {
                $manifest .= '<li>'.e($asset['name']).' — '.e($asset['sha256']).'</li>';
            }
            $manifest .= '</ul></section>';
            $bytes = (new ContractDocumentExporter)->render($preview['html'].$manifest, 'pdf');
            $fileHash = hash('sha256', $bytes);
            $organization = Organization::findOrFail($organizationId);
            $filename = 'contract-'.$contractId.'-revision-'.$number.'.pdf';
            $path = $this->files->putContent($bytes, 'contracts/'.$contractId.'/builder/legal', $fileHash.'.pdf', 'private', $organization);
            if ($path === false) {
                throw new ContractBuilderException('contracts.builder_asset_storage_failed', 503);
            }
            $id = DB::table('contract_revision_legal_bindings')->insertGetId([
                'revision_id' => $revision['id'], 'organization_id' => $organizationId, 'created_by' => $actor->id,
                'title' => trans_message('contracts.revision_legal_title', ['contract' => $contractId, 'revision' => $number]),
                'filename' => $filename, 'storage_path' => $path, 'content_hash' => $hash, 'file_hash' => $fileHash,
                'size' => strlen($bytes), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return DB::table('contract_revision_legal_bindings')->where('id', $id)->firstOrFail();
        });
        if ($binding->document_version_id === null) {
            $temporary = null;
            try {
                $organization = Organization::findOrFail($organizationId);
                $stream = $this->files->disk($organization)->readStream($binding->storage_path);
                if (!is_resource($stream)) {
                    throw new \RuntimeException('contract_revision_bundle_missing');
                }
                try { $bytes = stream_get_contents($stream, 20 * 1024 * 1024 + 1); }
                finally { fclose($stream); }
                if ($bytes === false || strlen($bytes) !== (int) $binding->size || !hash_equals($binding->file_hash, hash('sha256', $bytes))) {
                    throw new \RuntimeException('contract_revision_bundle_hash_mismatch');
                }
                $temporary = tempnam(sys_get_temp_dir(), 'most-revision-');
                if ($temporary === false || file_put_contents($temporary, $bytes) !== strlen($bytes)) {
                    throw new \RuntimeException('contract_revision_bundle_temporary_file');
                }
                $metadata = ['contract_id' => $contractId, 'contract_revision_id' => (int) $binding->revision_id,
                    'contract_content_hash' => $binding->content_hash, 'contract_revision_binding_id' => (int) $binding->id];
                $document = $this->publisher->publish($organizationId, (int) $binding->created_by, [
                    'title' => $binding->title, 'document_type' => 'contract', 'status' => 'draft',
                    'type_profile_code' => in_array('subcontractor', array_column($revisionData['parties'], 'role'), true) ? 'contract.subcontract' : 'contract.work',
                    'confidentiality_level' => 'internal', 'direction' => 'internal',
                    'metadata' => $metadata, 'version_metadata' => $metadata,
                    'create_operation_key' => 'contract-revision-'.$binding->revision_id.'-org-'.$organizationId,
                ], new UploadedFile($temporary, $binding->filename, 'application/pdf', null, true));
                $version = LegalArchiveDocumentVersion::query()->where('document_id', $document->id)->where('organization_id', $organizationId)
                    ->where('content_hash', $binding->file_hash)->where('processing_status', 'ready')->orderBy('id')->firstOrFail();
                DB::transaction(function () use ($binding, $document, $version, $revisionData): void {
                    $locked = DB::table('contract_revision_legal_bindings')->where('id', $binding->id)->lockForUpdate()->firstOrFail();
                    if ($locked->document_version_id !== null && (int) $locked->document_version_id !== (int) $version->id) {
                        throw new \RuntimeException('contract_revision_binding_conflict');
                    }
                    LegalArchiveDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
                    $snapshot = LegalDocumentPartySnapshotSet::where('document_version_id', $version->id)->first();
                    if ($snapshot === null) {
                        $snapshot = LegalDocumentPartySnapshotSet::create([
                            'organization_id' => $binding->organization_id, 'document_id' => $document->id, 'document_version_id' => $version->id,
                            'captured_at' => now(), 'captured_by_user_id' => $binding->created_by,
                        ]);
                        foreach ($revisionData['parties'] as $party) {
                            LegalDocumentParty::create([
                                'organization_id' => $binding->organization_id, 'document_id' => $document->id,
                                'document_version_id' => $version->id, 'snapshot_set_id' => $snapshot->id,
                                'party_organization_id' => $party['linked_organization_id'], 'party_role' => $party['side'] === 'first' ? 'customer' : 'contractor',
                                'legal_name' => $party['legal_name'] ?: $party['name'], 'tax_number' => $party['inn'],
                                'registration_number' => $party['ogrn'], 'legal_address' => $party['legal_address'],
                                'data_source' => $party['linked_organization_id'] === null ? 'manual' : 'organization', 'snapshot' => $party,
                            ]);
                        }
                    }
                    DB::table('contract_revision_legal_bindings')->where('id', $binding->id)->update([
                        'document_id' => $document->id, 'document_version_id' => $version->id, 'last_error' => null, 'updated_at' => now(),
                    ]);
                });
            } catch (\Throwable $exception) {
                DB::table('contract_revision_legal_bindings')->where('id', $binding->id)->whereNull('document_version_id')->update(['last_error' => 'archive_preparation_failed', 'updated_at' => now()]);
                Log::error('Contract revision archive preparation failed', ['contract_id' => $contractId, 'revision_id' => $binding->revision_id, 'organization_id' => $organizationId, 'exception' => $exception::class]);
                throw new ContractBuilderException('contracts.revision_archive_failed', 503);
            } finally {
                if (is_string($temporary) && is_file($temporary)) { unlink($temporary); }
            }
        }

        return $this->state($actor, $organizationId, $contractId, $number);
    }

    public function state(User $actor, int $organizationId, int $contractId, int $number): array
    {
        $revision = $this->instances->read($actor, $organizationId, $contractId, $number);
        $canPrepare = $this->canPrepare($actor, $organizationId);
        $empty = ['can_prepare' => $canPrepare, 'binding' => null];
        if (!$this->authorization->can($actor, 'legal_archive.view', ['organization_id' => $organizationId])) {
            return $empty;
        }
        $binding = DB::table('contract_revision_legal_bindings')->where('revision_id', $revision['id'])->where('organization_id', $organizationId)->first();
        if ($binding === null) { return $empty; }
        if ($binding->document_version_id === null) {
            return ['can_prepare' => $canPrepare, 'binding' => ['status' => $binding->last_error === null ? 'preparing' : 'failed', 'revision_id' => $revision['id']]];
        }
        $document = LegalArchiveDocument::query()->where('organization_id', $organizationId)->findOrFail($binding->document_id);
        try { $this->access->authorize($actor, $document, 'view'); }
        catch (AuthorizationException) { return ['can_prepare' => false, 'binding' => null]; }
        $version = LegalArchiveDocumentVersion::query()->where('document_id', $document->id)->where('organization_id', $organizationId)->findOrFail($binding->document_version_id);

        return ['can_prepare' => false, 'binding' => ['status' => 'ready', 'revision_id' => $revision['id'], 'content_hash' => $binding->content_hash,
            'document_id' => (int) $document->id, 'document_version_id' => (int) $version->id, 'file_hash' => $binding->file_hash,
            'version_number' => $version->version_number, 'is_current' => (bool) $version->is_current, 'version_status' => $version->status]];
    }

    private function canPrepare(User $actor, int $organizationId): bool
    {
        return (int) $actor->current_organization_id === $organizationId
            && $this->authorization->can($actor, 'contracts.edit', ['organization_id' => $organizationId])
            && $this->authorization->can($actor, 'legal_archive.create', ['organization_id' => $organizationId])
            && $this->authorization->can($actor, 'legal_archive.view', ['organization_id' => $organizationId]);
    }
}
