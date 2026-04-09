<?php

declare(strict_types=1);

namespace App\Services\Customer;

use App\BusinessModules\Features\Notifications\Models\Notification;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\ContactForm;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\File;
use App\Models\Project;
use App\Models\User;
use App\Services\Contract\ContractSideResolverService;
use App\Services\Project\ProjectCustomerResolverService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CustomerPortalService
{
    public function __construct(
        private readonly AuthorizationService $authorizationService,
        private readonly ProjectCustomerResolverService $projectCustomerResolverService,
        private readonly ContractSideResolverService $contractSideResolverService
    ) {
    }

    public function getDashboard(User $user, int $organizationId): array
    {
        $projects = $this->baseProjectQuery($organizationId)->get();
        $documentsCount = $this->baseDocumentQuery($organizationId)->count();
        $approvalsCount = $this->baseApprovalQuery($organizationId)->where('is_approved', false)->count();
        $unreadNotificationsCount = Notification::query()
            ->forUser($user)
            ->forOrganization($organizationId)
            ->unread()
            ->count();

        return [
            'metrics' => [
                [
                    'label' => 'Активные проекты',
                    'value' => (string) $projects->count(),
                    'tone' => 'primary',
                ],
                [
                    'label' => 'Ожидают решения',
                    'value' => (string) $approvalsCount,
                    'tone' => 'warning',
                ],
                [
                    'label' => 'Новые документы',
                    'value' => (string) $documentsCount,
                    'tone' => 'neutral',
                ],
                [
                    'label' => 'Непрочитанные сообщения',
                    'value' => (string) $unreadNotificationsCount,
                    'tone' => 'success',
                ],
            ],
        ];
    }

    public function getProjects(int $organizationId): array
    {
        $projects = $this->baseProjectQuery($organizationId)->get();

        return [
            'projects' => $projects->map(fn (Project $project): array => $this->mapProjectPreview($project))->all(),
        ];
    }

    public function getContracts(int $organizationId, array $filters = [], ?Project $project = null): array
    {
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;
        $perPage = isset($filters['per_page']) ? min(max((int) $filters['per_page'], 1), 50) : 15;
        $appliedFilters = $this->normalizeContractFilters($filters, $project);

        $contracts = $this->baseCustomerContractQuery($organizationId, $appliedFilters, $project)
            ->with([
                'project.projectAddress',
                'project.organization',
                'project.organizations',
                'projects:id,name,organization_id,address',
                'projects.projectAddress',
                'projects.organization',
                'projects.organizations',
                'contractor:id,name',
                'performanceActs:id,contract_id,amount,is_approved',
                'payments:id,contract_id,amount',
            ])
            ->latest('date')
            ->paginate($perPage, ['*'], 'page', $page);

        return [
            'items' => collect($contracts->items())
                ->filter(fn (Contract $contract): bool => $this->canAccessContract($organizationId, $contract))
                ->values()
                ->map(
                fn (Contract $contract): array => $this->mapCustomerContract($contract)
            )->all(),
            'meta' => $this->buildContractsMeta($contracts, $appliedFilters),
        ];
    }

    public function getContract(int $organizationId, Contract $contract): ?array
    {
        $contract->loadMissing([
            'project.projectAddress',
            'project.organization',
            'project.organizations',
            'projects:id,name',
            'contractor:id,name',
            'performanceActs:id,contract_id,amount,is_approved',
            'payments:id,contract_id,amount',
        ]);

        if (!$this->canAccessContract($organizationId, $contract)) {
            return null;
        }

        return [
            'contract' => $this->mapCustomerContract($contract),
        ];
    }

    public function canAccessContract(int $organizationId, Contract $contract): bool
    {
        return $contract->contract_side_type === ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR
            && (int) $contract->organization_id === $organizationId;
    }

    public function getProject(Project $project): array
    {
        $project->loadMissing(['projectAddress', 'organization', 'organizations']);
        $resolvedCustomer = $this->projectCustomerResolverService->resolve($project);

        return [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'location' => $this->resolveProjectLocation($project),
                'phase' => $this->resolveProjectPhase($project),
                'completion' => $this->resolveProjectCompletion($project),
                'budgetLabel' => $this->formatMoney($project->budget_amount),
                'leadLabel' => $this->resolveLeadLabel($project),
                'status' => $project->status,
                'description' => $project->description,
                'startDate' => optional($project->start_date)?->format('Y-m-d'),
                'endDate' => optional($project->end_date)?->format('Y-m-d'),
                'resolved_customer' => $this->mapResolvedCustomer($resolvedCustomer),
            ],
        ];
    }

    public function getDocuments(int $organizationId, ?Project $project = null): array
    {
        $documents = $this->baseDocumentQuery($organizationId, $project)
            ->latest('updated_at')
            ->limit(50)
            ->get();

        $projects = $project
            ? collect([$project->id => $project->loadMissing('projectAddress')])
            : $this->baseProjectQuery($organizationId)->get()->keyBy('id');

        return [
            'items' => $documents->map(
                fn (File $file): array => $this->mapDocument($file, $projects)
            )->all(),
        ];
    }

    public function getApprovals(int $organizationId, ?Project $project = null): array
    {
        $approvals = $this->baseApprovalQuery($organizationId, $project)
            ->with(['project.projectAddress', 'contract:id,number,subject,status'])
            ->latest('act_date')
            ->limit(20)
            ->get();

        return [
            'items' => $approvals->map(
                fn (ContractPerformanceAct $approval): array => $this->mapApproval($approval)
            )->all(),
        ];
    }

    public function getConversations(int $organizationId, ?Project $project = null): array
    {
        return [
            'items' => [],
            'meta' => [
                'organization_id' => $organizationId,
                'project_id' => $project?->id,
            ],
        ];
    }

    public function getNotifications(User $user, int $organizationId): array
    {
        $notifications = Notification::query()
            ->forUser($user)
            ->forOrganization($organizationId)
            ->latest('created_at')
            ->limit(25)
            ->get();
        $unreadCount = Notification::query()
            ->forUser($user)
            ->forOrganization($organizationId)
            ->unread()
            ->count();

        return [
            'items' => $notifications->map(
                fn (Notification $notification): array => $this->mapNotification($notification)
            )->all(),
            'meta' => [
                'organization_id' => $organizationId,
                'unread_count' => $unreadCount,
                'total' => $notifications->count(),
            ],
        ];
    }

    public function getProfile(User $user, int $organizationId): array
    {
        $user->loadMissing('currentOrganization');
        $authContext = AuthorizationContext::getOrganizationContext($organizationId);

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'organization_id' => $organizationId,
                'organization_name' => $user->currentOrganization?->name,
                'roles' => $this->authorizationService->getUserRoleSlugs($user, [
                    'organization_id' => $organizationId,
                ]),
                'interfaces' => $this->resolveCustomerInterfaces($user, $authContext),
            ],
        ];
    }

    public function getPermissions(User $user, int $organizationId): array
    {
        $authContext = AuthorizationContext::getOrganizationContext($organizationId);
        $roles = $this->authorizationService->getUserRoleSlugs($user, [
            'organization_id' => $organizationId,
        ]);
        $permissions = $this->authorizationService->getUserPermissionsStructured($user, $authContext);
        $permissionsFlat = $this->flattenPermissions($permissions);

        return [
            'roles' => $roles,
            'permissions' => [
                'system' => array_values($this->normalizePermissions($permissions['system'] ?? [])),
                'modules' => $this->normalizeModulePermissions($permissions['modules'] ?? []),
            ],
            'permissions_flat' => $permissionsFlat,
            'interfaces' => $this->resolveCustomerInterfaces($user, $authContext),
            'meta' => [
                'checked_at' => now()->toISOString(),
                'total_permissions' => count($permissionsFlat),
                'total_roles' => count($roles),
            ],
        ];
    }

    public function createSupportRequest(User $user, int $organizationId, array $payload): array
    {
        $user->loadMissing('currentOrganization');

        $contactForm = ContactForm::create([
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $payload['phone'] ?? $user->phone,
            'company' => $user->currentOrganization?->name,
            'company_role' => 'customer',
            'subject' => $payload['subject'],
            'message' => $payload['message'],
            'consent_to_personal_data' => true,
            'consent_version' => 'customer-v1',
            'page_source' => 'customer-portal',
            'status' => ContactForm::STATUS_NEW,
            'telegram_data' => [
                'organization_id' => $organizationId,
                'user_id' => $user->id,
            ],
        ]);

        return [
            'request' => $this->mapSupportRequest($contactForm),
        ];
    }

    public function getSupportRequests(User $user, int $organizationId): array
    {
        $requests = ContactForm::query()
            ->where('page_source', 'customer-portal')
            ->where(function (Builder $builder) use ($organizationId, $user): void {
                $builder
                    ->whereRaw("(telegram_data->>'organization_id') = ?", [(string) $organizationId])
                    ->orWhere(function (Builder $inner) use ($user): void {
                        $inner
                            ->where('email', $user->email)
                            ->where('company_role', 'customer');
                    });
            })
            ->latest('created_at')
            ->limit(20)
            ->get();

        return [
            'items' => $requests->map(
                fn (ContactForm $request): array => $this->mapSupportRequest($request)
            )->all(),
            'meta' => [
                'organization_id' => $organizationId,
                'total' => $requests->count(),
            ],
        ];
    }

    private function baseProjectQuery(int $organizationId): Builder
    {
        return Project::query()
            ->with(['projectAddress', 'organization', 'organizations'])
            ->accessibleByOrganization($organizationId)
            ->where('is_archived', false)
            ->orderByDesc('updated_at');
    }

    private function baseDocumentQuery(int $organizationId, ?Project $project = null): Builder
    {
        $query = File::query()->where('organization_id', $organizationId);

        if ($project) {
            $approvalIds = ContractPerformanceAct::query()
                ->where('project_id', $project->id)
                ->pluck('id');

            $query->where(function (Builder $builder) use ($project, $approvalIds): void {
                $builder->where(function (Builder $inner) use ($project): void {
                    $inner
                        ->where('fileable_type', Project::class)
                        ->where('fileable_id', $project->id);
                });

                if ($approvalIds->isNotEmpty()) {
                    $builder->orWhere(function (Builder $inner) use ($approvalIds): void {
                        $inner
                            ->where('fileable_type', ContractPerformanceAct::class)
                            ->whereIn('fileable_id', $approvalIds->all());
                    });
                }
            });
        }

        return $query;
    }

    private function baseApprovalQuery(int $organizationId, ?Project $project = null): Builder
    {
        return ContractPerformanceAct::query()
            ->when(
                $project !== null,
                fn (Builder $builder): Builder => $builder->where('project_id', $project->id),
                fn (Builder $builder): Builder => $builder->whereHas(
                    'project',
                    fn (Builder $projectQuery): Builder => $projectQuery->accessibleByOrganization($organizationId)
                )
            );
    }

    private function baseCustomerContractQuery(int $organizationId, array $filters = [], ?Project $project = null): Builder
    {
        return Contract::query()
            ->where('contract_side_type', ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR->value)
            ->where('organization_id', $organizationId)
            ->when($project !== null, function (Builder $builder) use ($project): void {
                $builder->where(function (Builder $scope) use ($project): void {
                    $scope
                        ->where('project_id', $project->id)
                        ->orWhereHas('projects', function (Builder $projectsQuery) use ($project): void {
                            $projectsQuery->where('projects.id', $project->id);
                        });
                });
            })
            ->when(isset($filters['project_id']), function (Builder $builder) use ($filters): void {
                $projectId = (int) $filters['project_id'];
                $builder->where(function (Builder $scope) use ($projectId): void {
                    $scope
                        ->where('project_id', $projectId)
                        ->orWhereHas('projects', fn (Builder $projectsQuery) => $projectsQuery->where('projects.id', $projectId));
                });
            })
            ->when(isset($filters['status']), fn (Builder $builder) => $builder->where('status', $filters['status']))
            ->when(isset($filters['contractor_id']), fn (Builder $builder) => $builder->where('contractor_id', (int) $filters['contractor_id']))
            ->when(isset($filters['date_from']), fn (Builder $builder) => $builder->whereDate('date', '>=', $filters['date_from']))
            ->when(isset($filters['date_to']), fn (Builder $builder) => $builder->whereDate('date', '<=', $filters['date_to']))
            ->when(isset($filters['search']), function (Builder $builder) use ($filters): void {
                $search = (string) $filters['search'];
                $builder->where(function (Builder $scope) use ($search): void {
                    $scope
                        ->where('number', 'like', '%' . $search . '%')
                        ->orWhere('subject', 'like', '%' . $search . '%');
                });
            });
    }

    private function mapProjectPreview(Project $project): array
    {
        $resolvedCustomer = $this->projectCustomerResolverService->resolve($project);

        return [
            'id' => $project->id,
            'name' => $project->name,
            'location' => $this->resolveProjectLocation($project),
            'phase' => $this->resolveProjectPhase($project),
            'completion' => $this->resolveProjectCompletion($project),
            'budgetLabel' => $this->formatMoney($project->budget_amount),
            'leadLabel' => $this->resolveLeadLabel($project),
            'resolved_customer' => $this->mapResolvedCustomer($resolvedCustomer),
        ];
    }

    private function mapCustomerContract(Contract $contract): array
    {
        $project = $contract->project;
        $contractSide = $this->contractSideResolverService->resolve($contract);
        $performedAmount = $contract->relationLoaded('performanceActs')
            ? (float) $contract->performanceActs->where('is_approved', true)->sum('amount')
            : 0.0;
        $paidAmount = $contract->relationLoaded('payments')
            ? (float) $contract->payments->sum('amount')
            : 0.0;
        $totalAmount = $contract->total_amount !== null ? (float) $contract->total_amount : null;
        $remainingAmount = $totalAmount !== null ? max(0.0, $totalAmount - $performedAmount) : null;

        return [
            'id' => $contract->id,
            'number' => $contract->number,
            'subject' => $contract->subject,
            'status' => $contract->status?->value ?? (string) $contract->status,
            'status_label' => $contract->status?->name ?? (string) $contract->status,
            'project' => $project ? [
                'id' => $project->id,
                'name' => $project->name,
                'location' => $this->resolveProjectLocation($project),
            ] : null,
            'projects' => $contract->relationLoaded('projects')
                ? $contract->projects->map(fn (Project $item): array => [
                    'id' => $item->id,
                    'name' => $item->name,
                ])->values()->all()
                : [],
            'contractor' => $contract->contractor ? [
                'id' => $contract->contractor->id,
                'name' => $contract->contractor->name,
            ] : null,
            'date' => optional($contract->date)?->format('Y-m-d'),
            'start_date' => optional($contract->start_date)?->format('Y-m-d'),
            'end_date' => optional($contract->end_date)?->format('Y-m-d'),
            'total_amount' => $totalAmount,
            'performed_amount' => round($performedAmount, 2),
            'paid_amount' => round($paidAmount, 2),
            'remaining_amount' => $remainingAmount !== null ? round($remainingAmount, 2) : null,
            'is_self_execution' => (bool) $contract->is_self_execution,
            'contract_category' => $contract->contract_category,
            'customer' => $contractSide['customer_organization'],
            'contract_side' => $contractSide,
            'current_organization_role' => $this->resolveCurrentOrganizationRole($contract),
        ];
    }

    private function mapDocument(File $file, Collection $projects): array
    {
        $project = null;

        if ($file->fileable_type === Project::class) {
            $project = $projects->get((int) $file->fileable_id);
        }

        if ($file->fileable_type === ContractPerformanceAct::class) {
            $approval = ContractPerformanceAct::query()
                ->with('project.projectAddress')
                ->find($file->fileable_id);

            $project = $approval?->project;
        }

        return [
            'id' => $file->id,
            'title' => $file->original_name ?: $file->name,
            'projectName' => $project?->name,
            'uploadedAtLabel' => $file->updated_at?->format('d.m.Y H:i'),
            'type' => $file->type,
            'category' => $file->category,
            'size' => $file->size,
            'path' => $file->path,
        ];
    }

    private function mapApproval(ContractPerformanceAct $approval): array
    {
        $project = $approval->project;
        $dateLabel = $approval->act_date?->format('d.m.Y');
        $hasCustomerContractAccess = $approval->contract instanceof Contract
            && $approval->contract->contract_side_type === ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR;

        return [
            'id' => $approval->id,
            'title' => 'Акт ' . ($approval->act_document_number ?: '#' . $approval->id),
            'projectName' => $project?->name,
            'contractId' => $hasCustomerContractAccess ? $approval->contract_id : null,
            'contractNumber' => $approval->contract?->number,
            'contractSubject' => $approval->contract?->subject,
            'contractStatus' => $approval->contract?->status?->value ?? (string) $approval->contract?->status,
            'deadlineLabel' => $approval->is_approved
                ? 'Согласовано ' . ($approval->approval_date?->format('d.m.Y') ?? $dateLabel)
                : 'Ожидает решения с ' . $dateLabel,
            'status' => $approval->is_approved ? 'approved' : 'pending',
            'amount' => $this->formatMoney($approval->amount),
        ];
    }

    private function applyResolvedCustomerProjectAccess(Builder $builder, int $organizationId): void
    {
        $builder->where(function (Builder $scope) use ($organizationId): void {
            $scope
                ->whereHas('organizations', function (Builder $organizationQuery) use ($organizationId): void {
                    $organizationQuery
                        ->where('organizations.id', $organizationId)
                        ->where('project_organization.is_active', true)
                        ->where(function (Builder $roleQuery): void {
                            $roleQuery
                                ->where('project_organization.role_new', 'customer')
                                ->orWhere(function (Builder $fallbackQuery): void {
                                    $fallbackQuery
                                        ->whereNull('project_organization.role_new')
                                        ->where('project_organization.role', 'customer');
                                });
                        });
                })
                ->orWhere(function (Builder $ownerScope) use ($organizationId): void {
                    $ownerScope
                        ->where('organization_id', $organizationId)
                        ->whereDoesntHave('organizations', function (Builder $organizationQuery): void {
                            $organizationQuery
                                ->where('project_organization.is_active', true)
                                ->where(function (Builder $roleQuery): void {
                                    $roleQuery
                                        ->where('project_organization.role_new', 'customer')
                                        ->orWhere(function (Builder $fallbackQuery): void {
                                            $fallbackQuery
                                                ->whereNull('project_organization.role_new')
                                                ->where('project_organization.role', 'customer');
                                        });
                                });
                        });
                });
        });
    }

    private function normalizeContractFilters(array $filters, ?Project $project = null): array
    {
        $normalized = array_filter([
            'project_id' => $project?->id ?? ($filters['project_id'] ?? null),
            'status' => $filters['status'] ?? null,
            'contractor_id' => isset($filters['contractor_id']) ? (int) $filters['contractor_id'] : null,
            'date_from' => $filters['date_from'] ?? null,
            'date_to' => $filters['date_to'] ?? null,
            'search' => isset($filters['search']) ? trim((string) $filters['search']) : null,
        ], static fn ($value): bool => $value !== null && $value !== '');

        return $normalized;
    }

    private function buildContractsMeta($contracts, array $filters): array
    {
        return [
            'current_page' => $contracts->currentPage(),
            'per_page' => $contracts->perPage(),
            'total' => $contracts->total(),
            'last_page' => $contracts->lastPage(),
            'filters' => $filters,
        ];
    }

    private function resolveCurrentOrganizationRole(Contract $contract): string
    {
        return $contract->contract_side_type === ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR
            ? 'customer'
            : 'initiator';
    }

    private function mapResolvedCustomer(?array $resolvedCustomer): ?array
    {
        if ($resolvedCustomer === null) {
            return null;
        }

        return [
            'id' => $resolvedCustomer['id'],
            'name' => $resolvedCustomer['name'],
            'source' => $resolvedCustomer['source'],
            'role' => $resolvedCustomer['role'],
            'is_fallback_owner' => $resolvedCustomer['is_fallback_owner'],
        ];
    }

    private function mapNotification(Notification $notification): array
    {
        $data = is_array($notification->data) ? $notification->data : [];
        $title = $this->firstNonEmptyString([
            $data['title'] ?? null,
            $data['subject'] ?? null,
            $data['name'] ?? null,
        ]) ?? Str::headline((string) ($notification->notification_type ?: $notification->type ?: 'Уведомление'));
        $description = $this->firstNonEmptyString([
            $data['message'] ?? null,
            $data['description'] ?? null,
            $data['body'] ?? null,
            $data['text'] ?? null,
        ]) ?? 'Откройте уведомление, чтобы посмотреть детали.';

        return [
            'id' => (string) $notification->id,
            'title' => $title,
            'description' => $description,
            'createdAtLabel' => $notification->created_at?->format('d.m.Y H:i'),
            'tone' => $this->resolveNotificationTone($notification),
            'statusLabel' => $notification->read_at ? 'Прочитано' : 'Не прочитано',
            'isUnread' => $notification->read_at === null,
        ];
    }

    private function mapSupportRequest(ContactForm $contactForm): array
    {
        return [
            'id' => $contactForm->id,
            'status' => $contactForm->status,
            'statusLabel' => $this->resolveSupportStatusLabel($contactForm->status),
            'subject' => $contactForm->subject,
            'message' => $contactForm->message,
            'phone' => $contactForm->phone,
            'createdAt' => $contactForm->created_at?->toISOString(),
            'createdAtLabel' => $contactForm->created_at?->format('d.m.Y H:i'),
        ];
    }

    private function resolveProjectLocation(Project $project): string
    {
        return $project->projectAddress?->getFormattedAddress()
            ?: $project->address
            ?: 'Адрес уточняется';
    }

    private function resolveProjectPhase(Project $project): string
    {
        return match ($project->status) {
            'draft' => 'Подготовка',
            'active' => 'В работе',
            'completed' => 'Завершен',
            'paused' => 'Приостановлен',
            default => $project->status ?: 'В работе',
        };
    }

    private function resolveProjectCompletion(Project $project): int
    {
        $completion = ContractPerformanceAct::query()
            ->where('project_id', $project->id)
            ->where('is_approved', true)
            ->count();

        if ($completion === 0) {
            return $project->status === 'completed' ? 100 : 0;
        }

        return min($completion * 10, 100);
    }

    private function resolveLeadLabel(Project $project): string
    {
        if ($project->customer_representative) {
            return 'Ответственный: ' . $project->customer_representative;
        }

        if ($project->organization?->name) {
            return 'Организация: ' . $project->organization->name;
        }

        return 'Ответственный уточняется';
    }

    private function formatMoney(float|int|string|null $amount): string
    {
        if ($amount === null || $amount === '') {
            return 'Бюджет уточняется';
        }

        return number_format((float) $amount, 0, '.', ' ') . ' руб.';
    }

    private function resolveNotificationTone(Notification $notification): string
    {
        return match ($notification->priority) {
            'critical', 'high' => 'warning',
            'low' => $notification->read_at ? 'neutral' : 'success',
            default => $notification->read_at ? 'neutral' : 'primary',
        };
    }

    private function resolveSupportStatusLabel(?string $status): string
    {
        return match ($status) {
            ContactForm::STATUS_PROCESSING => 'В работе',
            ContactForm::STATUS_COMPLETED => 'Решено',
            ContactForm::STATUS_CANCELLED => 'Закрыто',
            default => 'Новая заявка',
        };
    }

    private function normalizeModulePermissions(array $modules): array
    {
        $normalized = [];

        foreach ($modules as $module => $modulePermissions) {
            if (!is_array($modulePermissions)) {
                continue;
            }

            $normalized[$module] = array_values($this->normalizePermissions($modulePermissions));
        }

        return $normalized;
    }

    private function flattenPermissions(array $permissions): array
    {
        $flat = $this->normalizePermissions($permissions['system'] ?? []);

        foreach ($this->normalizeModulePermissions($permissions['modules'] ?? []) as $modulePermissions) {
            $flat = array_merge($flat, $modulePermissions);
        }

        return array_values(array_unique($flat));
    }

    private function normalizePermissions(array $permissions): array
    {
        $normalized = [];

        foreach ($permissions as $permission) {
            $normalizedPermission = $this->normalizePermission($permission);

            if ($normalizedPermission !== null) {
                $normalized[] = $normalizedPermission;
            }
        }

        return $normalized;
    }

    private function normalizePermission(mixed $permission): ?string
    {
        if (is_string($permission) && $permission !== '*') {
            return $permission;
        }

        if (is_array($permission) && isset($permission['name']) && is_string($permission['name']) && $permission['name'] !== '*') {
            return $permission['name'];
        }

        return null;
    }

    private function firstNonEmptyString(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function resolveAvailableInterfaces(User $user, AuthorizationContext $authContext): array
    {
        $interfaces = [];

        foreach (['lk', 'admin', 'mobile', 'customer'] as $interface) {
            if ($this->authorizationService->canAccessInterface($user, $interface, $authContext)) {
                $interfaces[] = $interface;
            }
        }

        return $interfaces;
    }

    private function resolveCustomerInterfaces(User $user, AuthorizationContext $authContext): array
    {
        $interfaces = $this->resolveAvailableInterfaces($user, $authContext);

        if (!\in_array('customer', $interfaces, true)) {
            $interfaces[] = 'customer';
        }

        return array_values(array_unique($interfaces));
    }
}
