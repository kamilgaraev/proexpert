<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifact;
use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Services\DesignManagementService;
use App\BusinessModules\Features\DesignManagement\Services\DesignWorkflowService;
use App\BusinessModules\Features\DesignManagement\Support\DesignPackageWorkflow;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentVersion;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentationService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Models\User;
use App\Models\Organization;
use App\Modules\Core\AccessController;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class MobilePtoService
{
    public function __construct(
        private readonly DesignManagementService $designManagement,
        private readonly DesignWorkflowService $workflow,
        private readonly AuthorizationService $authorization,
        private readonly AccessController $access,
        private readonly MobileProjectAccessResolver $projectAccess,
        private readonly ExecutiveDocumentationService $executiveDocumentation,
        private readonly \App\Services\Storage\FileService $fileService,
    ) {}

    /** @return array{items: array<int, array<string, mixed>>, meta: array<string, int>} */
    public function designPackages(User $actor, int $organizationId, array $filters): array
    {
        $this->assertModuleAvailable($actor, $organizationId);
        $projectId = (int) $filters['project_id'];
        $this->assertProject($actor, $organizationId, $projectId);
        $this->assertPermission($actor, 'design-management.view', $organizationId, $projectId);

        $paginator = $this->designManagement->listPackages($organizationId, [
            'project_id' => $projectId,
            'status' => $filters['status'] ?? null,
            'per_page' => $filters['per_page'] ?? 20,
            'page' => $filters['page'] ?? 1,
        ]);

        return [
            'items' => collect($paginator->items())
                ->map(fn (DesignPackage $package): array => $this->packageSummary($package, $actor, $organizationId))
                ->values()
                ->all(),
            'meta' => $this->paginationMeta($paginator),
        ];
    }

    /** @return array<string, mixed> */
    public function designPackage(User $actor, int $organizationId, int $packageId): array
    {
        $this->assertModuleAvailable($actor, $organizationId);
        $package = $this->designManagement->findPackage($organizationId, $packageId);
        if (! $package instanceof DesignPackage) {
            throw new BusinessLogicException(trans_message('design_management.errors.package_not_found'), 404);
        }

        $this->assertProject($actor, $organizationId, (int) $package->project_id);
        $this->assertPermission($actor, 'design-management.view', $organizationId, (int) $package->project_id);
        $package->loadMissing(['reviewComments.author:id,name', 'workflowEvents']);

        return [
            'id' => (int) $package->id,
            'project_id' => (int) $package->project_id,
            'project' => $package->project === null ? null : [
                'id' => (int) $package->project->id,
                'name' => (string) $package->project->name,
            ],
            'title' => (string) $package->title,
            'stage' => $package->stage,
            'project_stage' => $this->enumValue($package->project_stage),
            'discipline' => $package->discipline,
            'status' => $this->enumValue($package->status),
            'status_label' => trans_message('design_management.statuses.packages.'.$this->enumValue($package->status)),
            'planned_issue_date' => $package->planned_issue_date?->format('Y-m-d'),
            'issued_at' => $package->issued_at?->toIso8601String(),
            'result' => $this->result($package),
            'files' => $this->files($package, $organizationId),
            'comments' => $this->comments($package->reviewComments),
            'workflow_history' => DesignPackageWorkflow::workflowHistory($package),
            'available_actions' => $this->availableActions($package, $actor, $organizationId),
            'updated_at' => $package->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function actOnDesignPackage(User $actor, int $organizationId, int $packageId, string $action, ?string $comment): array
    {
        $this->assertModuleAvailable($actor, $organizationId);
        $package = $this->designManagement->findPackage($organizationId, $packageId);
        if (! $package instanceof DesignPackage) {
            throw new BusinessLogicException(trans_message('design_management.errors.package_not_found'), 404);
        }
        $projectId = (int) $package->project_id;
        $this->assertProject($actor, $organizationId, $projectId);
        $this->assertPermission($actor, 'design-management.view', $organizationId, $projectId);

        $allowed = $this->availableActions($package, $actor, $organizationId);
        if (! collect($allowed)->contains(static fn (array $item): bool => $item['key'] === $action)) {
            throw new BusinessLogicException(trans_message('design_management.errors.workflow_action_not_available'), 409);
        }

        try {
            $this->workflow->transition($package, (int) $actor->id, $action, $comment);
        } catch (DomainException $exception) {
            throw new BusinessLogicException($exception->getMessage(), 409, $exception);
        }

        return $this->designPackage($actor, $organizationId, $packageId);
    }

    /** @return list<array{key: string, title: string, requires_comment: bool}> */
    public function availableExecutiveDocumentActions(User $actor, int $organizationId, int $documentId): array
    {
        $document = $this->findExecutiveDocument($actor, $organizationId, $documentId);
        if (! $this->authorization->can($actor, 'executive-documentation.view', [
            'organization_id' => $organizationId,
            'project_id' => (int) $document->project_id,
            'strict_project_scope' => true,
        ])) {
            return [];
        }

        return $this->executiveDocumentActions($actor, $organizationId, $document);
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function actOnExecutiveDocument(User $actor, int $organizationId, int $documentId, string $action, array $payload): array
    {
        $document = $this->findExecutiveDocument($actor, $organizationId, $documentId);
        $this->assertModuleAvailableFor($organizationId, 'executive-documentation');
        $allowedActions = $this->executiveDocumentActions($actor, $organizationId, $document);
        if (! collect($allowedActions)->contains(static fn (array $item): bool => $item['key'] === $action)) {
            throw new BusinessLogicException(trans_message('mobile_companions.errors.action_not_available'), 409);
        }

        $userId = (int) $actor->id;
        $comment = isset($payload['comment']) ? (string) $payload['comment'] : null;
        try {
            match ($action) {
                'submit' => $this->executiveDocumentation->submit($document, $userId, $comment, isset($payload['version_id']) ? (int) $payload['version_id'] : null),
                'approve' => $this->executiveDocumentation->approve($document, $userId, $comment, isset($payload['version_id']) ? (int) $payload['version_id'] : null),
                'reject' => $this->executiveDocumentation->reject($document, $userId, (string) $comment, isset($payload['version_id']) ? (int) $payload['version_id'] : null),
                'add_remark' => $this->executiveDocumentation->addRemark($document, $userId, [
                    'body' => (string) $comment,
                    'version_id' => isset($payload['version_id']) ? (int) $payload['version_id'] : null,
                    'severity' => $payload['severity'] ?? 'major',
                ]),
            };
        } catch (DomainException $exception) {
            throw new BusinessLogicException($exception->getMessage(), 409, $exception);
        }

        $updated = $this->findExecutiveDocument($actor, $organizationId, $documentId);

        return $this->executiveDocumentPayload($actor, $organizationId, $updated);
    }

    private function findExecutiveDocument(User $actor, int $organizationId, int $documentId): ExecutiveDocument
    {
        $this->assertModuleAvailableFor($organizationId, 'executive-documentation');
        $document = $this->executiveDocumentation->findDocument($documentId, $organizationId);
        if (! $document instanceof ExecutiveDocument) {
            throw new BusinessLogicException(trans_message('executive_documentation.errors.document_not_found'), 404);
        }
        $this->projectAccess->assert($actor, $organizationId, (int) $document->project_id, trans_message('errors.resource_not_found'));
        $this->assertPermission($actor, 'executive-documentation.view', $organizationId, (int) $document->project_id);

        return $document;
    }

    /** @return list<array{key: string, title: string, requires_comment: bool}> */
    private function executiveDocumentActions(User $actor, int $organizationId, ExecutiveDocument $document): array
    {
        $projectId = (int) $document->project_id;
        $actions = [];
        $status = $document->status instanceof \BackedEnum ? $document->status->value : (string) $document->status;

        if (in_array($status, ['draft', 'remarks'], true) && $this->authorization->can($actor, 'executive-documentation.submit', [
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'strict_project_scope' => true,
        ])) {
            $actions[] = $this->executiveAction('submit', 'submit_executive_document', false);
        }

        if (in_array($status, ['under_review', 'remarks'], true)) {
            if ($this->authorization->can($actor, 'executive-documentation.approve', [
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'strict_project_scope' => true,
            ]) && ! $document->openRemarks()->exists()) {
                $actions[] = $this->executiveAction('approve', 'approve_executive_document', false);
            }
            if ($this->authorization->can($actor, 'executive-documentation.review', [
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'strict_project_scope' => true,
            ])) {
                $actions[] = $this->executiveAction('reject', 'reject_executive_document', true);
                $actions[] = $this->executiveAction('add_remark', 'add_executive_remark', true);
            }
        }

        return $actions;
    }

    private function executiveAction(string $key, string $titleKey, bool $requiresComment): array
    {
        return [
            'key' => $key,
            'title' => trans_message('mobile_companions.actions.'.$titleKey),
            'requires_comment' => $requiresComment,
        ];
    }

    /** @return array<string, mixed> */
    private function executiveDocumentPayload(User $actor, int $organizationId, ExecutiveDocument $document): array
    {
        $document->loadMissing(['remarks.createdBy:id,name', 'documentSet', 'versions']);
        $organization = Organization::query()->find($organizationId);
        $files = $document->versions
            ->filter(static fn (ExecutiveDocumentVersion $version): bool => is_string($version->file_url)
                && str_starts_with($version->file_url, 'org-'.$organizationId.'/'))
            ->map(function (ExecutiveDocumentVersion $version) use ($organization, $document): array {
                $mimeType = match (strtolower(pathinfo((string) $version->file_url, PATHINFO_EXTENSION))) {
                    'pdf' => 'application/pdf',
                    'jpg', 'jpeg' => 'image/jpeg',
                    'png' => 'image/png',
                    default => 'application/octet-stream',
                };
                $path = (string) $version->file_url;

                return [
                    'id' => (int) $version->id,
                    'name' => (string) ($document->title ?: basename($path)),
                    'mime_type' => $mimeType,
                    'preview_url' => in_array($mimeType, ['application/pdf', 'image/jpeg', 'image/png'], true)
                        ? $this->fileService->temporaryUrl($path, 5, $organization, ['ResponseContentDisposition' => 'inline'])
                        : null,
                    'download_url' => $this->fileService->temporaryDownloadUrl($path, 300),
                ];
            })
            ->values()
            ->all();

        return [
            'id' => (int) $document->id,
            'document_set_id' => (int) $document->document_set_id,
            'title' => (string) $document->title,
            'status' => $document->status instanceof \BackedEnum ? $document->status->value : (string) $document->status,
            'result' => [
                'document_date' => $document->document_date?->format('Y-m-d'),
                'approved_at' => $document->approved_at?->toIso8601String(),
                'submitted_at' => $document->submitted_at?->toIso8601String(),
            ],
            'files' => $files,
            'comments' => $document->remarks->map(static fn ($remark): array => [
                'id' => (int) $remark->id,
                'author' => $remark->createdBy?->name,
                'body' => (string) $remark->body,
                'response' => $remark->response,
                'status' => $remark->status instanceof \BackedEnum ? $remark->status->value : (string) $remark->status,
                'created_at' => $remark->created_at?->toIso8601String(),
            ])->values()->all(),
            'available_actions' => $this->executiveDocumentActions($actor, $organizationId, $document),
        ];
    }

    private function assertModuleAvailable(User $actor, int $organizationId): void
    {
        $this->assertModuleAvailableFor($organizationId, 'design-management');
    }

    private function assertModuleAvailableFor(int $organizationId, string $moduleSlug): void
    {
        if ($organizationId < 1 || ! $this->access->hasModuleAccess($organizationId, $moduleSlug)) {
            throw new BusinessLogicException(trans_message('errors.resource_not_found'), 404);
        }
    }

    private function assertProject(User $actor, int $organizationId, int $projectId): void
    {
        $this->projectAccess->assert($actor, $organizationId, $projectId, trans_message('errors.resource_not_found'));
    }

    private function assertPermission(User $actor, string $permission, int $organizationId, int $projectId): void
    {
        if (! $this->authorization->can($actor, $permission, [
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'strict_project_scope' => true,
        ])) {
            throw new BusinessLogicException(trans_message('errors.forbidden'), 403);
        }
    }

    /** @return list<array{key: string, title: string, requires_comment: bool}> */
    private function availableActions(DesignPackage $package, User $actor, int $organizationId): array
    {
        $projectId = (int) $package->project_id;

        return collect(DesignPackageWorkflow::availableActions($package))
            ->filter(function (string $action) use ($actor, $organizationId, $projectId): bool {
                $permission = DesignPackageWorkflow::permissionForAction($action);

                return $permission !== null && $this->authorization->can($actor, $permission, [
                    'organization_id' => $organizationId,
                    'project_id' => $projectId,
                    'strict_project_scope' => true,
                ]);
            })
            ->map(fn (string $action): array => [
                'key' => $action,
                'title' => trans_message('design_management.actions.'.$action),
                'requires_comment' => DesignPackageWorkflow::requiresComment($action),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function packageSummary(DesignPackage $package, User $actor, int $organizationId): array
    {
        return [
            'id' => (int) $package->id,
            'project_id' => (int) $package->project_id,
            'title' => (string) $package->title,
            'stage' => $package->stage,
            'project_stage' => $this->enumValue($package->project_stage),
            'discipline' => $package->discipline,
            'status' => $this->enumValue($package->status),
            'status_label' => trans_message('design_management.statuses.packages.'.$this->enumValue($package->status)),
            'planned_issue_date' => $package->planned_issue_date?->format('Y-m-d'),
            'available_actions' => $this->availableActions($package, $actor, $organizationId),
        ];
    }

    private function result(DesignPackage $package): array
    {
        $check = $package->latestCompletenessCheck;

        return [
            'status' => $this->enumValue($package->status),
            'composition_status' => $package->composition_status,
            'open_blocking_comments_count' => (int) ($package->open_blocking_comments_count ?? 0),
            'latest_completeness_check' => $check === null ? null : [
                'id' => (int) $check->id,
                'status' => $this->enumValue($check->status),
                'blocking_count' => (int) $check->blocking_count,
                'warning_count' => (int) $check->warning_count,
                'checked_at' => $check->checked_at?->toIso8601String(),
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function files(DesignPackage $package, int $organizationId): array
    {
        $files = [];

        foreach ($package->artifacts as $artifact) {
            if (! $artifact instanceof DesignArtifact) {
                continue;
            }
            $version = $artifact->currentVersion;
            if (! $version instanceof DesignArtifactVersion || ! str_starts_with((string) $version->source_file_path, 'org-'.$organizationId.'/')) {
                continue;
            }

            $source = $this->designManagement->viewerPayload($version)['source'];
            $downloadUrl = $source['download_url'] ?? null;
            $mimeType = (string) ($version->source_mime_type ?? 'application/octet-stream');
            $files[] = [
                'id' => (int) $version->id,
                'artifact_id' => (int) $artifact->id,
                'name' => (string) ($version->source_original_name ?: $version->title ?: $artifact->title),
                'mime_type' => $mimeType,
                'preview_url' => in_array($mimeType, ['application/pdf', 'image/jpeg', 'image/png'], true) ? $downloadUrl : null,
                'download_url' => $downloadUrl,
            ];
        }

        return $files;
    }

    /** @return list<array<string, mixed>> */
    private function comments(Collection $comments): array
    {
        return $comments->map(static fn ($comment): array => [
            'id' => (int) $comment->id,
            'author' => $comment->author?->name,
            'body' => (string) $comment->body,
            'status' => $comment->status instanceof \BackedEnum ? $comment->status->value : (string) $comment->status,
            'created_at' => $comment->created_at?->toIso8601String(),
        ])->values()->all();
    }

    private function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
        ];
    }

    private function enumValue(mixed $value): string
    {
        return $value instanceof \BackedEnum ? $value->value : (string) $value;
    }
}
