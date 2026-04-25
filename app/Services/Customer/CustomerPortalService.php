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
use App\Models\CustomerIssue;
use App\Models\CustomerPortalComment;
use App\Models\CustomerRequest;
use App\Models\File;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectParticipantInvitation;
use App\Models\User;
use App\Services\Contract\ContractSideResolverService;
use App\Services\Project\ProjectCustomerResolverService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File as FileSystem;

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
        $projects = $this->baseProjectQuery($organizationId, $user)->get();
        $documentsCount = $this->baseDocumentQuery($organizationId, null, $user)->count();
        $approvalsCount = $this->baseApprovalQuery($organizationId, null, $user)->where('is_approved', false)->count();
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
            'attention_feed' => $this->buildAttentionFeed($organizationId, $user),
            'finance_summary' => $this->canViewFinance($user, $organizationId)
                ? $this->buildFinanceSummaryPayload($organizationId, $user)
                : null,
            'project_risks' => $this->buildProjectRisks($organizationId, $user),
            'recent_changes' => $this->buildRecentChanges($organizationId, $user),
            'discipline_summary' => $this->buildDisciplineSummary($organizationId, $user),
        ];
    }

    public function getProjects(int $organizationId, ?User $user = null): array
    {
        $projects = $this->baseProjectQuery($organizationId, $user)->get();

        return [
            'projects' => $projects->map(fn (Project $project): array => $this->mapProjectPreview($project))->all(),
        ];
    }

    public function getProjectInvitations(int $organizationId, ?User $user = null): array
    {
        $projectIds = $this->baseProjectQuery($organizationId, $user)
            ->pluck('projects.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($projectIds === []) {
            return ['items' => []];
        }

        $items = ProjectParticipantInvitation::query()
            ->with(['project:id,name', 'invitedOrganization:id,name'])
            ->whereIn('project_id', $projectIds)
            ->latest()
            ->limit(30)
            ->get()
            ->map(function (ProjectParticipantInvitation $invitation): array {
                $status = $invitation->isCancelled()
                    ? ProjectParticipantInvitation::STATUS_CANCELLED
                    : ($invitation->isExpired() ? ProjectParticipantInvitation::STATUS_EXPIRED : $invitation->status);

                return [
                    'id' => $invitation->id,
                    'project' => [
                        'id' => $invitation->project?->id,
                        'name' => $invitation->project?->name,
                    ],
                    'status' => $status,
                    'role' => $invitation->role,
                    'role_label' => $invitation->roleEnum()->label(),
                    'organization_name' => $invitation->organization_name ?? $invitation->invitedOrganization?->name,
                    'email' => $invitation->email,
                    'expires_at' => optional($invitation->expires_at)?->toIso8601String(),
                    'accepted_at' => optional($invitation->accepted_at)?->toIso8601String(),
                    'cancelled_at' => optional($invitation->cancelled_at)?->toIso8601String(),
                    'resent_at' => optional($invitation->resent_at)?->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return [
            'items' => $items,
        ];
    }

    public function getContracts(int $organizationId, array $filters = [], ?Project $project = null, ?User $user = null): array
    {
        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;
        $perPage = isset($filters['per_page']) ? min(max((int) $filters['per_page'], 1), 50) : 15;
        $appliedFilters = $this->normalizeContractFilters($filters, $project);

        $contracts = $this->baseCustomerContractQuery($organizationId, $appliedFilters, $project, $user)
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

    public function getContract(int $organizationId, Contract $contract, ?User $user = null): ?array
    {
        $contract->loadMissing([
            'project.projectAddress',
            'project.organization',
            'project.organizations',
            'projects:id,name',
            'contractor:id,name',
            'supplier:id,name',
            'agreements:id,contract_id,number,agreement_date,change_amount',
            'performanceActs:id,contract_id,amount,is_approved',
            'payments:id,contract_id,amount',
            'stateEvents:id,contract_id,event_type,event_data,created_at',
        ]);

        if (!$this->canAccessContract($organizationId, $contract, $user)) {
            return null;
        }

        return [
            'contract' => $this->mapCustomerContractDetails($contract),
        ];
    }

    public function canAccessContract(int $organizationId, Contract $contract, ?User $user = null): bool
    {
        if (
            $contract->contract_side_type !== ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR
            || (int) $contract->organization_id !== $organizationId
        ) {
            return false;
        }

        $scopedProjectIds = $this->resolveScopedProjectIds($organizationId, $user);

        if ($scopedProjectIds === null) {
            return true;
        }

        return $this->resolveContractProjectIds($contract)
            ->intersect($scopedProjectIds)
            ->isNotEmpty();
    }

    public function getProject(int $organizationId, Project $project, ?User $user = null): array
    {
        $project->loadMissing(['projectAddress', 'organization', 'organizations']);
        $resolvedCustomer = $this->projectCustomerResolverService->resolve($project);
        $financeSummary = $this->buildProjectFinancePayload($organizationId, $project, $user);

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
                'finance_summary' => $financeSummary,
                'key_contracts' => $this->buildProjectKeyContracts($organizationId, $project, $user),
                'problem_flags' => $this->buildSingleProjectRisk($organizationId, $project, $user),
            ],
        ];
    }

    public function getProjectWorkspace(int $organizationId, Project $project, ?User $user = null): array
    {
        $projectPayload = $this->getProject($organizationId, $project, $user)['project'];

        return [
            'workspace' => [
                'project' => $projectPayload,
                'documents' => $this->getDocuments($organizationId, $project, $user),
                'approvals' => $this->getApprovals($organizationId, $project, $user),
                'timeline' => $this->getProjectTimeline($organizationId, $project, $user)['items'],
                'risk_center' => $this->getProjectRisks($organizationId, $project, $user)['risk'],
                'summary' => [
                    'documents_total' => count($this->getDocuments($organizationId, $project, $user)['items']),
                    'approvals_total' => count($this->getApprovals($organizationId, $project, $user)['items']),
                    'key_contracts_total' => count($projectPayload['key_contracts'] ?? []),
                ],
            ],
        ];
    }

    public function getProjectTimeline(int $organizationId, Project $project, ?User $user = null): array
    {
        $project->loadMissing(['projectAddress', 'organization']);

        $contracts = $this->baseCustomerContractQuery($organizationId, ['project_id' => $project->id], $project, $user)
            ->with(['project:id,name'])
            ->latest('updated_at')
            ->limit(10)
            ->get();
        $approvals = $this->baseApprovalQuery($organizationId, $project, $user)
            ->latest('updated_at')
            ->limit(10)
            ->get();
        $documents = $this->baseDocumentQuery($organizationId, $project, $user)
            ->latest('updated_at')
            ->limit(10)
            ->get();
        $issues = CustomerIssue::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $project->id)
            ->latest('updated_at')
            ->limit(10)
            ->get();
        $requests = CustomerRequest::query()
            ->where('organization_id', $organizationId)
            ->where('project_id', $project->id)
            ->latest('updated_at')
            ->limit(10)
            ->get();

        $items = collect()
            ->merge($contracts->map(fn (Contract $contract): array => [
                'id' => 'contract-' . $contract->id,
                'type' => 'contract',
                'title' => 'Обновлен договор ' . $contract->number,
                'subtitle' => $contract->subject,
                'project' => ['id' => $project->id, 'name' => $project->name],
                'related_entity' => ['type' => 'contract', 'id' => $contract->id],
                'created_at' => $contract->updated_at?->toISOString(),
                'status' => $contract->status?->value ?? (string) $contract->status,
                'priority' => 'info',
            ]))
            ->merge($approvals->map(fn (ContractPerformanceAct $approval): array => [
                'id' => 'approval-' . $approval->id,
                'type' => 'approval',
                'title' => 'Обновлен акт ' . ($approval->act_document_number ?: '#' . $approval->id),
                'subtitle' => $approval->is_approved ? 'Согласовано' : 'Ожидает решения',
                'project' => ['id' => $project->id, 'name' => $project->name],
                'related_entity' => ['type' => 'approval', 'id' => $approval->id],
                'created_at' => $approval->updated_at?->toISOString(),
                'status' => $approval->is_approved ? 'approved' : 'pending',
                'priority' => $approval->is_approved ? 'info' : 'warning',
            ]))
            ->merge($documents->map(fn (File $document): array => [
                'id' => 'document-' . $document->id,
                'type' => 'document',
                'title' => 'Добавлен документ ' . ($document->original_name ?: $document->name),
                'subtitle' => $document->category ?: $document->type,
                'project' => ['id' => $project->id, 'name' => $project->name],
                'related_entity' => ['type' => 'document', 'id' => $document->id],
                'created_at' => $document->updated_at?->toISOString(),
                'status' => 'available',
                'priority' => 'info',
            ]))
            ->merge($issues->map(fn (CustomerIssue $issue): array => [
                'id' => 'issue-' . $issue->id,
                'type' => 'issue',
                'title' => 'Открыто замечание ' . $issue->title,
                'subtitle' => $this->resolvePortalWorkflowStatusLabel($issue->status),
                'project' => ['id' => $project->id, 'name' => $project->name],
                'related_entity' => ['type' => 'issue', 'id' => $issue->id],
                'created_at' => $issue->updated_at?->toISOString(),
                'status' => $issue->status,
                'priority' => $this->isWorkflowOverdue($issue->status, $issue->due_date) ? 'critical' : 'warning',
            ]))
            ->merge($requests->map(fn (CustomerRequest $request): array => [
                'id' => 'request-' . $request->id,
                'type' => 'request',
                'title' => 'Обновлен запрос ' . $request->title,
                'subtitle' => $this->resolvePortalWorkflowStatusLabel($request->status),
                'project' => ['id' => $project->id, 'name' => $project->name],
                'related_entity' => ['type' => 'request', 'id' => $request->id],
                'created_at' => $request->updated_at?->toISOString(),
                'status' => $request->status,
                'priority' => $this->isWorkflowOverdue($request->status, $request->due_date) ? 'critical' : 'primary',
            ]))
            ->sortByDesc('created_at')
            ->take(20)
            ->values()
            ->all();

        return [
            'items' => $items,
            'meta' => [
                'project_id' => $project->id,
                'total' => count($items),
            ],
        ];
    }

    public function getProjectRisks(int $organizationId, Project $project, ?User $user = null): array
    {
        return [
            'risk' => $this->buildSingleProjectRisk($organizationId, $project, $user),
        ];
    }

    public function getFinanceSummary(int $organizationId, ?User $user = null): array
    {
        return [
            'summary' => $this->buildFinanceSummaryPayload($organizationId, $user),
        ];
    }

    public function getProjectFinanceSummary(int $organizationId, Project $project, ?User $user = null): array
    {
        return [
            'summary' => $this->buildProjectFinancePayload($organizationId, $project, $user),
        ];
    }

    public function getIssues(int $organizationId, array $filters = [], ?User $user = null): array
    {
        $scopedProjectIds = $this->resolveScopedProjectIds($organizationId, $user);
        $issues = CustomerIssue::query()
            ->with(['author:id,name', 'project:id,name', 'contract:id,number,subject', 'performanceAct:id,act_document_number,amount', 'file:id,name,original_name', 'comments.author:id,name'])
            ->where('organization_id', $organizationId)
            ->when($scopedProjectIds !== null, fn (Builder $builder) => $builder->whereIn('project_id', $scopedProjectIds->all()))
            ->when(isset($filters['status']), fn (Builder $builder) => $builder->where('status', (string) $filters['status']))
            ->when(isset($filters['project_id']), fn (Builder $builder) => $builder->where('project_id', (int) $filters['project_id']))
            ->when(isset($filters['issue_reason']), fn (Builder $builder) => $builder->where('issue_reason', (string) $filters['issue_reason']))
            ->when(isset($filters['due_state']) && $filters['due_state'] === 'overdue', fn (Builder $builder) => $builder->whereNotIn('status', ['resolved', 'rejected'])->whereDate('due_date', '<', now()->toDateString()))
            ->latest('updated_at')
            ->limit(50)
            ->get();

        return [
            'items' => $issues->map(fn (CustomerIssue $issue): array => $this->mapIssue($issue))->all(),
        ];
    }

    public function getIssue(int $organizationId, CustomerIssue $issue, ?User $user = null): ?array
    {
        if ((int) $issue->organization_id !== $organizationId) {
            return null;
        }

        if (!$this->canAccessWorkflowProject($organizationId, $issue->project_id, $user)) {
            return null;
        }

        $issue->loadMissing(['author:id,name', 'resolver:id,name', 'project:id,name', 'contract:id,number,subject', 'performanceAct:id,act_document_number,amount', 'file:id,name,original_name', 'comments.author:id,name']);

        return [
            'issue' => $this->mapIssue($issue, true),
        ];
    }

    public function createIssue(User $user, int $organizationId, array $payload): array
    {
        $payload = $this->normalizeIssuePayload($organizationId, $payload, $user);

        $issue = CustomerIssue::create([
            'organization_id' => $organizationId,
            'author_user_id' => $user->id,
            'project_id' => $payload['project_id'] ?? null,
            'contract_id' => $payload['contract_id'] ?? null,
            'performance_act_id' => $payload['performance_act_id'] ?? null,
            'file_id' => $payload['file_id'] ?? null,
            'title' => $payload['title'],
            'issue_reason' => $payload['issue_reason'],
            'body' => $payload['body'],
            'attachments' => $payload['attachments'] ?? [],
            'due_date' => $payload['due_date'] ?? null,
            'status' => 'new',
            'metadata' => [
                'history' => [
                    [
                        'type' => 'created',
                        'author_id' => $user->id,
                        'author_name' => $user->name,
                        'created_at' => now()->toISOString(),
                        'body' => $payload['body'],
                    ],
                ],
            ],
        ]);

        return [
            'issue' => $this->mapIssue($issue->load(['author:id,name', 'project:id,name', 'contract:id,number,subject', 'performanceAct:id,act_document_number,amount', 'file:id,name,original_name']), true),
        ];
    }

    public function addIssueComment(User $user, int $organizationId, CustomerIssue $issue, array $payload): ?array
    {
        if ((int) $issue->organization_id !== $organizationId) {
            return null;
        }

        if (!$this->canAccessWorkflowProject($organizationId, $issue->project_id, $user)) {
            return null;
        }

        $issue->comments()->create([
            'organization_id' => $organizationId,
            'author_user_id' => $user->id,
            'body' => $payload['body'],
            'attachments' => $payload['attachments'] ?? [],
        ]);

        if ($issue->status === 'new') {
            $issue->update(['status' => 'in_progress']);
        }

        $metadata = $issue->metadata ?? [];
        $history = is_array($metadata['history'] ?? null) ? $metadata['history'] : [];
        $history[] = [
            'type' => 'comment_added',
            'author_id' => $user->id,
            'author_name' => $user->name,
            'created_at' => now()->toISOString(),
            'body' => $payload['body'],
        ];

        $issue->update([
            'metadata' => array_merge($metadata, [
                'history' => $history,
                'last_response_at' => now()->toISOString(),
            ]),
        ]);

        return $this->getIssue($organizationId, $issue->fresh(['author:id,name', 'resolver:id,name', 'project:id,name', 'contract:id,number,subject', 'performanceAct:id,act_document_number,amount', 'file:id,name,original_name', 'comments.author:id,name']), $user);
    }

    public function resolveIssue(User $user, int $organizationId, CustomerIssue $issue, string $status): ?array
    {
        if ((int) $issue->organization_id !== $organizationId) {
            return null;
        }

        if (!$this->canAccessWorkflowProject($organizationId, $issue->project_id, $user)) {
            return null;
        }

        $metadata = $issue->metadata ?? [];
        $history = is_array($metadata['history'] ?? null) ? $metadata['history'] : [];
        $history[] = [
            'type' => 'status_changed',
            'author_id' => $user->id,
            'author_name' => $user->name,
            'created_at' => now()->toISOString(),
            'status' => $status,
        ];

        $issue->update([
            'status' => $status,
            'resolved_at' => now(),
            'resolved_by_user_id' => $user->id,
            'metadata' => array_merge($metadata, [
                'history' => $history,
                'last_response_at' => now()->toISOString(),
            ]),
        ]);

        return $this->getIssue($organizationId, $issue->fresh(['author:id,name', 'resolver:id,name', 'project:id,name', 'contract:id,number,subject', 'performanceAct:id,act_document_number,amount', 'file:id,name,original_name', 'comments.author:id,name']), $user);
    }

    public function getRequests(int $organizationId, array $filters = [], ?User $user = null): array
    {
        $scopedProjectIds = $this->resolveScopedProjectIds($organizationId, $user);
        $requests = CustomerRequest::query()
            ->with(['author:id,name', 'project:id,name', 'contract:id,number,subject', 'comments.author:id,name'])
            ->where('organization_id', $organizationId)
            ->when($scopedProjectIds !== null, fn (Builder $builder) => $builder->whereIn('project_id', $scopedProjectIds->all()))
            ->when(isset($filters['status']), fn (Builder $builder) => $builder->where('status', (string) $filters['status']))
            ->when(isset($filters['project_id']), fn (Builder $builder) => $builder->where('project_id', (int) $filters['project_id']))
            ->when(isset($filters['request_type']), fn (Builder $builder) => $builder->where('request_type', (string) $filters['request_type']))
            ->when(isset($filters['due_state']) && $filters['due_state'] === 'overdue', fn (Builder $builder) => $builder->whereNotIn('status', ['completed', 'rejected'])->whereDate('due_date', '<', now()->toDateString()))
            ->latest('updated_at')
            ->limit(50)
            ->get();

        return [
            'items' => $requests->map(fn (CustomerRequest $request): array => $this->mapRequest($request))->all(),
        ];
    }

    public function getRequest(int $organizationId, CustomerRequest $request, ?User $user = null): ?array
    {
        if ((int) $request->organization_id !== $organizationId) {
            return null;
        }

        if (!$this->canAccessWorkflowProject($organizationId, $request->project_id, $user)) {
            return null;
        }

        $request->loadMissing(['author:id,name', 'resolver:id,name', 'project:id,name', 'contract:id,number,subject', 'comments.author:id,name']);

        return [
            'request' => $this->mapRequest($request, true),
        ];
    }

    public function createRequest(User $user, int $organizationId, array $payload): array
    {
        $payload = $this->normalizeRequestPayload($organizationId, $payload, $user);

        $request = CustomerRequest::create([
            'organization_id' => $organizationId,
            'author_user_id' => $user->id,
            'project_id' => $payload['project_id'] ?? null,
            'contract_id' => $payload['contract_id'] ?? null,
            'title' => $payload['title'],
            'request_type' => $payload['request_type'],
            'body' => $payload['body'],
            'attachments' => $payload['attachments'] ?? [],
            'due_date' => $payload['due_date'] ?? null,
            'status' => 'new',
            'metadata' => [
                'history' => [
                    [
                        'type' => 'created',
                        'author_id' => $user->id,
                        'author_name' => $user->name,
                        'created_at' => now()->toISOString(),
                        'body' => $payload['body'],
                    ],
                ],
            ],
        ]);

        return [
            'request' => $this->mapRequest($request->load(['author:id,name', 'project:id,name', 'contract:id,number,subject']), true),
        ];
    }

    public function addRequestComment(User $user, int $organizationId, CustomerRequest $request, array $payload): ?array
    {
        if ((int) $request->organization_id !== $organizationId) {
            return null;
        }

        if (!$this->canAccessWorkflowProject($organizationId, $request->project_id, $user)) {
            return null;
        }

        $request->comments()->create([
            'organization_id' => $organizationId,
            'author_user_id' => $user->id,
            'body' => $payload['body'],
            'attachments' => $payload['attachments'] ?? [],
        ]);

        if ($request->status === 'new') {
            $request->update(['status' => 'in_progress']);
        }

        $metadata = $request->metadata ?? [];
        $history = is_array($metadata['history'] ?? null) ? $metadata['history'] : [];
        $history[] = [
            'type' => 'comment_added',
            'author_id' => $user->id,
            'author_name' => $user->name,
            'created_at' => now()->toISOString(),
            'body' => $payload['body'],
        ];

        $request->update([
            'metadata' => array_merge($metadata, [
                'history' => $history,
                'last_response_at' => now()->toISOString(),
            ]),
        ]);

        return $this->getRequest($organizationId, $request->fresh(['author:id,name', 'resolver:id,name', 'project:id,name', 'contract:id,number,subject', 'comments.author:id,name']), $user);
    }

    public function updateRequestStatus(User $user, int $organizationId, CustomerRequest $request, string $status): ?array
    {
        if ((int) $request->organization_id !== $organizationId) {
            return null;
        }

        if (!$this->canAccessWorkflowProject($organizationId, $request->project_id, $user)) {
            return null;
        }

        $metadata = $request->metadata ?? [];
        $history = is_array($metadata['history'] ?? null) ? $metadata['history'] : [];
        $history[] = [
            'type' => 'status_changed',
            'author_id' => $user->id,
            'author_name' => $user->name,
            'created_at' => now()->toISOString(),
            'status' => $status,
        ];

        $request->update([
            'status' => $status,
            'resolved_at' => in_array($status, ['completed', 'rejected'], true) ? now() : null,
            'resolved_by_user_id' => in_array($status, ['completed', 'rejected'], true) ? $user->id : null,
            'metadata' => array_merge($metadata, [
                'history' => $history,
                'last_response_at' => now()->toISOString(),
            ]),
        ]);

        return $this->getRequest($organizationId, $request->fresh(['author:id,name', 'resolver:id,name', 'project:id,name', 'contract:id,number,subject', 'comments.author:id,name']), $user);
    }

    public function getTeam(User $user, int $organizationId): array
    {
        $organization = Organization::query()
            ->with(['users' => function ($query): void {
                $query->wherePivot('is_active', true);
            }])
            ->findOrFail($organizationId);

        $authContext = AuthorizationContext::getOrganizationContext($organizationId);

        return [
            'members' => $organization->users->map(function (User $member) use ($authContext, $organization): array {
                return $this->mapTeamMember($member, $organization->id, $authContext);
            })->all(),
            'available_roles' => $this->loadCustomerRoleCatalog(),
            'current_user_id' => $user->id,
        ];
    }

    public function getTeamMember(User $viewer, int $organizationId, User $member): ?array
    {
        if (!$member->belongsToOrganization($organizationId)) {
            return null;
        }

        $authContext = AuthorizationContext::getOrganizationContext($organizationId);

        return [
            'member' => $this->mapTeamMember($member->loadMissing('organizations'), $organizationId, $authContext, true),
            'current_user_id' => $viewer->id,
        ];
    }

    public function getNotificationSettings(User $user, int $organizationId): array
    {
        $settings = $user->settings ?? [];
        $customerSettings = $settings['customer_notification_settings'] ?? $this->defaultNotificationSettings();

        return [
            'settings' => $customerSettings,
            'organization_id' => $organizationId,
        ];
    }

    public function updateNotificationSettings(User $user, int $organizationId, array $payload): array
    {
        $settings = $user->settings ?? [];
        $settings['customer_notification_settings'] = [
            'channels' => $payload['channels'],
            'events' => $payload['events'],
            'updated_at' => now()->toISOString(),
            'organization_id' => $organizationId,
        ];

        $user->update([
            'settings' => $settings,
        ]);

        return [
            'settings' => $settings['customer_notification_settings'],
            'organization_id' => $organizationId,
        ];
    }

    public function getDocuments(int $organizationId, ?Project $project = null, ?User $user = null): array
    {
        $documents = $this->baseDocumentQuery($organizationId, $project, $user)
            ->latest('updated_at')
            ->limit(50)
            ->get();

        $projects = $project
            ? collect([$project->id => $project->loadMissing('projectAddress')])
            : $this->baseProjectQuery($organizationId, $user)->get()->keyBy('id');

        return [
            'items' => $documents->map(
                fn (File $file): array => $this->mapDocument($file, $projects)
            )->all(),
        ];
    }

    public function getApprovals(int $organizationId, ?Project $project = null, ?User $user = null): array
    {
        $approvals = $this->baseApprovalQuery($organizationId, $project, $user)
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

    public function getConversations(int $organizationId, ?Project $project = null, ?User $user = null): array
    {
        return [
            'items' => [],
            'meta' => [
                'organization_id' => $organizationId,
                'project_id' => $project?->id,
                'scoped' => $this->resolveScopedProjectIds($organizationId, $user) !== null,
            ],
        ];
    }

    public function getNotifications(User $user, int $organizationId, array $filters = []): array
    {
        $notifications = Notification::query()
            ->forUser($user)
            ->forOrganization($organizationId)
            ->when(isset($filters['unread']) && filter_var($filters['unread'], FILTER_VALIDATE_BOOLEAN), fn (Builder $builder) => $builder->unread())
            ->when(isset($filters['event_type']), fn (Builder $builder) => $builder->where('notification_type', (string) $filters['event_type']))
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
                'filters' => $filters,
            ],
        ];
    }

    public function getDisciplineAnalytics(User $user, int $organizationId): array
    {
        return [
            'summary' => $this->buildDisciplineSummary($organizationId, $user),
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

    private function baseProjectQuery(int $organizationId, ?User $user = null): Builder
    {
        $query = Project::query()
            ->with(['projectAddress', 'organization', 'organizations'])
            ->accessibleByOrganization($organizationId)
            ->where('is_archived', false)
            ->orderByDesc('updated_at');

        $scopedProjectIds = $this->resolveScopedProjectIds($organizationId, $user);

        if ($scopedProjectIds !== null) {
            $query->whereKey($scopedProjectIds->all());
        }

        return $query;
    }

    private function baseDocumentQuery(int $organizationId, ?Project $project = null, ?User $user = null): Builder
    {
        $query = File::query()->where('organization_id', $organizationId);
        $scopedProjectIds = $this->resolveScopedProjectIds($organizationId, $user);

        if ($project) {
            if ($scopedProjectIds !== null && !$scopedProjectIds->contains($project->id)) {
                $query->whereRaw('1 = 0');
                return $query;
            }

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
        } elseif ($scopedProjectIds !== null) {
            $approvalIds = ContractPerformanceAct::query()
                ->whereIn('project_id', $scopedProjectIds->all())
                ->pluck('id');

            $query->where(function (Builder $builder) use ($scopedProjectIds, $approvalIds): void {
                $builder->where(function (Builder $inner) use ($scopedProjectIds): void {
                    $inner
                        ->where('fileable_type', Project::class)
                        ->whereIn('fileable_id', $scopedProjectIds->all());
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

    private function baseApprovalQuery(int $organizationId, ?Project $project = null, ?User $user = null): Builder
    {
        $query = ContractPerformanceAct::query()
            ->whereHas('contract', function (Builder $contractQuery) use ($organizationId): void {
                $contractQuery
                    ->where('contract_side_type', ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR->value)
                    ->where('organization_id', $organizationId);
            })
            ->when(
                $project !== null,
                fn (Builder $builder): Builder => $builder->where('project_id', $project->id),
                fn (Builder $builder): Builder => $builder->whereHas(
                    'project',
                    fn (Builder $projectQuery): Builder => $projectQuery->accessibleByOrganization($organizationId)
                )
            );

        $scopedProjectIds = $this->resolveScopedProjectIds($organizationId, $user);

        if ($scopedProjectIds !== null) {
            $query->whereIn('project_id', $scopedProjectIds->all());
        }

        return $query;
    }

    private function baseCustomerContractQuery(int $organizationId, array $filters = [], ?Project $project = null, ?User $user = null): Builder
    {
        $query = Contract::query()
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
            ->when(isset($filters['contractor_search']), function (Builder $builder) use ($filters): void {
                $search = (string) $filters['contractor_search'];
                $builder->whereHas('contractor', function (Builder $contractorQuery) use ($search): void {
                    $contractorQuery->where('name', 'ilike', '%' . $search . '%');
                });
            })
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

        $scopedProjectIds = $this->resolveScopedProjectIds($organizationId, $user);

        if ($scopedProjectIds !== null) {
            $query->where(function (Builder $builder) use ($scopedProjectIds): void {
                $builder
                    ->whereIn('project_id', $scopedProjectIds->all())
                    ->orWhereHas('projects', fn (Builder $projectsQuery) => $projectsQuery->whereIn('projects.id', $scopedProjectIds->all()));
            });
        }

        return $query;
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
            ? (float) $contract->payments->sum('paid_amount')
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

    private function mapCustomerContractDetails(Contract $contract): array
    {
        $base = $this->mapCustomerContract($contract);
        $agreements = $contract->relationLoaded('agreements') ? $contract->agreements : collect();
        $acts = $contract->relationLoaded('performanceActs') ? $contract->performanceActs : collect();
        $payments = $contract->relationLoaded('payments') ? $contract->payments : collect();
        $events = $contract->relationLoaded('stateEvents') ? $contract->stateEvents : collect();

        return array_merge($base, [
            'financial_summary' => [
                'total_amount' => $base['total_amount'],
                'performed_amount' => $base['performed_amount'],
                'paid_amount' => $base['paid_amount'],
                'remaining_amount' => $base['remaining_amount'],
                'advance_amount' => $contract->actual_advance_amount !== null ? (float) $contract->actual_advance_amount : null,
                'planned_advance_amount' => $contract->planned_advance_amount !== null ? (float) $contract->planned_advance_amount : null,
                'warranty_retention_amount' => (float) $contract->warranty_retention_amount,
            ],
            'agreements_summary' => [
                'count' => $agreements->count(),
                'total_change' => round((float) $agreements->sum('change_amount'), 2),
                'items' => $agreements->map(fn ($agreement): array => [
                    'id' => $agreement->id,
                    'number' => $agreement->number,
                    'date' => optional($agreement->agreement_date)?->format('Y-m-d'),
                    'change_amount' => $agreement->change_amount !== null ? (float) $agreement->change_amount : null,
                ])->values()->all(),
            ],
            'acts_summary' => [
                'count' => $acts->count(),
                'approved_count' => $acts->where('is_approved', true)->count(),
                'items' => $acts->map(fn ($act): array => [
                    'id' => $act->id,
                    'number' => $act->act_document_number ?: '#' . $act->id,
                    'date' => optional($act->act_date)?->format('Y-m-d'),
                    'amount' => $act->amount !== null ? (float) $act->amount : null,
                    'status' => $act->is_approved ? 'approved' : 'pending',
                ])->values()->all(),
            ],
            'payments_summary' => [
                'count' => $payments->count(),
                'items' => $payments->map(fn ($payment): array => [
                    'id' => $payment->id,
                    'date' => optional($payment->document_date)?->format('Y-m-d'),
                    'amount' => $payment->paid_amount !== null ? (float) $payment->paid_amount : null,
                    'type' => $payment->metadata['contract_payment_type'] ?? $payment->invoice_type?->value,
                    'reference' => $payment->metadata['reference_document_number'] ?? $payment->document_number,
                ])->values()->all(),
            ],
            'timeline' => $this->buildContractTimeline($contract, $agreements, $acts, $payments, $events),
        ]);
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
            'projectId' => $project?->id,
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
            'projectId' => $project?->id,
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
            'contractor_search' => isset($filters['contractor_search']) ? trim((string) $filters['contractor_search']) : null,
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
            'eventType' => $notification->notification_type ?: $notification->type,
            'createdAtLabel' => $notification->created_at?->format('d.m.Y H:i'),
            'created_at' => $notification->created_at?->toISOString(),
            'tone' => $this->resolveNotificationTone($notification),
            'statusLabel' => $notification->read_at ? 'Прочитано' : 'Не прочитано',
            'isUnread' => $notification->read_at === null,
            'project' => isset($data['project']) && is_array($data['project']) ? $data['project'] : null,
            'related_entity' => isset($data['related_entity']) && is_array($data['related_entity']) ? $data['related_entity'] : null,
            'priority' => $notification->priority ?: 'normal',
        ];
    }

    private function mapIssue(CustomerIssue $issue, bool $withComments = false): array
    {
        $metadata = is_array($issue->metadata) ? $issue->metadata : [];
        $history = is_array($metadata['history'] ?? null) ? $metadata['history'] : [];
        $lastResponseAt = $metadata['last_response_at'] ?? $issue->comments->max('created_at');
        $overdueSince = $issue->due_date && $issue->due_date->isPast() && !in_array($issue->status, ['resolved', 'rejected'], true)
            ? $issue->due_date->toDateString()
            : null;

        return [
            'id' => $issue->id,
            'title' => $issue->title,
            'issue_reason' => $issue->issue_reason,
            'body' => $issue->body,
            'status' => $issue->status,
            'status_label' => $this->resolvePortalWorkflowStatusLabel($issue->status),
            'due_date' => optional($issue->due_date)?->format('Y-m-d'),
            'attachments' => $issue->attachments ?? [],
            'author' => $issue->author ? [
                'id' => $issue->author->id,
                'name' => $issue->author->name,
            ] : null,
            'resolver' => $issue->resolver ? [
                'id' => $issue->resolver->id,
                'name' => $issue->resolver->name,
            ] : null,
            'assignee' => isset($metadata['assignee']) && is_array($metadata['assignee']) ? $metadata['assignee'] : null,
            'project' => $issue->project ? [
                'id' => $issue->project->id,
                'name' => $issue->project->name,
            ] : null,
            'contract' => $issue->contract ? [
                'id' => $issue->contract->id,
                'number' => $issue->contract->number,
                'subject' => $issue->contract->subject,
            ] : null,
            'approval' => $issue->performanceAct ? [
                'id' => $issue->performanceAct->id,
                'number' => $issue->performanceAct->act_document_number ?: '#' . $issue->performanceAct->id,
                'amount' => $issue->performanceAct->amount !== null ? (float) $issue->performanceAct->amount : null,
            ] : null,
            'document' => $issue->file ? [
                'id' => $issue->file->id,
                'title' => $issue->file->original_name ?: $issue->file->name,
            ] : null,
            'comments' => $withComments
                ? $issue->comments->map(fn (CustomerPortalComment $comment): array => $this->mapComment($comment))->all()
                : [],
            'history' => $withComments ? array_values($history) : [],
            'last_response_at' => $lastResponseAt instanceof \Carbon\CarbonInterface ? $lastResponseAt->toISOString() : (is_string($lastResponseAt) ? $lastResponseAt : null),
            'overdue_since' => $overdueSince,
            'created_at' => $issue->created_at?->toISOString(),
            'updated_at' => $issue->updated_at?->toISOString(),
        ];
    }

    private function mapRequest(CustomerRequest $request, bool $withComments = false): array
    {
        $metadata = is_array($request->metadata) ? $request->metadata : [];
        $history = is_array($metadata['history'] ?? null) ? $metadata['history'] : [];
        $lastResponseAt = $metadata['last_response_at'] ?? $request->comments->max('created_at');
        $overdueSince = $request->due_date && $request->due_date->isPast() && !in_array($request->status, ['completed', 'rejected'], true)
            ? $request->due_date->toDateString()
            : null;

        return [
            'id' => $request->id,
            'title' => $request->title,
            'request_type' => $request->request_type,
            'body' => $request->body,
            'status' => $request->status,
            'status_label' => $this->resolvePortalWorkflowStatusLabel($request->status),
            'due_date' => optional($request->due_date)?->format('Y-m-d'),
            'attachments' => $request->attachments ?? [],
            'author' => $request->author ? [
                'id' => $request->author->id,
                'name' => $request->author->name,
            ] : null,
            'resolver' => $request->resolver ? [
                'id' => $request->resolver->id,
                'name' => $request->resolver->name,
            ] : null,
            'assignee' => isset($metadata['assignee']) && is_array($metadata['assignee']) ? $metadata['assignee'] : null,
            'project' => $request->project ? [
                'id' => $request->project->id,
                'name' => $request->project->name,
            ] : null,
            'contract' => $request->contract ? [
                'id' => $request->contract->id,
                'number' => $request->contract->number,
                'subject' => $request->contract->subject,
            ] : null,
            'comments' => $withComments
                ? $request->comments->map(fn (CustomerPortalComment $comment): array => $this->mapComment($comment))->all()
                : [],
            'history' => $withComments ? array_values($history) : [],
            'last_response_at' => $lastResponseAt instanceof \Carbon\CarbonInterface ? $lastResponseAt->toISOString() : (is_string($lastResponseAt) ? $lastResponseAt : null),
            'overdue_since' => $overdueSince,
            'created_at' => $request->created_at?->toISOString(),
            'updated_at' => $request->updated_at?->toISOString(),
        ];
    }

    private function mapComment(CustomerPortalComment $comment): array
    {
        return [
            'id' => $comment->id,
            'body' => $comment->body,
            'attachments' => $comment->attachments ?? [],
            'author' => $comment->author ? [
                'id' => $comment->author->id,
                'name' => $comment->author->name,
            ] : null,
            'created_at' => $comment->created_at?->toISOString(),
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

    private function mapTeamMember(User $member, int $organizationId, AuthorizationContext $authContext, bool $withDetails = false): array
    {
        $roles = $this->authorizationService->getUserRoleSlugs($member, [
            'organization_id' => $organizationId,
        ]);
        $accessibleProjects = Project::query()
            ->accessibleByOrganization($organizationId)
            ->where('is_archived', false)
            ->get(['projects.id', 'projects.name']);
        $assignedProjects = $member->assignedProjects()
            ->whereIn('projects.id', $accessibleProjects->pluck('id')->all())
            ->get(['projects.id', 'projects.name']);
        $accessHistory = $member->roleAssignments()
            ->where('context_id', $authContext->id)
            ->latest('updated_at')
            ->limit(5)
            ->get()
            ->map(fn ($assignment): array => [
                'id' => $assignment->id,
                'action' => $assignment->is_active ? 'role_assigned' : 'role_revoked',
                'role' => $assignment->role_slug,
                'created_at' => $assignment->updated_at?->toISOString(),
            ])
            ->all();

        return [
            'id' => $member->id,
            'name' => $member->name,
            'email' => $member->email,
            'phone' => $member->phone,
            'is_owner' => (bool) $member->pivot?->is_owner,
            'status' => $member->is_active ? 'active' : 'inactive',
            'roles' => $roles,
            'interfaces' => $this->resolveCustomerInterfaces($member, $authContext),
            'project_access' => $assignedProjects->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
            ])->all(),
            'has_full_project_access' => $assignedProjects->isEmpty(),
            'access_history' => $withDetails ? $accessHistory : [],
            'available_project_count' => $accessibleProjects->count(),
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

    private function resolveWorkflowStatusLabel(?string $status): string
    {
        return match ($status) {
            'in_progress' => 'В работе',
            'resolved' => 'Решено',
            'rejected' => 'Отклонено',
            default => 'Новое',
        };
    }

    private function resolvePortalWorkflowStatusLabel(?string $status): string
    {
        return match ($status) {
            'accepted' => 'Принят',
            'in_progress' => 'В работе',
            'waiting_response' => 'Ждет ответа',
            'waiting_customer' => 'Ждет решения заказчика',
            'resolved' => 'Решено',
            'completed' => 'Завершен',
            'overdue' => 'Просрочен',
            'rejected' => 'Отклонено',
            default => 'Новое',
        };
    }

    private function canViewFinance(User $user, int $organizationId): bool
    {
        return $this->authorizationService->can($user, 'customer.finance.view', [
            'organization_id' => $organizationId,
        ]);
    }

    private function buildAttentionFeed(int $organizationId, ?User $user = null): array
    {
        $contracts = $this->baseCustomerContractQuery($organizationId, [], null, $user)
            ->with(['project:id,name'])
            ->latest('created_at')
            ->limit(4)
            ->get();
        $approvals = $this->baseApprovalQuery($organizationId, null, $user)
            ->with(['project:id,name'])
            ->where('is_approved', false)
            ->latest('act_date')
            ->limit(4)
            ->get();
        $scopedProjectIds = $this->resolveScopedProjectIds($organizationId, $user);
        $issues = CustomerIssue::query()
            ->with(['project:id,name'])
            ->where('organization_id', $organizationId)
            ->when($scopedProjectIds !== null, fn (Builder $builder) => $builder->whereIn('project_id', $scopedProjectIds->all()))
            ->whereIn('status', ['new', 'in_progress'])
            ->latest('updated_at')
            ->limit(4)
            ->get();
        $requests = CustomerRequest::query()
            ->with(['project:id,name'])
            ->where('organization_id', $organizationId)
            ->when($scopedProjectIds !== null, fn (Builder $builder) => $builder->whereIn('project_id', $scopedProjectIds->all()))
            ->whereIn('status', ['new', 'in_progress'])
            ->latest('updated_at')
            ->limit(4)
            ->get();

        return [
            'contracts' => $contracts->map(fn (Contract $contract): array => [
                'id' => 'contract-' . $contract->id,
                'type' => 'contract',
                'title' => $contract->number,
                'subtitle' => $contract->project?->name,
                'status' => $contract->status?->value ?? (string) $contract->status,
                'priority' => 'info',
                'project' => $contract->project ? ['id' => $contract->project->id, 'name' => $contract->project->name] : null,
                'related_entity' => ['type' => 'contract', 'id' => $contract->id],
                'created_at' => $contract->created_at?->toISOString(),
            ])->all(),
            'approvals' => $approvals->map(fn (ContractPerformanceAct $approval): array => [
                'id' => 'approval-' . $approval->id,
                'type' => 'approval',
                'title' => 'Акт ' . ($approval->act_document_number ?: '#' . $approval->id),
                'subtitle' => $approval->project?->name,
                'status' => $approval->is_approved ? 'approved' : 'pending',
                'priority' => 'warning',
                'project' => $approval->project ? ['id' => $approval->project->id, 'name' => $approval->project->name] : null,
                'related_entity' => ['type' => 'approval', 'id' => $approval->id],
                'created_at' => $approval->updated_at?->toISOString(),
            ])->all(),
            'issues' => $issues->map(fn (CustomerIssue $issue): array => [
                'id' => 'issue-' . $issue->id,
                'type' => 'issue',
                'title' => $issue->title,
                'subtitle' => $this->resolvePortalWorkflowStatusLabel($issue->status),
                'status' => $issue->status,
                'priority' => $this->resolveWorkflowPriority($issue->status, $issue->due_date),
                'project' => $issue->project ? ['id' => $issue->project->id, 'name' => $issue->project->name] : null,
                'related_entity' => ['type' => 'issue', 'id' => $issue->id],
                'created_at' => $issue->updated_at?->toISOString(),
            ])->all(),
            'requests' => $requests->map(fn (CustomerRequest $request): array => [
                'id' => 'request-' . $request->id,
                'type' => 'request',
                'title' => $request->title,
                'subtitle' => $this->resolvePortalWorkflowStatusLabel($request->status),
                'status' => $request->status,
                'priority' => $this->resolveWorkflowPriority($request->status, $request->due_date),
                'project' => $request->project ? ['id' => $request->project->id, 'name' => $request->project->name] : null,
                'related_entity' => ['type' => 'request', 'id' => $request->id],
                'created_at' => $request->updated_at?->toISOString(),
            ])->all(),
        ];
    }

    private function buildFinanceSummaryPayload(int $organizationId, ?User $user = null): array
    {
        $contracts = $this->baseCustomerContractQuery($organizationId, [], null, $user)
            ->with(['project:id,name', 'performanceActs:id,contract_id,amount,is_approved', 'payments:id,contract_id,amount'])
            ->get();
        $projects = $this->baseProjectQuery($organizationId, $user)->get();

        $totals = $this->calculateContractsTotals($contracts);

        return [
            'totals' => $totals,
            'projects' => $projects->map(fn (Project $project): array => $this->buildProjectFinancePayload($organizationId, $project, $user))->all(),
        ];
    }

    private function buildProjectFinancePayload(int $organizationId, Project $project, ?User $user = null): array
    {
        $contracts = $this->baseCustomerContractQuery($organizationId, ['project_id' => $project->id], $project, $user)
            ->with(['performanceActs:id,contract_id,amount,is_approved', 'payments:id,contract_id,amount'])
            ->get();

        $totals = $this->calculateContractsTotals($contracts);

        return [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
            ],
            'totals' => $totals,
            'deviation' => [
                'planned_budget' => $project->budget_amount !== null ? (float) $project->budget_amount : null,
                'contracts_total' => $totals['total_amount'],
                'delta' => $project->budget_amount !== null
                    ? round((float) $project->budget_amount - (float) $totals['total_amount'], 2)
                    : null,
                'performed_vs_paid_delta' => round((float) $totals['performed_amount'] - (float) $totals['paid_amount'], 2),
                'payment_delay_amount' => round(max(0, (float) $totals['performed_amount'] - (float) $totals['paid_amount']), 2),
                'problem_flags' => $this->resolveFinanceDeviationFlags($project, $totals),
            ],
        ];
    }

    private function calculateContractsTotals(Collection $contracts): array
    {
        $totalAmount = 0.0;
        $performedAmount = 0.0;
        $paidAmount = 0.0;
        $advanceAmount = 0.0;
        $retentionAmount = 0.0;

        foreach ($contracts as $contract) {
            $totalAmount += (float) ($contract->total_amount ?? 0);
            $performedAmount += (float) $contract->performanceActs->where('is_approved', true)->sum('amount');
            $paidAmount += (float) $contract->payments->sum('paid_amount');
            $advanceAmount += (float) ($contract->actual_advance_amount ?? 0);
            $retentionAmount += (float) $contract->warranty_retention_amount;
        }

        return [
            'total_amount' => round($totalAmount, 2),
            'performed_amount' => round($performedAmount, 2),
            'paid_amount' => round($paidAmount, 2),
            'remaining_amount' => round(max(0, $totalAmount - $performedAmount), 2),
            'advance_amount' => round($advanceAmount, 2),
            'retention_amount' => round($retentionAmount, 2),
        ];
    }

    private function buildProjectRisks(int $organizationId, ?User $user = null): array
    {
        return $this->baseProjectQuery($organizationId, $user)
            ->limit(6)
            ->get()
            ->map(fn (Project $project): array => $this->buildSingleProjectRisk($organizationId, $project, $user))
            ->filter(fn (array $risk): bool => count($risk['flags']) > 0)
            ->values()
            ->all();
    }

    private function buildSingleProjectRisk(int $organizationId, Project $project, ?User $user = null): array
    {
        $contracts = $this->baseCustomerContractQuery($organizationId, ['project_id' => $project->id], $project, $user)
            ->with(['performanceActs:id,contract_id,amount,is_approved'])
            ->get();
        $pendingApprovals = $this->baseApprovalQuery($organizationId, $project, $user)->where('is_approved', false)->count();
        $documentsWithoutReaction = $this->baseDocumentQuery($organizationId, $project, $user)
            ->where('updated_at', '<', now()->subDays(14))
            ->count();
        $flags = [];

        if ($pendingApprovals > 0) {
            $flags[] = 'Есть акты на согласовании';
        }

        if ($project->end_date !== null && $project->end_date->isPast() && $project->status !== 'completed') {
            $flags[] = 'Срок проекта истек';
        }

        if ($contracts->isEmpty()) {
            $flags[] = 'Нет договоров заказчика';
        }

        if ($documentsWithoutReaction > 0) {
            $flags[] = 'Есть документы без реакции';
        }

        $flags = array_values(array_unique(array_merge(
            $flags,
            $this->resolveFinanceDeviationFlags($project, $this->calculateContractsTotals($contracts))
        )));

        return [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
            ],
            'flags' => $flags,
            'pending_approvals' => $pendingApprovals,
            'documents_without_reaction' => $documentsWithoutReaction,
        ];
    }

    private function buildRecentChanges(int $organizationId, ?User $user = null): array
    {
        $contracts = $this->baseCustomerContractQuery($organizationId, [], null, $user)
            ->with(['project:id,name'])
            ->latest('updated_at')
            ->limit(5)
            ->get()
            ->map(fn (Contract $contract): array => [
                'type' => 'contract',
                'id' => $contract->id,
                'title' => $contract->number,
                'subtitle' => $contract->project?->name,
                'created_at' => $contract->updated_at?->toISOString(),
            ]);
        $documents = $this->baseDocumentQuery($organizationId, null, $user)
            ->latest('updated_at')
            ->limit(5)
            ->get()
            ->map(fn (File $file): array => [
                'type' => 'document',
                'id' => $file->id,
                'title' => $file->original_name ?: $file->name,
                'subtitle' => $file->category ?: $file->type,
                'created_at' => $file->updated_at?->toISOString(),
            ]);

        $scopedProjectIds = $this->resolveScopedProjectIds($organizationId, $user);
        $issues = CustomerIssue::query()
            ->where('organization_id', $organizationId)
            ->when($scopedProjectIds !== null, fn (Builder $builder) => $builder->whereIn('project_id', $scopedProjectIds->all()))
            ->latest('updated_at')
            ->limit(5)
            ->get()
            ->map(fn (CustomerIssue $issue): array => [
                'type' => 'issue',
                'id' => $issue->id,
                'title' => $issue->title,
                'subtitle' => $this->resolvePortalWorkflowStatusLabel($issue->status),
                'created_at' => $issue->updated_at?->toISOString(),
            ]);

        return $contracts
            ->merge($documents)
            ->merge($issues)
            ->sortByDesc('created_at')
            ->take(8)
            ->values()
            ->all();
    }

    private function buildProjectKeyContracts(int $organizationId, Project $project, ?User $user = null): array
    {
        return $this->baseCustomerContractQuery($organizationId, ['project_id' => $project->id], $project, $user)
            ->latest('date')
            ->limit(3)
            ->get(['id', 'number', 'subject', 'status', 'total_amount'])
            ->map(fn (Contract $contract): array => [
                'id' => $contract->id,
                'number' => $contract->number,
                'subject' => $contract->subject,
                'status' => $contract->status?->value ?? (string) $contract->status,
                'total_amount' => $contract->total_amount !== null ? (float) $contract->total_amount : null,
            ])->all();
    }

    private function buildContractTimeline(
        Contract $contract,
        Collection $agreements,
        Collection $acts,
        Collection $payments,
        Collection $events
    ): array {
        $items = collect([
            [
                'type' => 'contract_created',
                'title' => 'Договор создан',
                'date' => optional($contract->date ?? $contract->created_at)?->format('Y-m-d'),
            ],
        ]);

        $items = $items
            ->merge($agreements->map(fn ($agreement): array => [
                'type' => 'agreement',
                'title' => 'Дополнительное соглашение ' . $agreement->number,
                'date' => optional($agreement->agreement_date)?->format('Y-m-d'),
            ]))
            ->merge($acts->map(fn ($act): array => [
                'type' => 'approval',
                'title' => 'Акт ' . ($act->act_document_number ?: '#' . $act->id),
                'date' => optional($act->act_date)?->format('Y-m-d'),
            ]))
            ->merge($payments->map(fn ($payment): array => [
                'type' => 'payment',
                'title' => 'Платеж',
                'date' => optional($payment->payment_date)?->format('Y-m-d'),
            ]))
            ->merge($events->map(fn ($event): array => [
                'type' => 'status',
                'title' => Str::headline((string) $event->event_type),
                'date' => $event->created_at?->format('Y-m-d'),
            ]));

        return $items
            ->filter(fn (array $item): bool => !empty($item['date']))
            ->sortByDesc('date')
            ->values()
            ->all();
    }

    private function buildDisciplineSummary(int $organizationId, ?User $user = null): array
    {
        $scopedProjectIds = $this->resolveScopedProjectIds($organizationId, $user);

        $issuesQuery = CustomerIssue::query()
            ->where('organization_id', $organizationId)
            ->when($scopedProjectIds !== null, fn (Builder $builder) => $builder->whereIn('project_id', $scopedProjectIds->all()));
        $requestsQuery = CustomerRequest::query()
            ->where('organization_id', $organizationId)
            ->when($scopedProjectIds !== null, fn (Builder $builder) => $builder->whereIn('project_id', $scopedProjectIds->all()));
        $approvalsQuery = $this->baseApprovalQuery($organizationId, null, $user);

        $issueResponseHours = $issuesQuery->get()
            ->map(function (CustomerIssue $issue): ?float {
                $lastResponseAt = data_get($issue->metadata, 'last_response_at');
                if (!$lastResponseAt || !$issue->created_at) {
                    return null;
                }

                return round($issue->created_at->diffInMinutes($lastResponseAt) / 60, 1);
            })
            ->filter();
        $requestResponseHours = $requestsQuery->get()
            ->map(function (CustomerRequest $request): ?float {
                $lastResponseAt = data_get($request->metadata, 'last_response_at');
                if (!$lastResponseAt || !$request->created_at) {
                    return null;
                }

                return round($request->created_at->diffInMinutes($lastResponseAt) / 60, 1);
            })
            ->filter();
        $approvalCycleHours = (clone $approvalsQuery)
            ->where(function (Builder $builder): void {
                $builder
                    ->whereNotNull('approval_date')
                    ->orWhere('is_approved', true);
            })
            ->get()
            ->map(function (ContractPerformanceAct $approval): ?float {
                if (!$approval->created_at) {
                    return null;
                }

                $completedAt = $approval->approval_date?->startOfDay() ?? ($approval->is_approved ? $approval->updated_at : null);
                if ($completedAt === null) {
                    return null;
                }

                return round($approval->created_at->diffInMinutes($completedAt) / 60, 1);
            })
            ->filter();

        return [
            'issue_response_hours' => $issueResponseHours->isEmpty() ? null : round($issueResponseHours->avg(), 1),
            'approval_cycle_hours' => $approvalCycleHours->isEmpty() ? 0.0 : round($approvalCycleHours->avg(), 1),
            'rework_count' => (clone $approvalsQuery)->where('is_approved', false)->count(),
            'overdue_actions_count' => (clone $issuesQuery)->whereNotIn('status', ['resolved', 'rejected'])->whereDate('due_date', '<', now()->toDateString())->count()
                + (clone $requestsQuery)->whereNotIn('status', ['completed', 'rejected'])->whereDate('due_date', '<', now()->toDateString())->count(),
            'stalled_items_count' => $this->baseDocumentQuery($organizationId, null, $user)->where('updated_at', '<', now()->subDays(14))->count()
                + (clone $approvalsQuery)->where('updated_at', '<', now()->subDays(14))->count(),
            'request_response_hours' => $requestResponseHours->isEmpty() ? null : round($requestResponseHours->avg(), 1),
        ];
    }

    private function resolveScopedProjectIds(int $organizationId, ?User $user = null): ?Collection
    {
        if ($user === null) {
            return null;
        }

        $assignedProjectIds = $user->assignedProjects()
            ->where(function (Builder $builder) use ($organizationId): void {
                $builder
                    ->where('projects.organization_id', $organizationId)
                    ->orWhereExists(function ($subQuery) use ($organizationId): void {
                        $subQuery
                            ->selectRaw('1')
                            ->from('project_organization')
                            ->whereColumn('project_organization.project_id', 'projects.id')
                            ->where('project_organization.organization_id', $organizationId)
                            ->where('project_organization.is_active', true);
                    });
            })
            ->pluck('projects.id');

        if ($assignedProjectIds->isEmpty()) {
            return null;
        }

        return $assignedProjectIds->map(fn ($id): int => (int) $id)->values();
    }

    private function resolveContractProjectIds(Contract $contract): Collection
    {
        $projectIds = collect();

        if ($contract->project_id !== null) {
            $projectIds->push((int) $contract->project_id);
        }

        $relatedProjectIds = $contract->relationLoaded('projects')
            ? $contract->projects->pluck('id')
            : $contract->projects()->pluck('projects.id');

        return $projectIds
            ->merge($relatedProjectIds)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    private function canAccessWorkflowProject(int $organizationId, ?int $projectId, ?User $user = null): bool
    {
        if ($projectId === null) {
            return true;
        }

        $scopedProjectIds = $this->resolveScopedProjectIds($organizationId, $user);

        if ($scopedProjectIds === null) {
            return true;
        }

        return $scopedProjectIds->contains((int) $projectId);
    }

    private function resolveFinanceDeviationFlags(Project $project, array $totals): array
    {
        $flags = [];

        if ((float) $totals['performed_amount'] > (float) $totals['paid_amount']) {
            $flags[] = 'Выполнение опережает оплату';
        }

        if ((float) $totals['paid_amount'] > (float) $totals['performed_amount']) {
            $flags[] = 'Оплата опережает выполнение';
        }

        if ($project->end_date !== null && $project->end_date->isPast() && $project->status !== 'completed') {
            $flags[] = 'Проектный срок под риском';
        }

        if ($project->budget_amount !== null && (float) $totals['total_amount'] > (float) $project->budget_amount) {
            $flags[] = 'Есть риск перерасхода бюджета';
        }

        return $flags;
    }

    private function isWorkflowOverdue(?string $status, mixed $dueDate): bool
    {
        return $dueDate instanceof \Carbon\CarbonInterface
            && $dueDate->isPast()
            && !in_array($status, ['resolved', 'rejected', 'completed'], true);
    }

    private function resolveWorkflowPriority(?string $status, mixed $dueDate): string
    {
        if ($this->isWorkflowOverdue($status, $dueDate)) {
            return 'warning';
        }

        return match ($status) {
            'new', 'waiting_response', 'waiting_customer', 'overdue' => 'warning',
            'resolved', 'completed' => 'success',
            default => 'primary',
        };
    }

    private function defaultNotificationSettings(): array
    {
        return [
            'channels' => [
                'in_app' => true,
                'email' => true,
            ],
            'events' => [
                'new_contract' => true,
                'new_approval' => true,
                'issue_waiting_response' => true,
                'request_deadline' => true,
                'contract_amount_changed' => true,
                'new_document' => true,
                'request_status_changed' => true,
                'project_deadline_changed' => true,
                'access_updated' => true,
                'finance_risk_detected' => true,
            ],
        ];
    }

    private function loadCustomerRoleCatalog(): array
    {
        $path = config_path('RoleDefinitions/customer');

        if (!FileSystem::exists($path)) {
            return [];
        }

        return collect(FileSystem::files($path))
            ->map(function ($file): ?array {
                $decoded = json_decode(FileSystem::get($file->getPathname()), true);

                if (!is_array($decoded) || !isset($decoded['slug'], $decoded['name'])) {
                    return null;
                }

                return [
                    'slug' => $decoded['slug'],
                    'name' => $decoded['name'],
                    'description' => $decoded['description'] ?? null,
                    'permissions' => $decoded['system_permissions'] ?? [],
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function normalizeIssuePayload(int $organizationId, array $payload, ?User $user = null): array
    {
        if (isset($payload['project_id']) && !$this->baseProjectQuery($organizationId, $user)->whereKey((int) $payload['project_id'])->exists()) {
            unset($payload['project_id']);
        }

        if (isset($payload['contract_id'])) {
            $contract = Contract::query()
                ->whereKey((int) $payload['contract_id'])
                ->where('organization_id', $organizationId)
                ->where('contract_side_type', ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR->value)
                ->first();

            if ($contract === null) {
                unset($payload['contract_id']);
            }
        }

        if (isset($payload['performance_act_id'])) {
            $approval = $this->baseApprovalQuery($organizationId, null, $user)->whereKey((int) $payload['performance_act_id'])->first();

            if ($approval === null) {
                unset($payload['performance_act_id']);
            }
        }

        if (isset($payload['file_id'])) {
            $file = File::query()
                ->whereKey((int) $payload['file_id'])
                ->where('organization_id', $organizationId)
                ->first();

            if ($file === null) {
                unset($payload['file_id']);
            }
        }

        return $payload;
    }

    private function normalizeRequestPayload(int $organizationId, array $payload, ?User $user = null): array
    {
        if (isset($payload['project_id']) && !$this->baseProjectQuery($organizationId, $user)->whereKey((int) $payload['project_id'])->exists()) {
            unset($payload['project_id']);
        }

        if (isset($payload['contract_id'])) {
            $contract = Contract::query()
                ->whereKey((int) $payload['contract_id'])
                ->where('organization_id', $organizationId)
                ->where('contract_side_type', ContractSideTypeEnum::CUSTOMER_TO_GENERAL_CONTRACTOR->value)
                ->first();

            if ($contract === null) {
                unset($payload['contract_id']);
            }
        }

        return $payload;
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
