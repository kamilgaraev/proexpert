<?php

declare(strict_types=1);

namespace App\Services\Mobile;

use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeProfile;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeRequest;
use App\BusinessModules\Features\ChangeManagement\Services\ChangeManagementService;
use App\BusinessModules\Features\ExecutiveDocumentation\Enums\ExecutiveDocumentStatusEnum;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;
use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentationService;
use App\BusinessModules\Features\VideoMonitoring\Models\VideoCamera;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\Material;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
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
        private readonly ExecutiveDocumentationService $executiveDocumentationService
    ) {
    }

    public function canView(string $slug, User $user, int $organizationId): bool
    {
        $config = $this->moduleConfig($slug);

        if (!$this->accessController->hasModuleAccess($organizationId, $config['access_slug'])) {
            return false;
        }

        return $this->hasAnyPermission($user, $organizationId, $config['view_permissions']);
    }

    public function list(string $slug, User $user, int $organizationId, array $filters, int $perPage): array
    {
        $config = $this->moduleConfig($slug);
        $query = $this->queryFor($slug, $organizationId);

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
        $model = $this->findModel($slug, $organizationId, $id);

        return [
            'module' => $this->modulePayload($slug, $config),
            'item' => $this->listItem($slug, $model, $user, $organizationId),
            'sections' => $this->detailSections($slug, $model),
            'related_items' => $this->relatedItems($slug, $model),
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
            $this->ensureActionPermission($user, $organizationId, ['change-management.create', 'change-management.edit']);
            $change = $this->findModel($slug, $organizationId, $id);

            if (!$change instanceof ChangeRequest) {
                throw new DomainException(trans_message('mobile_companions.errors.item_not_found'));
            }

            $this->changeManagementService->submitChange($change);

            return $this->detail($slug, $id, $user, $organizationId);
        }

        if ($slug === 'executive-documentation' && $action === 'acknowledge_transmittal') {
            $this->ensureActionPermission($user, $organizationId, [
                'executive-documentation.review',
                'executive-documentation.approve',
            ]);
            $set = $this->findModel($slug, $organizationId, $id);

            if (!$set instanceof ExecutiveDocumentSet) {
                throw new DomainException(trans_message('mobile_companions.errors.item_not_found'));
            }

            $this->executiveDocumentationService->acknowledgeTransmittal(
                $set,
                (int) $user->id,
                $payload['comment'] ?? null
            );

            return $this->detail($slug, $id, $user, $organizationId);
        }

        throw new DomainException(trans_message('mobile_companions.errors.action_not_available'));
    }

    private function moduleConfig(string $slug): array
    {
        $config = self::MODULES[$slug] ?? null;

        if (!is_array($config)) {
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

    private function queryFor(string $slug, int $organizationId): Builder
    {
        return match ($slug) {
            'contract-management' => Contract::query()
                ->forOrganization($organizationId)
                ->with(['project', 'contractor', 'supplier'])
                ->withCount(['performanceActs', 'payments']),
            'change-management' => ChangeRequest::query()
                ->forOrganization($organizationId)
                ->with(['project', 'impact', 'approvals', 'variationOrders']),
            'executive-documentation' => ExecutiveDocumentSet::query()
                ->forOrganization($organizationId)
                ->with(['project', 'documents.remarks', 'transmittal'])
                ->withCount(['documents']),
            'project-management' => Project::query()
                ->accessibleByOrganization($organizationId)
                ->withCount(['users', 'contracts', 'completedWorks']),
            'catalog-management' => Material::query()
                ->where('organization_id', $organizationId)
                ->with(['measurementUnit']),
            'brigades' => BrigadeProfile::query()
                ->with(['members', 'assignments.project', 'specializations'])
                ->withCount(['members', 'assignments'])
                ->where(function (Builder $query) use ($organizationId): void {
                    $query->where('organization_id', $organizationId)
                        ->orWhereHas('assignments', static function (Builder $assignmentQuery) use ($organizationId): void {
                            $assignmentQuery->where('contractor_organization_id', $organizationId);
                        });
                }),
            'video-monitoring' => VideoCamera::query()
                ->where('organization_id', $organizationId)
                ->with(['project'])
                ->withCount(['events']),
            default => throw new DomainException(trans_message('mobile_companions.errors.module_not_found')),
        };
    }

    private function findModel(string $slug, int $organizationId, int $id): Model
    {
        $model = $this->queryFor($slug, $organizationId)->whereKey($id)->first();

        if (!$model instanceof Model) {
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

        if (!in_array($status, $config['statuses'], true)) {
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

    private function relatedItems(string $slug, Model $model): array
    {
        return match ($slug) {
            'executive-documentation' => $this->executiveRelatedItems($model),
            'brigades' => $this->brigadeRelatedItems($model),
            default => [],
        };
    }

    private function executiveRelatedItems(Model $model): array
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
        if ($slug === 'change-management' && $model instanceof ChangeRequest && $model->status === 'draft') {
            if ($this->hasAnyPermission($user, $organizationId, ['change-management.create', 'change-management.edit'])) {
                return [
                    $this->action('submit', 'submit_change', false),
                ];
            }
        }

        if ($slug === 'executive-documentation' && $model instanceof ExecutiveDocumentSet) {
            $model->loadMissing('transmittal');
            $status = $this->statusValue($model->status);

            if (
                $status === ExecutiveDocumentStatusEnum::TRANSMITTED->value
                && $model->transmittal !== null
                && $model->transmittal->acknowledged_at === null
                && $this->hasAnyPermission($user, $organizationId, ['executive-documentation.review', 'executive-documentation.approve'])
            ) {
                return [
                    $this->action('acknowledge_transmittal', 'acknowledge_transmittal', true),
                ];
            }
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

    private function ensureActionPermission(User $user, int $organizationId, array $permissions): void
    {
        if ($this->hasAnyPermission($user, $organizationId, $permissions)) {
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

        return trans_message('mobile_companions.statuses.' . str_replace('-', '_', $status));
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
