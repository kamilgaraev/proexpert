<?php

declare(strict_types=1);

namespace App\Services\Customer;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\ContactForm;
use App\Models\ContractPerformanceAct;
use App\Models\File;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class CustomerPortalService
{
    public function __construct(
        private readonly AuthorizationService $authorizationService
    ) {
    }

    public function getDashboard(int $organizationId): array
    {
        $projects = $this->baseProjectQuery($organizationId)->get();
        $documentsCount = $this->baseDocumentQuery($organizationId)->count();
        $approvalsCount = $this->baseApprovalQuery($organizationId)->where('is_approved', false)->count();

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
                    'value' => '0',
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

    public function getProject(Project $project): array
    {
        $project->loadMissing(['projectAddress', 'organization']);

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
            ->with(['project.projectAddress'])
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

    public function getNotifications(int $organizationId): array
    {
        return [
            'items' => [],
            'meta' => [
                'organization_id' => $organizationId,
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
            'request' => [
                'id' => $contactForm->id,
                'status' => $contactForm->status,
                'subject' => $contactForm->subject,
                'created_at' => $contactForm->created_at?->toISOString(),
            ],
        ];
    }

    private function baseProjectQuery(int $organizationId): Builder
    {
        return Project::query()
            ->with(['projectAddress', 'organization'])
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

    private function mapProjectPreview(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'location' => $this->resolveProjectLocation($project),
            'phase' => $this->resolveProjectPhase($project),
            'completion' => $this->resolveProjectCompletion($project),
            'budgetLabel' => $this->formatMoney($project->budget_amount),
            'leadLabel' => $this->resolveLeadLabel($project),
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

        return [
            'id' => $approval->id,
            'title' => 'Акт ' . ($approval->act_document_number ?: '#' . $approval->id),
            'projectName' => $project?->name,
            'deadlineLabel' => $approval->is_approved
                ? 'Согласовано ' . ($approval->approval_date?->format('d.m.Y') ?? $dateLabel)
                : 'Ожидает решения с ' . $dateLabel,
            'status' => $approval->is_approved ? 'approved' : 'pending',
            'amount' => $this->formatMoney($approval->amount),
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
