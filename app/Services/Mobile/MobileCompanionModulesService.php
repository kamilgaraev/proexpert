<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeProfile;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeRequest;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Models\ChangeWorkflowEvent;
use App\BusinessModules\Features\ChangeManagement\Services\ChangeManagementService;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\VideoMonitoring\Models\VideoCamera;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\Material;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Mobile\MobileLegalArchiveService;
use App\Services\Storage\FileService;
use BackedEnum;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class MobileCompanionModulesService
{
    private const MODULES = [
        'contract-management' => [
            'icon' => 'contract',
            'route' => 'contract-management',
            'access_slug' => 'contract-management',
            'view_permissions' => ['contracts.view', 'contract-management.view'],
            'status_column' => 'status',
            'statuses' => ['draft', 'active', 'completed', 'on_hold', 'terminated'],
        ],
        'change-management' => [
            'icon' => 'change',
            'route' => 'change-management',
            'access_slug' => 'change-management',
            'view_permissions' => ['change-management.view'],
            'status_column' => 'status',
            'statuses' => [
                'draft',
                'submitted',
                'impact_assessment',
                'internal_review',
                'customer_review',
                'approved',
                'implemented',
                'closed',
                'rejected',
                'cancelled',
            ],
        ],
        'executive-documentation' => [
            'icon' => 'documents',
            'route' => 'executive-documentation',
            'access_slug' => 'executive-documentation',
            'view_permissions' => ['executive-documentation.view'],
            'status_column' => 'status',
            'statuses' => ['draft', 'prepared', 'under_review', 'remarks', 'approved', 'rejected', 'transmitted', 'archived'],
        ],
        'project-management' => [
            'icon' => 'project',
            'route' => 'project-management',
            'access_slug' => 'project-management',
            'view_permissions' => ['projects.view', 'projects.view_assigned'],
            'status_column' => 'status',
            'statuses' => ['active', 'planned', 'completed', 'paused', 'archived'],
        ],
        'catalog-management' => [
            'icon' => 'catalog',
            'route' => 'catalog-management',
            'access_slug' => 'catalog-management',
            'view_permissions' => ['materials.view', 'catalog-management.view'],
            'status_column' => 'is_active',
            'statuses' => ['active', 'inactive'],
        ],
        'brigades' => [
            'icon' => 'brigades',
            'route' => 'brigades',
            'access_slug' => 'brigades',
            'view_permissions' => ['brigades.view', 'brigades.catalog.view', 'brigades.assignments.view'],
            'status_column' => 'verification_status',
            'statuses' => ['draft', 'pending', 'approved', 'rejected'],
        ],
        'video-monitoring' => [
            'icon' => 'video',
            'route' => 'video-monitoring',
            'access_slug' => 'video-monitoring',
            'view_permissions' => ['video-monitoring.view', 'video-monitoring.watch_live'],
            'status_column' => 'status',
            'statuses' => ['online', 'offline', 'unknown', 'error'],
        ],
    ];

    public function __construct(
        private readonly AuthorizationService $authorizationService,
        private readonly AccessController $accessController,
        private readonly ChangeManagementService $changeManagementService,
        private readonly MobileProjectAccessResolver $projectAccess,
        private readonly MobileLegalArchiveService $legalArchive,
        private readonly MobilePtoService $ptoService,
        private readonly FileService $fileService,
    ) {}

    public function canView(string $slug, User $user, int $organizationId): bool
    {
        $config = $this->moduleConfig($slug);

        if (! $this->accessController->hasModuleAccess($organizationId, $config['access_slug'])) {
            return false;
        }

        return $this->hasAnyPermission($user, $organizationId, $config['view_permissions']);
    }

    public function list(string $slug, User $user, int $organizationId, array $filters, int $perPage): array
    {
        $config = $this->moduleConfig($slug);
        $query = $this->queryFor($slug, $user, $organizationId);

        if (($filters['project_id'] ?? null) !== null && ($filters['project_id'] ?? '') !== '') {
            $this->projectAccess->assert(
                $user,
                $organizationId,
                (int) $filters['project_id'],
                trans_message('mobile_companions.errors.item_not_found'),
            );
        }

        $this->applyProjectFilter($query, $slug, $filters['project_id'] ?? null);
        $this->applySearch($query, $slug, $filters['q'] ?? null);
        $this->applyStatus($query, $config, $filters['status'] ?? null);

        $paginator = $query
            ->orderByDesc($this->sortColumn($slug))
            ->orderByDesc('id')
            ->paginate($perPage);

        return [
            'module' => $this->modulePayload($slug, $config),
            'items' => collect($paginator->items())
                ->map(fn (Model $model): array => $this->listItem($slug, $model, $user, $organizationId))
                ->values()
                ->all(),
            'filters' => [
                'statuses' => $this->statusFilterPayload($config['statuses']),
            ],
            'empty_state' => $this->emptyState($slug),
            'permission_state' => $this->permissionState(),
            'meta' => $this->meta($paginator),
        ];
    }

    public function detail(string $slug, int $id, User $user, int $organizationId): array
    {
        $config = $this->moduleConfig($slug);
        $model = $this->findModel($slug, $user, $organizationId, $id);
        $contractArchive = $slug === 'contract-management'
            ? $this->contractLegalArchive($model, $user, $organizationId)
            : null;

        return [
            'module' => $this->modulePayload($slug, $config),
            'item' => $this->listItem($slug, $model, $user, $organizationId),
            'sections' => $this->detailSections($slug, $model),
            'related_items' => $this->relatedItems($slug, $model, $user, $organizationId),
            'files' => $slug === 'contract-management'
                ? ($contractArchive['files'] ?? [])
                : $this->detailFiles($slug, $model, $user, $organizationId),
            'result' => $this->detailResult($slug, $model, $contractArchive),
            'comments' => $this->detailComments($slug, $model),
            'workflow_history' => $this->workflowHistory($slug, $model),
            'workflow' => $contractArchive['workflow_summary'] ?? null,
            'empty_state' => $this->emptyState($slug),
            'permission_state' => $this->permissionState(),
        ];
    }

    public function executeAction(
        string $slug,
        int $id,
        string $action,
        User $user,
        int $organizationId,
        array $payload
    ): array {
        if ($slug === 'change-management' && $action === 'submit') {
            $change = $this->findModel($slug, $user, $organizationId, $id);

            if (! $change instanceof ChangeRequest) {
                throw new DomainException(trans_message('mobile_companions.errors.item_not_found'));
            }
            $this->ensureModelActionPermission($user, $organizationId, $change, ['change-management.create', 'change-management.edit']);

            $this->changeManagementService->submitChange($change);

            return $this->detail($slug, $id, $user, $organizationId);
        }

        if ($slug === 'change-management' && in_array($action, ['start_internal_review', 'start_customer_review', 'implement', 'close'], true)) {
            $permissions = match ($action) {
                'start_internal_review', 'implement' => ['change-management.edit'],
                'start_customer_review', 'close' => ['change-management.change-orders.approve'],
            };
            $change = $this->findModel($slug, $user, $organizationId, $id);
            if (! $change instanceof ChangeRequest) {
                throw new DomainException(trans_message('mobile_companions.errors.item_not_found'));
            }
            $this->ensureModelActionPermission($user, $organizationId, $change, $permissions);
            match ($action) {
                'start_internal_review' => $this->changeManagementService->startInternalReview($change),
                'start_customer_review' => $this->changeManagementService->startCustomerReview($change),
                'implement' => $this->changeManagementService->implementChange($change, $payload['comment'] ?? null),
                'close' => $this->changeManagementService->closeChange($change),
            };

            return $this->detail($slug, $id, $user, $organizationId);
        }

        throw new DomainException(trans_message('mobile_companions.errors.action_not_available'));
    }

    private function moduleConfig(string $slug): array
    {
        $config = self::MODULES[$slug] ?? null;

        if (! is_array($config)) {
            throw new DomainException(trans_message('mobile_companions.errors.module_not_found'));
        }

        return $config;
    }

    private function modulePayload(string $slug, array $config): array
    {
        return [
            'slug' => $slug,
            'title' => trans_message("mobile_modules.modules.{$slug}.title"),
            'description' => trans_message("mobile_modules.modules.{$slug}.description"),
            'icon' => $config['icon'],
            'route' => $config['route'],
        ];
    }

    private function queryFor(string $slug, User $user, int $organizationId): Builder
    {
        $projectIds = $this->projectAccess
            ->query($user, $organizationId)
            ->pluck('projects.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return match ($slug) {
            'contract-management' => Contract::query()
                ->forOrganization($organizationId)
                ->where(function (Builder $query) use ($projectIds): void {
                    $query->whereIn('project_id', $projectIds)
                        ->orWhere(function (Builder $withoutPrimaryProject) use ($projectIds): void {
                            $withoutPrimaryProject
                                ->whereNull('project_id')
                                ->where(function (Builder $projectsQuery) use ($projectIds): void {
                                    $projectsQuery
                                        ->whereHas('projects', fn (Builder $projectQuery) => $projectQuery->whereIn('projects.id', $projectIds))
                                        ->orWhereDoesntHave('projects');
                                });
                        });
                })
                ->with(['project', 'contractor', 'supplier'])
                ->withCount(['performanceActs', 'payments']),
            'change-management' => ChangeRequest::query()
                ->forOrganization($organizationId)
                ->whereIn('project_id', $projectIds)
                ->with(['project', 'impact', 'approvals', 'variationOrders']),
            'executive-documentation' => ExecutiveDocumentSet::query()
                ->forOrganization($organizationId)
                ->whereIn('project_id', $projectIds)
                ->with(['project', 'documents.remarks.createdBy:id,name', 'transmittal'])
                ->withCount(['documents']),
            'project-management' => Project::query()
                ->whereIn('projects.id', $projectIds)
                ->withCount(['users', 'contracts', 'completedWorks']),
            'catalog-management' => Material::query()
                ->where('organization_id', $organizationId)
                ->with(['measurementUnit']),
            'brigades' => BrigadeProfile::query()
                ->with([
                    'members',
                    'assignments' => fn (Builder $assignmentQuery) => $assignmentQuery->whereIn('project_id', $projectIds),
                    'assignments.project',
                    'specializations',
                ])
                ->withCount([
                    'members',
                    'assignments' => fn (Builder $assignmentQuery) => $assignmentQuery->whereIn('project_id', $projectIds),
                ])
                ->whereHas('assignments', fn (Builder $assignmentQuery) => $assignmentQuery->whereIn('project_id', $projectIds))
                ->where(function (Builder $query) use ($organizationId): void {
                    $query->where('organization_id', $organizationId)
                        ->orWhereHas('assignments', static function (Builder $assignmentQuery) use ($organizationId): void {
                            $assignmentQuery->where('contractor_organization_id', $organizationId);
                        });
                }),
            'video-monitoring' => VideoCamera::query()
                ->where('organization_id', $organizationId)
                ->whereIn('project_id', $projectIds)
                ->with(['project'])
                ->withCount(['events']),
            default => throw new DomainException(trans_message('mobile_companions.errors.module_not_found')),
        };
    }

    private function findModel(string $slug, User $user, int $organizationId, int $id): Model
    {
        $model = $this->queryFor($slug, $user, $organizationId)->whereKey($id)->first();

        if (! $model instanceof Model) {
            throw new DomainException(trans_message('mobile_companions.errors.item_not_found'));
        }

        return $model;
    }

    private function applyProjectFilter(Builder $query, string $slug, mixed $projectId): void
    {
        if ($projectId === null || $projectId === '') {
            return;
        }

        $projectId = (int) $projectId;

        match ($slug) {
            'contract-management' => $query->where(function (Builder $scope) use ($projectId): void {
                $scope->where('project_id', $projectId)
                    ->orWhereHas('projects', static function (Builder $projectQuery) use ($projectId): void {
                        $projectQuery->where('projects.id', $projectId);
                    });
            }),
            'change-management',
            'executive-documentation',
            'video-monitoring' => $query->where('project_id', $projectId),
            'project-management' => $query->whereKey($projectId),
            'brigades' => $query->whereHas('assignments', static function (Builder $assignmentQuery) use ($projectId): void {
                $assignmentQuery->where('project_id', $projectId);
            }),
            default => null,
        };
    }

    private function applySearch(Builder $query, string $slug, mixed $search): void
    {
        $search = trim((string) ($search ?? ''));

        if ($search === '') {
            return;
        }

        match ($slug) {
            'contract-management' => $query->where(function (Builder $scope) use ($search): void {
                $scope->where('number', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhereHas('project', static fn (Builder $projectQuery) => $projectQuery->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('contractor', static fn (Builder $contractorQuery) => $contractorQuery->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('supplier', static fn (Builder $supplierQuery) => $supplierQuery->where('name', 'like', "%{$search}%"));
            }),
            'change-management' => $query->where(function (Builder $scope) use ($search): void {
                $scope->where('change_number', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('reason', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('project', static fn (Builder $projectQuery) => $projectQuery->where('name', 'like', "%{$search}%"));
            }),
            'executive-documentation' => $query->where(function (Builder $scope) use ($search): void {
                $scope->where('set_number', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('stage_name', 'like', "%{$search}%")
                    ->orWhere('zone_name', 'like', "%{$search}%")
                    ->orWhereHas('project', static fn (Builder $projectQuery) => $projectQuery->where('name', 'like', "%{$search}%"));
            }),
            'project-management' => $query->where(function (Builder $scope) use ($search): void {
                $scope->where('name', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%")
                    ->orWhere('customer', 'like', "%{$search}%")
                    ->orWhere('designer', 'like', "%{$search}%")
                    ->orWhere('external_code', 'like', "%{$search}%");
            }),
            'catalog-management' => $query->where(function (Builder $scope) use ($search): void {
                $scope->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%");
            }),
            'brigades' => $query->where(function (Builder $scope) use ($search): void {
                $scope->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%")
                    ->orWhere('contact_phone', 'like', "%{$search}%")
                    ->orWhere('contact_email', 'like', "%{$search}%");
            }),
            'video-monitoring' => $query->where(function (Builder $scope) use ($search): void {
                $scope->where('name', 'like', "%{$search}%")
                    ->orWhere('zone', 'like', "%{$search}%")
                    ->orWhere('status_message', 'like', "%{$search}%")
                    ->orWhereHas('project', static fn (Builder $projectQuery) => $projectQuery->where('name', 'like', "%{$search}%"));
            }),
            default => null,
        };
    }

    private function applyStatus(Builder $query, array $config, mixed $status): void
    {
        $status = trim((string) ($status ?? ''));

        if ($status === '') {
            return;
        }

        if (! in_array($status, $config['statuses'], true)) {
            throw new DomainException(trans_message('mobile_companions.errors.status_not_supported'));
        }

        if ($config['status_column'] === 'is_active') {
            $query->where('is_active', $status === 'active');

            return;
        }

        $query->where($config['status_column'], $status);
    }

    private function sortColumn(string $slug): string
    {
        return match ($slug) {
            'executive-documentation' => 'updated_at',
            default => 'updated_at',
        };
    }

    private function listItem(string $slug, Model $model, User $user, int $organizationId): array
    {
        return match ($slug) {
            'contract-management' => $this->contractItem($model, $user, $organizationId),
            'change-management' => $this->changeItem($model, $user, $organizationId),
            'executive-documentation' => $this->executiveDocumentSetItem($model, $user, $organizationId),
            'project-management' => $this->projectItem($model, $user, $organizationId),
            'catalog-management' => $this->materialItem($model, $user, $organizationId),
            'brigades' => $this->brigadeItem($model, $user, $organizationId),
            'video-monitoring' => $this->videoCameraItem($model, $user, $organizationId),
            default => throw new DomainException(trans_message('mobile_companions.errors.module_not_found')),
        };
    }

    private function contractItem(Model $model, User $user, int $organizationId): array
    {
        /** @var Contract $contract */
        $contract = $model;
        $status = $this->statusValue($contract->status);
        $partyName = $contract->contractor?->name ?? $contract->supplier?->name;

        return $this->itemPayload(
            $contract,
            $this->firstFilled([$contract->subject, $contract->number, (string) $contract->id]),
            $this->firstFilled([$partyName, $contract->project?->name]),
            $status,
            $contract->project?->name,
            'amount',
            $this->moneyValue($contract->total_amount),
            'performance_acts',
            (string) $contract->performance_acts_count,
            []
        );
    }

    private function changeItem(Model $model, User $user, int $organizationId): array
    {
        /** @var ChangeRequest $change */
        $change = $model;
        $status = $this->statusValue($change->status);

        return $this->itemPayload(
            $change,
            $change->title,
            $change->project?->name,
            $status,
            $change->project?->name,
            'number',
            $change->change_number,
            'schedule_delta_days',
            $this->stringValue($change->impact?->schedule_delta_days),
            $this->availableActions('change-management', $change, $user, $organizationId)
        );
    }

    private function executiveDocumentSetItem(Model $model, User $user, int $organizationId): array
    {
        /** @var ExecutiveDocumentSet $set */
        $set = $model;
        $status = $this->statusValue($set->status);

        return $this->itemPayload(
            $set,
            $set->title,
            $this->firstFilled([$set->stage_name, $set->project?->name]),
            $status,
            $set->project?->name,
            'documents',
            (string) $set->documents_count,
            'zone',
            $set->zone_name,
            $this->availableActions('executive-documentation', $set, $user, $organizationId)
        );
    }

    private function projectItem(Model $model, User $user, int $organizationId): array
    {
        /** @var Project $project */
        $project = $model;
        $status = $this->statusValue($project->status);

        return $this->itemPayload(
            $project,
            $project->name,
            $project->address,
            $status,
            $project->name,
            'budget',
            $this->moneyValue($project->budget_amount),
            'contracts',
            (string) $project->contracts_count,
            []
        );
    }

    private function materialItem(Model $model, User $user, int $organizationId): array
    {
        /** @var Material $material */
        $material = $model;
        $status = $material->is_active ? 'active' : 'inactive';

        return $this->itemPayload(
            $material,
            $material->name,
            $this->firstFilled([$material->category, $material->description]),
            $status,
            null,
            'code',
            $material->code,
            'unit',
            $material->measurementUnit?->name,
            []
        );
    }

    private function brigadeItem(Model $model, User $user, int $organizationId): array
    {
        /** @var BrigadeProfile $brigade */
        $brigade = $model;
        $status = $this->statusValue($brigade->verification_status);

        return $this->itemPayload(
            $brigade,
            $brigade->name,
            $this->firstFilled([$brigade->contact_person, $brigade->description]),
            $status,
            null,
            'team_size',
            (string) $brigade->team_size,
            'assignments',
            (string) $brigade->assignments_count,
            []
        );
    }

    private function videoCameraItem(Model $model, User $user, int $organizationId): array
    {
        /** @var VideoCamera $camera */
        $camera = $model;
        $status = $this->statusValue($camera->status);

        return $this->itemPayload(
            $camera,
            $camera->name,
            $this->firstFilled([$camera->zone, $camera->project?->name]),
            $status,
            $camera->project?->name,
            'zone',
            $camera->zone,
            'last_online_at',
            $this->dateTimeValue($camera->last_online_at),
            []
        );
    }

    private function itemPayload(
        Model $model,
        ?string $title,
        ?string $subtitle,
        ?string $status,
        ?string $projectName,
        string $primaryLabelKey,
        ?string $primaryValue,
        string $secondaryLabelKey,
        ?string $secondaryValue,
        array $actions
    ): array {
        return [
            'id' => (int) $model->getKey(),
            'title' => $this->firstFilled([$title, (string) $model->getKey()]),
            'subtitle' => $subtitle,
            'status' => $status,
            'status_label' => $status !== null ? $this->statusLabel($status) : null,
            'status_tone' => $this->statusTone($status),
            'project_name' => $projectName,
            'primary_label' => trans_message("mobile_companions.fields.{$primaryLabelKey}"),
            'primary_value' => $primaryValue,
            'secondary_label' => trans_message("mobile_companions.fields.{$secondaryLabelKey}"),
            'secondary_value' => $secondaryValue,
            'updated_at' => $this->dateTimeValue($model->getAttribute('updated_at')),
            'available_actions' => $actions,
        ];
    }

    private function detailSections(string $slug, Model $model): array
    {
        $sections = match ($slug) {
            'contract-management' => $this->contractSections($model),
            'change-management' => $this->changeSections($model),
            'executive-documentation' => $this->executiveDocumentSetSections($model),
            'project-management' => $this->projectSections($model),
            'catalog-management' => $this->materialSections($model),
            'brigades' => $this->brigadeSections($model),
            'video-monitoring' => $this->videoCameraSections($model),
            default => [],
        };

        return array_values(array_filter($sections));
    }

    private function contractSections(Model $model): array
    {
        /** @var Contract $contract */
        $contract = $model;

        return [
            $this->section('main', [
                $this->row('number', $contract->number),
                $this->row('subject', $contract->subject),
                $this->row('status', $this->statusLabel($this->statusValue($contract->status))),
                $this->row('project', $contract->project?->name),
                $this->row('contractor', $contract->contractor?->name),
                $this->row('supplier', $contract->supplier?->name),
            ]),
            $this->section('dates', [
                $this->row('date', $this->dateValue($contract->date)),
                $this->row('start_date', $this->dateValue($contract->start_date)),
                $this->row('end_date', $this->dateValue($contract->end_date)),
            ]),
            $this->section('finance', [
                $this->row('base_amount', $this->moneyValue($contract->base_amount)),
                $this->row('amount', $this->moneyValue($contract->total_amount)),
                $this->row('paid_amount', $this->moneyValue($contract->total_paid_amount)),
                $this->row('remaining_amount', $this->moneyValue($contract->remaining_amount)),
            ]),
        ];
    }

    private function changeSections(Model $model): array
    {
        /** @var ChangeRequest $change */
        $change = $model;

        return [
            $this->section('main', [
                $this->row('number', $change->change_number),
                $this->row('title', $change->title),
                $this->row('status', $this->statusLabel($this->statusValue($change->status))),
                $this->row('project', $change->project?->name),
                $this->row('reason', $change->reason),
                $this->row('description', $change->description),
            ]),
            $this->section('impact', [
                $this->row('cost_delta', $this->moneyValue($change->impact?->cost_delta)),
                $this->row('schedule_delta_days', $this->stringValue($change->impact?->schedule_delta_days)),
                $this->row('requires_contract_change', $this->boolValue($change->impact?->requires_contract_change)),
                $this->row('requires_estimate_revision', $this->boolValue($change->impact?->requires_estimate_revision)),
                $this->row('requires_customer_approval', $this->boolValue($change->impact?->requires_customer_approval)),
            ]),
        ];
    }

    private function executiveDocumentSetSections(Model $model): array
    {
        /** @var ExecutiveDocumentSet $set */
        $set = $model;

        return [
            $this->section('main', [
                $this->row('number', $set->set_number),
                $this->row('title', $set->title),
                $this->row('status', $this->statusLabel($this->statusValue($set->status))),
                $this->row('project', $set->project?->name),
                $this->row('stage', $set->stage_name),
                $this->row('zone', $set->zone_name),
                $this->row('planned_transmittal_date', $this->dateValue($set->planned_transmittal_date)),
                $this->row('transmitted_at', $this->dateTimeValue($set->transmitted_at)),
            ]),
            $this->section('documents', [
                $this->row('documents', (string) $set->documents->count()),
                $this->row('open_remarks', (string) $set->documents->sum(fn ($document): int => $document->remarks->where('status', 'open')->count())),
                $this->row('acknowledged_at', $this->dateTimeValue($set->transmittal?->acknowledged_at)),
            ]),
        ];
    }

    private function projectSections(Model $model): array
    {
        /** @var Project $project */
        $project = $model;

        return [
            $this->section('main', [
                $this->row('title', $project->name),
                $this->row('status', $this->statusLabel($this->statusValue($project->status))),
                $this->row('address', $project->address),
                $this->row('customer', $project->customer),
                $this->row('designer', $project->designer),
            ]),
            $this->section('dates', [
                $this->row('start_date', $this->dateValue($project->start_date)),
                $this->row('end_date', $this->dateValue($project->end_date)),
                $this->row('contract_number', $project->contract_number),
                $this->row('contract_date', $this->dateValue($project->contract_date)),
            ]),
            $this->section('scope', [
                $this->row('budget', $this->moneyValue($project->budget_amount)),
                $this->row('site_area', $this->stringValue($project->site_area_m2)),
                $this->row('contracts', (string) $project->contracts_count),
                $this->row('completed_works', (string) $project->completed_works_count),
            ]),
        ];
    }

    private function materialSections(Model $model): array
    {
        /** @var Material $material */
        $material = $model;

        return [
            $this->section('main', [
                $this->row('title', $material->name),
                $this->row('code', $material->code),
                $this->row('status', $this->statusLabel($material->is_active ? 'active' : 'inactive')),
                $this->row('category', $material->category),
                $this->row('unit', $material->measurementUnit?->name),
                $this->row('description', $material->description),
            ]),
            $this->section('finance', [
                $this->row('default_price', $this->moneyValue($material->default_price)),
            ]),
        ];
    }

    private function brigadeSections(Model $model): array
    {
        /** @var BrigadeProfile $brigade */
        $brigade = $model;

        return [
            $this->section('main', [
                $this->row('title', $brigade->name),
                $this->row('status', $this->statusLabel($this->statusValue($brigade->verification_status))),
                $this->row('availability_status', $this->statusLabel($this->statusValue($brigade->availability_status))),
                $this->row('team_size', (string) $brigade->team_size),
                $this->row('description', $brigade->description),
            ]),
            $this->section('contact', [
                $this->row('contact_person', $brigade->contact_person),
                $this->row('phone', $brigade->contact_phone),
                $this->row('email', $brigade->contact_email),
            ]),
            $this->section('performance', [
                $this->row('rating', $this->stringValue($brigade->rating)),
                $this->row('completed_projects_count', (string) $brigade->completed_projects_count),
                $this->row('members', (string) $brigade->members->count()),
                $this->row('assignments', (string) $brigade->assignments->count()),
            ]),
        ];
    }

    private function videoCameraSections(Model $model): array
    {
        /** @var VideoCamera $camera */
        $camera = $model;

        return [
            $this->section('main', [
                $this->row('title', $camera->name),
                $this->row('status', $this->statusLabel($this->statusValue($camera->status))),
                $this->row('project', $camera->project?->name),
                $this->row('zone', $camera->zone),
                $this->row('enabled', $this->boolValue($camera->is_enabled)),
                $this->row('status_message', $camera->status_message),
            ]),
            $this->section('dates', [
                $this->row('last_checked_at', $this->dateTimeValue($camera->last_checked_at)),
                $this->row('last_online_at', $this->dateTimeValue($camera->last_online_at)),
            ]),
        ];
    }

    private function relatedItems(string $slug, Model $model, User $user, int $organizationId): array
    {
        return match ($slug) {
            'executive-documentation' => $this->executiveRelatedItems($model, $user, $organizationId),
            'brigades' => $this->brigadeRelatedItems($model),
            default => [],
        };
    }

    private function detailFiles(string $slug, Model $model, User $user, int $organizationId): array
    {
        if ($slug !== 'executive-documentation' || ! $model instanceof ExecutiveDocumentSet) {
            return [];
        }

        $organization = Organization::query()->find($organizationId);

        return $model->documents
            ->flatMap(function (ExecutiveDocument $document) use ($organizationId, $organization): array {
                $version = $document->versions->first();
                $path = $version?->file_url;
                if ($version === null || ! is_string($path) || ! str_starts_with($path, 'org-'.$organizationId.'/')) {
                    return [];
                }

                $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                $mimeType = match ($extension) {
                    'pdf' => 'application/pdf',
                    'jpg', 'jpeg' => 'image/jpeg',
                    'png' => 'image/png',
                    default => 'application/octet-stream',
                };
                $previewUrl = in_array($mimeType, ['application/pdf', 'image/jpeg', 'image/png'], true)
                    ? $this->fileService->temporaryUrl($path, 5, $organization, ['ResponseContentDisposition' => 'inline'])
                    : null;

                return [[
                    'id' => (int) $version->id,
                    'record_id' => (int) $document->id,
                    'name' => (string) ($document->title ?: basename($path)),
                    'mime_type' => $mimeType,
                    'preview_url' => $previewUrl,
                    'download_url' => $this->fileService->temporaryDownloadUrl($path, 300),
                ]];
            })
            ->values()
            ->all();
    }

    private function contractLegalArchive(Model $model, User $user, int $organizationId): ?array
    {
        if (! $model instanceof Contract || $model->legal_archive_document_id === null) {
            return null;
        }

        try {
            $document = $this->legalArchive->document($user, $organizationId, (int) $model->legal_archive_document_id);
            $workflow = $this->legalArchive->summary($user, $document);
        } catch (\Illuminate\Auth\Access\AuthorizationException|DomainException) {
            return null;
        }

        return [
            'document_id' => (int) $document->id,
            'title' => (string) $document->title,
            'status' => (string) $document->status,
            'workflow_summary' => $workflow,
            'files' => $document->versions->map(fn ($version): array => [
                'id' => (int) $version->id,
                'name' => (string) $version->original_filename,
                'mime_type' => $version->mime_type,
                'preview_url' => "/api/v1/mobile/legal-archive/documents/{$document->id}/versions/{$version->id}/preview",
                'download_url' => "/api/v1/mobile/legal-archive/documents/{$document->id}/versions/{$version->id}/download",
            ])->values()->all(),
        ];
    }

    private function detailResult(string $slug, Model $model, ?array $contractArchive = null): ?array
    {
        if ($slug === 'contract-management' && $model instanceof Contract) {
            return [
                'status' => $this->statusValue($model->status),
                'amount' => $this->moneyValue($model->total_amount),
                'legal_archive_document_id' => $model->legal_archive_document_id === null ? null : (int) $model->legal_archive_document_id,
                'legal_archive' => $contractArchive === null ? null : [
                    'document_id' => $contractArchive['document_id'],
                    'title' => $contractArchive['title'],
                    'status' => $contractArchive['status'],
                ],
            ];
        }

        if ($slug === 'change-management' && $model instanceof ChangeRequest) {
            return [
                'status' => $this->statusValue($model->status),
                'implementation_comment' => $model->implementation_comment,
                'impact' => $model->impact === null ? null : [
                    'cost_delta' => $this->moneyValue($model->impact->cost_delta),
                    'schedule_delta_days' => $model->impact->schedule_delta_days,
                    'requires_customer_approval' => (bool) $model->impact->requires_customer_approval,
                ],
            ];
        }

        if ($slug === 'executive-documentation' && $model instanceof ExecutiveDocumentSet) {
            return [
                'status' => $this->statusValue($model->status),
                'transmitted_at' => $this->dateTimeValue($model->transmitted_at),
                'transmittal' => $model->transmittal === null ? null : [
                    'id' => (int) $model->transmittal->id,
                    'number' => $model->transmittal->transmittal_number,
                    'status' => $model->transmittal->status,
                    'comment' => $model->transmittal->comment,
                    'acknowledged_at' => $this->dateTimeValue($model->transmittal->acknowledged_at),
                    'decision_comment' => $model->transmittal->decision_comment,
                ],
            ];
        }

        return null;
    }

    private function detailComments(string $slug, Model $model): array
    {
        if ($slug === 'change-management' && $model instanceof ChangeRequest) {
            return $model->approvals
                ->filter(static fn ($approval): bool => is_string($approval->comment) && trim($approval->comment) !== '')
                ->map(fn ($approval): array => [
                    'id' => (int) $approval->id,
                    'author' => null,
                    'body' => (string) $approval->comment,
                    'status' => (string) $approval->status,
                    'created_at' => $approval->decided_at?->toIso8601String(),
                ])->values()->all();
        }

        if ($slug === 'executive-documentation' && $model instanceof ExecutiveDocumentSet) {
            return $model->documents->flatMap(static fn (ExecutiveDocument $document): array => $document->remarks
                ->map(static fn ($remark): array => [
                    'id' => (int) $remark->id,
                    'record_id' => (int) $document->id,
                    'author' => $remark->createdBy?->name,
                    'body' => (string) $remark->body,
                    'status' => $remark->status instanceof BackedEnum ? $remark->status->value : (string) $remark->status,
                    'response' => $remark->response,
                    'created_at' => $remark->created_at?->toIso8601String(),
                ])
            )->values()->all();
        }

        return [];
    }

    private function workflowHistory(string $slug, Model $model): array
    {
        if ($slug === 'change-management' && $model instanceof ChangeRequest) {
            return ChangeWorkflowEvent::query()
                ->where('organization_id', $model->organization_id)
                ->where('change_request_id', $model->id)
                ->orderBy('version')
                ->get(['id', 'event_type', 'prior_status', 'current_status', 'actor_id', 'occurred_at'])
                ->map(static fn (ChangeWorkflowEvent $event): array => [
                    'id' => (int) $event->id,
                    'action' => $event->event_type,
                    'from_status' => $event->prior_status,
                    'to_status' => $event->current_status,
                    'user_id' => $event->actor_id === null ? null : (int) $event->actor_id,
                    'at' => $event->occurred_at?->toIso8601String(),
                ])->all();
        }

        if ($slug === 'executive-documentation' && $model instanceof ExecutiveDocumentSet) {
            return $model->transmittal === null ? [] : [[
                'action' => 'transmit',
                'status' => $model->transmittal->status,
                'comment' => $model->transmittal->comment,
                'at' => $model->transmittal->transmitted_at?->toIso8601String(),
                'acknowledgement_comment' => $model->transmittal->acknowledgement_comment,
                'acknowledged_at' => $model->transmittal->acknowledged_at?->toIso8601String(),
                'decision_comment' => $model->transmittal->decision_comment,
                'decision_at' => $model->transmittal->decision_at?->toIso8601String(),
            ]];
        }

        return [];
    }

    private function executiveRelatedItems(Model $model, User $user, int $organizationId): array
    {
        /** @var ExecutiveDocumentSet $set */
        $set = $model;

        return $set->documents
            ->map(fn ($document): array => [
                'id' => (int) $document->id,
                'title' => $document->title,
                'subtitle' => $document->work_type_name,
                'status' => $this->statusValue($document->status),
                'status_label' => $this->statusLabel($this->statusValue($document->status)),
                'available_actions' => $this->ptoService->availableExecutiveDocumentActions($user, $organizationId, (int) $document->id),
                'actions_endpoint' => "/api/v1/mobile/pto/executive-documents/{$document->id}/actions/{action}",
            ])
            ->values()
            ->all();
    }

    private function brigadeRelatedItems(Model $model): array
    {
        /** @var BrigadeProfile $brigade */
        $brigade = $model;

        return $brigade->assignments
            ->map(fn ($assignment): array => [
                'id' => (int) $assignment->id,
                'title' => $assignment->project?->name,
                'subtitle' => $this->dateValue($assignment->starts_at),
                'status' => $this->statusValue($assignment->status),
                'status_label' => $this->statusLabel($this->statusValue($assignment->status)),
            ])
            ->values()
            ->all();
    }

    private function section(string $titleKey, array $rows): ?array
    {
        $rows = array_values(array_filter($rows));

        if ($rows === []) {
            return null;
        }

        return [
            'title' => trans_message("mobile_companions.sections.{$titleKey}"),
            'rows' => $rows,
        ];
    }

    private function row(string $labelKey, mixed $value): ?array
    {
        $value = $this->stringValue($value);

        if ($value === null || trim($value) === '') {
            return null;
        }

        return [
            'label' => trans_message("mobile_companions.fields.{$labelKey}"),
            'value' => $value,
        ];
    }

    private function availableActions(string $slug, Model $model, User $user, int $organizationId): array
    {
        if ($slug === 'change-management' && $model instanceof ChangeRequest) {
            return match ($model->status) {
                'draft' => $this->hasAnyProjectPermission($user, $organizationId, (int) $model->project_id, ['change-management.create', 'change-management.edit'])
                    ? [$this->action('submit', 'submit_change', false)]
                    : [],
                'impact_assessment' => $this->hasAnyProjectPermission($user, $organizationId, (int) $model->project_id, ['change-management.edit'])
                    ? [$this->action('start_internal_review', 'start_internal_review', false)]
                    : [],
                'internal_review' => $model->impact?->requires_customer_approval
                    && $this->hasAnyProjectPermission($user, $organizationId, (int) $model->project_id, ['change-management.change-orders.approve'])
                    ? [$this->action('start_customer_review', 'start_customer_review', false)]
                    : [],
                'approved' => $this->hasAnyProjectPermission($user, $organizationId, (int) $model->project_id, ['change-management.edit'])
                    ? [$this->action('implement', 'implement_change', true)]
                    : [],
                'implemented' => $this->hasAnyProjectPermission($user, $organizationId, (int) $model->project_id, ['change-management.change-orders.approve'])
                    ? [$this->action('close', 'close_change', false)]
                    : [],
                default => [],
            };
        }

        return [];
    }

    private function action(string $key, string $titleKey, bool $requiresComment): array
    {
        return [
            'key' => $key,
            'title' => trans_message("mobile_companions.actions.{$titleKey}"),
            'requires_comment' => $requiresComment,
        ];
    }

    private function statusFilterPayload(array $statuses): array
    {
        return array_map(fn (string $status): array => [
            'value' => $status,
            'label' => $this->statusLabel($status),
        ], $statuses);
    }

    private function emptyState(string $slug): array
    {
        return [
            'title' => trans_message("mobile_companions.empty.{$slug}.title"),
            'description' => trans_message("mobile_companions.empty.{$slug}.description"),
        ];
    }

    private function permissionState(): array
    {
        return [
            'title' => trans_message('mobile_companions.permission.title'),
            'description' => trans_message('mobile_companions.permission.description'),
        ];
    }

    private function meta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    private function hasAnyPermission(User $user, int $organizationId, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->authorizationService->can($user, $permission, ['organization_id' => $organizationId])) {
                return true;
            }
        }

        return false;
    }

    private function hasAnyProjectPermission(User $user, int $organizationId, int $projectId, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->authorizationService->can($user, $permission, [
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'strict_project_scope' => true,
            ])) {
                return true;
            }
        }

        return false;
    }

    private function ensureModelActionPermission(User $user, int $organizationId, ChangeRequest $change, array $permissions): void
    {
        if ($this->hasAnyProjectPermission($user, $organizationId, (int) $change->project_id, $permissions)) {
            return;
        }

        throw new DomainException(trans_message('mobile_companions.errors.permission_denied'));
    }

    private function statusValue(mixed $status): ?string
    {
        if ($status instanceof BackedEnum) {
            return (string) $status->value;
        }

        if (is_bool($status)) {
            return $status ? 'active' : 'inactive';
        }

        if (is_scalar($status)) {
            $value = trim((string) $status);

            return $value !== '' ? $value : null;
        }

        return null;
    }

    private function statusLabel(?string $status): ?string
    {
        if ($status === null || $status === '') {
            return null;
        }

        return trans_message('mobile_companions.statuses.'.str_replace('-', '_', $status));
    }

    private function statusTone(?string $status): string
    {
        return match ($status) {
            'approved', 'active', 'completed', 'closed', 'implemented', 'online' => 'success',
            'draft', 'prepared', 'planned', 'unknown', 'pending' => 'neutral',
            'submitted', 'impact_assessment', 'internal_review', 'customer_review', 'under_review', 'remarks', 'on_hold' => 'warning',
            'rejected', 'cancelled', 'terminated', 'offline', 'error', 'inactive' => 'critical',
            default => 'neutral',
        };
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if (is_bool($value)) {
            return $this->boolValue($value);
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return null;
    }

    private function boolValue(?bool $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value
            ? trans_message('mobile_companions.boolean.yes')
            : trans_message('mobile_companions.boolean.no');
    }

    private function moneyValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 2, '.', ' ');
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        if (is_scalar($value)) {
            $date = trim((string) $value);

            return $date !== '' ? $date : null;
        }

        return null;
    }

    private function dateTimeValue(mixed $value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->toIso8601String();
        }

        if (is_scalar($value)) {
            $date = trim((string) $value);

            return $date !== '' ? $date : null;
        }

        return null;
    }

    private function firstFilled(array $values): ?string
    {
        foreach ($values as $value) {
            $string = $this->stringValue($value);

            if ($string !== null && $string !== '') {
                return $string;
            }
        }

        return null;
    }
}
