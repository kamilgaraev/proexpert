<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\BusinessModules\Features\DesignManagement\Services\DesignIssueContextResolver;
use App\BusinessModules\Features\DesignManagement\Models\DesignIfcModelElement;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelSetRevision;
use App\BusinessModules\Features\DesignManagement\Services\DesignProjectIssueService;
use App\BusinessModules\Features\DesignManagement\Services\DesignModelSessionPayloadValidator;
use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\Exceptions\BusinessLogicException;
use App\Models\Organization;
use App\Models\User;
use App\Services\Storage\FileService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final readonly class MobileDesignManagementIssueService
{
    public function __construct(
        private MobileDesignManagementAccess $access,
        private DesignProjectIssueService $issues,
        private DesignIssueContextResolver $contexts,
        private MobileMutationIdempotency $idempotency,
        private FileService $files,
        private MobileDesignManagementService $models,
    ) {}

    public function list(User $actor, int $organizationId, array $filters): array
    {
        $projectId = (int) $filters['project_id'];
        $this->access->project($actor, $organizationId, $projectId, 'design-management.view');
        if (isset($filters['version_id'])) {
            $version = $this->access->version($actor, $organizationId, (int) $filters['version_id']);
            if ((int) $version->project_id !== $projectId) {
                throw new BusinessLogicException(trans_message('design_issues.errors.target_not_found'), 404);
            }
        }
        $page = QualityDefect::query()->forOrganization($organizationId)->projectIssues()->where('project_id', $projectId)
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['version_id']), fn ($query) => $query->whereJsonContains('metadata->design_issue_context->version_id', (int) $filters['version_id']))
            ->with(['photos', 'statusHistory.changedBy', 'createdBy', 'assignedUser'])->latest('id')
            ->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);

        return ['items' => $page->getCollection(), 'meta' => $this->models->meta($page)];
    }

    public function find(User $actor, int $organizationId, int $issueId, string $permission = 'design-management.view'): QualityDefect
    {
        $issue = QualityDefect::query()->forOrganization($organizationId)->projectIssues()
            ->with(['photos', 'statusHistory.changedBy', 'createdBy', 'assignedUser'])->find($issueId);
        if (! $issue instanceof QualityDefect) {
            throw new BusinessLogicException(trans_message('design_issues.errors.not_found'), 404);
        }
        $this->access->project($actor, $organizationId, (int) $issue->project_id, $permission);

        return $issue;
    }

    public function assignees(User $actor, int $organizationId, array $filters): array
    {
        $this->access->project($actor, $organizationId, (int) $filters['project_id'], 'design-management.review');
        $page = $this->issues->assignees($actor, $organizationId, (int) $filters['project_id'], $filters);

        return ['items' => $page->getCollection()->map(fn (User $user): array => ['id' => (int) $user->id, 'name' => $user->name])->all(), 'meta' => $this->models->meta($page)];
    }

    public function create(User $actor, int $organizationId, array $payload): array
    {
        $projectId = (int) $payload['project_id'];
        $this->access->project($actor, $organizationId, $projectId, 'design-management.review');
        if (isset($payload['version_id'])) {
            $version = $this->access->version($actor, $organizationId, (int) $payload['version_id']);
            if ((int) $version->project_id !== $projectId) {
                throw new BusinessLogicException(trans_message('design_issues.errors.target_not_found'), 422);
            }
        }
        $this->assertCamera($payload['camera'] ?? null);
        if (isset($payload['bim_element_id']) && ! DesignModelSessionPayloadValidator::elementId($payload['bim_element_id'])) {
            throw new BusinessLogicException(trans_message('design_issues.errors.target_not_found'), 422);
        }
        $context = $this->contexts->resolve($actor, $organizationId, $projectId, $payload);
        $this->assertContext($actor, $organizationId, $projectId, $context);
        $key = $payload['idempotency_key'] ?? null;
        unset($payload['idempotency_key']);

        return $this->run($actor, $organizationId, $key, 'design-management.issue.create', $payload,
            fn (): QualityDefect => $this->issues->create($actor, $organizationId, $projectId, $payload),
            fn (int $id): QualityDefect => $this->find($actor, $organizationId, $id, 'design-management.review'));
    }

    public function act(User $actor, int $organizationId, int $issueId, string $action, array $payload): array
    {
        $permission = $action === 'blocking' ? 'design-management.issues.manage_blocking' : 'design-management.review';
        $issue = $this->find($actor, $organizationId, $issueId, $permission);
        $key = $payload['idempotency_key'] ?? null;
        unset($payload['idempotency_key']);

        return $this->run($actor, $organizationId, $key, 'design-management.issue.'.$action, ['issue_id' => $issueId] + $payload,
            fn (): QualityDefect => $this->revision($issue, (int) $payload['expected_revision'], function (QualityDefect $locked) use ($actor, $action, $payload): QualityDefect {
                return match ($action) {
                    'assign' => $this->issues->assign($locked, $actor, (int) $payload['assignee_id'], $payload['comment'] ?? null),
                    'resolve' => $this->issues->resolve($locked, $actor, $payload['comment'] ?? null),
                    'verify' => $this->issues->verify($locked, $actor, (bool) $payload['accepted'], $payload['comment'] ?? null),
                    'blocking' => $this->issues->setBlocking($locked, $actor, (bool) $payload['active'], $payload['reason'] ?? null),
                    default => throw new BusinessLogicException(trans_message('errors.resource_not_found'), 404),
                };
            }), fn (int $id): QualityDefect => $this->find($actor, $organizationId, $id, $permission));
    }

    public function attachment(User $actor, int $organizationId, int $issueId, string $type, array $payload): array
    {
        $issue = $this->find($actor, $organizationId, $issueId, 'design-management.review');
        $key = $payload['idempotency_key'] ?? null;
        unset($payload['idempotency_key']);
        $operation = 'design-management.issue.'.$type;
        if ($key !== null && preg_match('/^[A-Za-z0-9_-]{16,128}$/', $key) !== 1) {
            throw new BusinessLogicException(trans_message('safety_management.errors.idempotency_key_invalid'), 422);
        }
        $existing = $key === null ? null : DB::table('mobile_mutation_idempotencies')->where('organization_id', $organizationId)
            ->where('user_id', $actor->id)->where('idempotency_key', $key)->first();
        $storedFile = null;
        if ($existing === null) {
            if ((int) $issue->getAttribute('row_version') !== (int) $payload['expected_revision']) {
                throw new BusinessLogicException(trans_message('design_issues.errors.stale_revision'), 409);
            }
            $storedFile = $this->stageAttachment($issue, $payload['file'], $type, $key);
        }

        return $this->run($actor, $organizationId, $key, $operation, ['issue_id' => $issueId] + $payload,
            function () use ($issue, $actor, $organizationId, $payload, $type, $storedFile): QualityDefect {
                $this->access->project($actor, $organizationId, (int) $issue->project_id, 'design-management.review');

                return $this->revision($issue, (int) $payload['expected_revision'], function (QualityDefect $locked) use ($actor, $payload, $type, $storedFile): QualityDefect {
                    if ($storedFile === null) {
                        throw new BusinessLogicException(trans_message('design_issues.errors.snapshot_upload_failed'), 422);
                    }
                    if ($type === 'snapshot') {
                        $metadata = $locked->metadata ?? [];
                        $metadata['design_issue_context']['snapshot'] = ['path' => $storedFile['path'], 'mime_type' => $storedFile['content_type'], 'original_name' => $payload['file']->getClientOriginalName()];
                        $locked->update(['metadata' => $metadata, 'row_version' => (int) $locked->getAttribute('row_version') + 1]);
                    } else {
                        $locked->photos()->create(['organization_id' => $locked->organization_id, 'uploaded_by' => $actor->id,
                            'type' => 'evidence', 'url' => $storedFile['path'], 'caption' => $payload['caption'] ?? null,
                            'mime_type' => $storedFile['content_type'], 'size_bytes' => $storedFile['size'],
                            'storage_sha256' => $storedFile['sha256'], 'storage_etag' => $storedFile['etag'], 'storage_identity_verified' => true]);
                        $locked->update(['row_version' => (int) $locked->getAttribute('row_version') + 1]);
                    }
                    $locked->statusHistory()->create(['organization_id' => $locked->organization_id, 'from_status' => $locked->status,
                        'to_status' => $locked->status, 'changed_by' => $actor->id, 'changed_at' => now(),
                        'comment' => trans_message('mobile_design.history.'.$type),
                        'reporting_dimensions' => ['project_id' => (int) $locked->project_id], 'reporting_evidence_refs' => []]);

                    return $locked->fresh(['photos', 'statusHistory.changedBy']);
                });
            }, fn (int $id): QualityDefect => $this->find($actor, $organizationId, $id, 'design-management.review'));
    }

    public function context(User $actor, int $organizationId, int $issueId): array
    {
        $issue = $this->find($actor, $organizationId, $issueId);
        $this->assertContext($actor, $organizationId, (int) $issue->project_id, (array) (($issue->metadata ?? [])['design_issue_context'] ?? []));

        return $this->issues->bimContext($issue, $actor);
    }

    private function run(User $actor, int $organizationId, ?string $key, string $operation, array $payload, callable $create, callable $find): array
    {
        $replayed = true;
        $result = $this->idempotency->run($organizationId, (int) $actor->id, $key, $operation, $payload,
            function () use ($create, &$replayed): Model {
                $replayed = false;

                return $create();
            }, $find);

        return ['issue' => $result, 'receipt' => ['operation' => $operation, 'resource_id' => (int) $result->getKey(), 'replayed' => $replayed]];
    }

    private function revision(QualityDefect $issue, int $expectedRevision, callable $operation): QualityDefect
    {
        return DB::transaction(function () use ($issue, $expectedRevision, $operation): QualityDefect {
            $locked = QualityDefect::query()->forOrganization((int) $issue->organization_id)->projectIssues()
                ->where('project_id', $issue->project_id)->whereKey($issue->id)->lockForUpdate()->firstOrFail();
            if ((int) $locked->getAttribute('row_version') !== $expectedRevision) {
                throw new BusinessLogicException(trans_message('design_issues.errors.stale_revision'), 409);
            }

            return $operation($locked);
        });
    }

    private function stageAttachment(QualityDefect $issue, UploadedFile $file, string $type, ?string $key): array
    {
        $sha = hash_file('sha256', $file->getPathname());
        if ($sha === false) {
            throw new BusinessLogicException(trans_message('design_issues.errors.snapshot_upload_failed'), 422);
        }
        $token = hash('sha256', ($key ?? (string) \Illuminate\Support\Str::uuid()).':'.$sha);
        $extension = match ($file->getMimeType()) { 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', default => throw new BusinessLogicException(trans_message('design_issues.errors.validation_failed'), 422) };
        $path = "org-{$issue->organization_id}/design-management/issues/{$issue->id}/{$type}/{$token}.{$extension}";
        $stream = fopen($file->getPathname(), 'rb');
        if ($stream === false) {
            throw new BusinessLogicException(trans_message('design_issues.errors.snapshot_upload_failed'), 422);
        }
        try {
            $stored = $this->files->disk(Organization::query()->findOrFail($issue->organization_id))->put($path, $stream, 'private');
        } finally {
            fclose($stream);
        }
        if (! $stored) {
            throw new BusinessLogicException(trans_message('design_issues.errors.snapshot_upload_failed'), 422);
        }

        $descriptor = $this->files->describeCurrent($path, -10 * 1024 * 1024);
        if (! hash_equals($sha, $descriptor['sha256']) || (int) $descriptor['size'] !== (int) $file->getSize()) {
            throw new BusinessLogicException(trans_message('design_issues.errors.snapshot_upload_failed'), 422);
        }

        return $descriptor;
    }

    private function assertCamera(mixed $camera): void
    {
        if ($camera !== null && (! is_array($camera) || ! $this->finiteValues($camera) || strlen(json_encode($camera, JSON_THROW_ON_ERROR)) > 524288)) {
            throw new BusinessLogicException(trans_message('design_issues.errors.target_not_found'), 422);
        }
    }

    private function finiteValues(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (! $this->finiteValues($item)) {
                    return false;
                }
            }

            return true;
        }

        return ! is_float($value) || is_finite($value);
    }

    private function assertContext(User $actor, int $organizationId, int $projectId, array $context): void
    {
        $versionIds = isset($context['view_models']) ? array_column($context['view_models'], 'version_id') : [];
        if (isset($context['model_set_revision_id'])) {
            $revision = DesignModelSetRevision::query()->findOrFail($context['model_set_revision_id']);
            $versionIds = $revision->version_ids ?? [];
        }
        if (isset($context['version_id'])) {
            $versionIds[] = $context['version_id'];
        }
        foreach (array_unique(array_map('intval', $versionIds)) as $versionId) {
            if ((int) $this->access->version($actor, $organizationId, $versionId)->project_id !== $projectId) {
                throw new BusinessLogicException(trans_message('design_issues.errors.target_not_found'), 422);
            }
        }
        $elements = $context['elements'] ?? [];
        if (isset($context['bim_element_id'])) {
            if (! isset($context['version_id'])) {
                throw new BusinessLogicException(trans_message('design_issues.errors.target_not_found'), 422);
            }
            $elements[] = ['version_id' => $context['version_id'], 'element_id' => (int) $context['bim_element_id']];
        }
        foreach ($elements as $element) {
            if (! DesignIfcModelElement::query()->where('organization_id', $organizationId)->where('project_id', $projectId)
                ->where('version_id', $element['version_id'])->where('express_id', $element['element_id'])->exists()) {
                throw new BusinessLogicException(trans_message('design_issues.errors.target_not_found'), 422);
            }
        }
    }
}
