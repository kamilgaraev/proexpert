<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Services;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentTransmittal;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use App\Services\Storage\FileService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class ExecutiveTransmittalService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly UserProjectAccessService $projectAccess,
        private readonly FileService $files,
    ) {}

    private function query(int $userId, string $permission = 'executive-documentation.view'): Builder
    {
        $actor = User::query()->find($userId);
        $organizationId = (int) $actor?->current_organization_id;
        if ($actor === null || !$actor->belongsToOrganization($organizationId)) {
            throw new BusinessLogicException(trans_message('executive_documentation.errors.not_found'), 404);
        }
        if (!$this->authorization->can($actor, $permission, ['organization_id' => $organizationId])) {
            throw new BusinessLogicException(trans_message('executive_documentation.errors.forbidden'), 403);
        }
        return ExecutiveDocumentTransmittal::query()
            ->whereNotNull('manifest_hash')
            ->where('manifest->recipient->organization_id', $organizationId)
            ->whereHas('documentSet', fn (Builder $query) => $query->whereIn('project_id',
                $this->projectAccess->queryAccessibleProjects($actor, $organizationId)->select('projects.id')
            ));
    }

    public function list(int $userId, ?int $projectId = null): Collection
    {
        $actor = User::query()->findOrFail($userId);
        return $this->query($userId)->with('documentSet')
            ->when($projectId !== null, fn (Builder $query) => $query->whereHas('documentSet', fn (Builder $set) => $set->where('project_id', $projectId)))
            ->latest('id')->limit(25)->get()->filter(fn ($item) => $this->authorization->can($actor, 'executive-documentation.view', ['organization_id' => (int) $actor->current_organization_id, 'project_id' => (int) $item->documentSet->project_id, 'strict_project_scope' => true]))->values();
    }

    public function find(int $id, int $userId, string $permission = 'executive-documentation.view'): ExecutiveDocumentTransmittal
    {
        $transmittal = $this->query($userId, $permission)->with('documentSet')->find($id);
        if ($transmittal === null) {
            throw new BusinessLogicException(trans_message('executive_documentation.errors.not_found'), 404);
        }
        $actor = User::query()->findOrFail($userId);
        if (!$this->authorization->can($actor, $permission, [
            'organization_id' => (int) $actor->current_organization_id,
            'project_id' => (int) $transmittal->documentSet->project_id,
            'strict_project_scope' => true,
        ])) {
            throw new BusinessLogicException(trans_message('executive_documentation.errors.forbidden'), 403);
        }
        return $transmittal;
    }

    public function latestForSet(int $setId, int $userId): ExecutiveDocumentTransmittal
    {
        $item = $this->query($userId)->where('document_set_id', $setId)->latest('id')->first();
        if ($item === null) {
            throw new BusinessLogicException(trans_message('executive_documentation.errors.not_found'), 404);
        }
        return $this->find((int) $item->id, $userId);
    }

    public function remark(int $documentId, int $userId, array $data): \App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentRemark
    {
        $initial = $this->find((int) $data['transmittal_id'], $userId, 'executive-documentation.review');
        return DB::transaction(function () use ($initial, $documentId, $userId, $data) {
            ExecutiveDocumentSet::query()->lockForUpdate()->findOrFail($initial->document_set_id);
            $document = ExecutiveDocument::query()->where('document_set_id', $initial->document_set_id)->whereKey($documentId)->lockForUpdate()->first();
            $transmittal = ExecutiveDocumentTransmittal::query()->lockForUpdate()->findOrFail($initial->id);
            $this->find((int) $transmittal->id, $userId, 'executive-documentation.review');
            $key = trim((string) ($data['operation_key'] ?? ''));
            if ($key === '') {
                throw new DomainException(trans_message('executive_documentation.errors.transmittal_operation_conflict'));
            }
            $hash = hash('sha256', json_encode([$transmittal->id, $documentId, (int) $data['version_id'], $data['body'], $data['severity'] ?? 'major'], JSON_THROW_ON_ERROR));
            $existing = $document?->remarks()->where('metadata->operation_key', $key)->first();
            if ($existing !== null) {
                if ((int) $existing->created_by !== $userId || ($existing->metadata['request_hash'] ?? null) !== $hash) {
                    throw new DomainException(trans_message('executive_documentation.errors.transmittal_operation_conflict'));
                }
                return $existing;
            }
            $entry = collect($transmittal->manifest['documents'] ?? [])->firstWhere('document_id', $documentId);
            if ($document === null || $entry === null || (int) $entry['version_id'] !== (int) $data['version_id'] || $transmittal->status === 'accepted') {
                throw new DomainException(trans_message('executive_documentation.errors.remark_version_not_in_manifest'));
            }
            return $document->remarks()->create([
                'organization_id' => $document->organization_id, 'version_id' => $entry['version_id'],
                'created_by' => $userId, 'body' => $data['body'], 'severity' => $data['severity'] ?? 'major', 'status' => 'open',
                'metadata' => ['source' => 'customer', 'transmittal_id' => $transmittal->id, 'operation_key' => $key, 'request_hash' => $hash],
            ]);
        });
    }

    public function download(int $id, int $versionId, int $userId): string
    {
        $transmittal = $this->find($id, $userId);
        $entry = collect($transmittal->manifest['documents'] ?? [])->firstWhere('version_id', $versionId);
        $path = $entry['file_url'] ?? null;
        if (!is_string($path) || !str_starts_with($path, 'org-'.$transmittal->organization_id.'/')) {
            throw new BusinessLogicException(trans_message('executive_documentation.errors.version_not_found'), 404);
        }
        return $this->files->temporaryDownloadUrl($path, 300);
    }

    public function decide(int $id, int $userId, string $action, array $data): ExecutiveDocumentTransmittal
    {
        $initial = $this->find($id, $userId, 'executive-documentation.approve');
        return DB::transaction(function () use ($initial, $id, $userId, $action, $data): ExecutiveDocumentTransmittal {
            $set = ExecutiveDocumentSet::query()->lockForUpdate()->findOrFail($initial->document_set_id);
            $documents = ExecutiveDocument::query()->where('document_set_id', $set->id)->orderBy('id')->lockForUpdate()->get();
            $transmittal = ExecutiveDocumentTransmittal::query()->lockForUpdate()->findOrFail($id);
            $this->find($id, $userId, 'executive-documentation.approve');
            $key = (string) ($data['operation_key'] ?? '');
            if ($key === '' || !in_array($action, ['receive', 'return', 'accept'], true)
                || !hash_equals((string) $transmittal->manifest_hash, (string) ($data['expected_manifest_hash'] ?? ''))) {
                throw new DomainException(trans_message('executive_documentation.errors.version_conflict'));
            }
            $hash = hash('sha256', json_encode([$action, $data], JSON_THROW_ON_ERROR));
            $metadata = $transmittal->metadata ?? [];
            $existing = $metadata['customer_operations'][$key] ?? null;
            if ($existing !== null) {
                if ($existing['hash'] !== $hash || (int) $existing['actor_id'] !== $userId) {
                    throw new DomainException(trans_message('executive_documentation.errors.operation_conflict'));
                }
                return $transmittal;
            }
            if ($action === 'receive') {
                if ($transmittal->status !== 'sent' && $transmittal->acknowledged_at === null) {
                    throw new DomainException(trans_message('executive_documentation.errors.transmittal_decision_invalid'));
                }
                if ($transmittal->acknowledged_at === null) {
                    $transmittal->fill(['status' => 'received', 'acknowledged_at' => now(), 'acknowledged_by' => $userId, 'acknowledgement_comment' => $data['comment'] ?? null]);
                }
            } else {
                if ($transmittal->status !== 'received' || (int) $set->transmittal()->value('executive_document_transmittals.id') !== $id
                    || ($action === 'return' && trim((string) ($data['comment'] ?? '')) === '')) {
                    throw new DomainException(trans_message('executive_documentation.errors.transmittal_decision_invalid'));
                }
                if ($action === 'accept') {
                    foreach ($transmittal->manifest['documents'] ?? [] as $entry) {
                        $document = $documents->firstWhere('id', $entry['document_id']);
                        $version = $document?->versions()->first();
                        if ($version === null || (int) $version->id !== (int) $entry['version_id']
                            || $version->content_hash !== $entry['content_hash'] || $version->status !== 'transmitted'
                            || $document->openRemarks()->exists()) {
                            throw new DomainException(trans_message('executive_documentation.errors.transmittal_acceptance_blocked'));
                        }
                    }
                }
                $transmittal->fill(['status' => $action === 'accept' ? 'accepted' : 'returned', 'decision_by' => $userId, 'decision_at' => now(), 'decision_comment' => $data['comment'] ?? null]);
            }
            $metadata['customer_operations'][$key] = ['hash' => $hash, 'actor_id' => $userId, 'action' => $action, 'at' => now()->toIso8601String()];
            $transmittal->fill(['metadata' => $metadata])->save();
            return $transmittal->fresh('documentSet');
        });
    }

    public function present(ExecutiveDocumentTransmittal $transmittal, int $userId = 0): array
    {
        $manifest = $transmittal->manifest ?? [];
        $actor = User::query()->find($userId);
        $canDecide = $actor !== null && $this->authorization->can($actor, 'executive-documentation.approve', ['organization_id' => (int) $actor->current_organization_id, 'project_id' => (int) $transmittal->documentSet->project_id, 'strict_project_scope' => true]);
        $latest = (int) $transmittal->documentSet->transmittal()->value('executive_document_transmittals.id') === (int) $transmittal->id;
        $previous = $transmittal->previous_transmittal_id ? ExecutiveDocumentTransmittal::query()->find($transmittal->previous_transmittal_id) : null;
        $oldVersions = collect($previous?->manifest['documents'] ?? [])->keyBy('document_id');
        $changed = collect($manifest['documents'] ?? [])->filter(fn ($entry) => ($oldVersions[$entry['document_id']]['version_id'] ?? null) !== $entry['version_id'])->pluck('document_id')->values()->all();
        return array_merge($manifest['set'] ?? [], [
            'project' => $manifest['project'] ?? null,
            'status' => 'transmitted',
            'status_label' => trans_message('executive_documentation.statuses.transmitted'),
            'documents' => collect($manifest['documents'] ?? [])->map(fn ($entry) => [
                'id' => $entry['document_id'], 'title' => $entry['title'], 'document_type' => $entry['document_type'],
                'document_type_label' => $entry['document_type_label'], 'status' => 'transmitted',
                'status_label' => trans_message('executive_documentation.statuses.transmitted'),
                'versions' => [['id' => $entry['version_id'], 'version_number' => $entry['version_number'], 'content_hash' => $entry['content_hash']]],
            ])->all(),
            'transmittal' => [
                'id' => $transmittal->id, 'transmittal_number' => $transmittal->transmittal_number,
                'manifest_hash' => $transmittal->manifest_hash, 'status' => $transmittal->status,
                'transmitted_at' => $transmittal->transmitted_at?->toIso8601String(),
                'received_at' => $transmittal->acknowledged_at?->toIso8601String(),
                'decision_at' => $transmittal->decision_at?->toIso8601String(),
                'comment' => $transmittal->comment, 'decision_comment' => $transmittal->decision_comment,
                'previous_transmittal_id' => $transmittal->previous_transmittal_id, 'changed_document_ids' => $changed,
                'available_actions' => !$canDecide ? [] : match ($transmittal->status) { 'sent' => ['receive'], 'received' => $latest ? ['return', 'accept'] : [], default => [] },
            ],
        ]);
    }
}
