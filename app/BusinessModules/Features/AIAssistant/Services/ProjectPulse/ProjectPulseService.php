<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\ProjectPulse;

use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseContext;
use App\BusinessModules\Features\AIAssistant\Models\ProjectPulseReport;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Models\User;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantReportAccessService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ProjectPulseService
{
    public function __construct(
        private readonly ProjectPulseFactCollector $factCollector,
        private readonly ProjectPulseRuleEngine $ruleEngine,
        private readonly ProjectPulseAiSynthesizer $aiSynthesizer,
        private readonly ProjectPulseFormatter $formatter,
    ) {
    }

    public function current(ProjectPulseContext $context): ?array
    {
        $actor = $this->actor($context->organizationId, $context->userId, $context->projectId);
        $query = app(AssistantDataAccessPolicy::class)->entityQuery($actor, $context->organizationId, 'project_pulse_report');
        if ($query === null) { throw new AccessDeniedHttpException(); }
        $existing = $query
            ->forOrganization($context->organizationId)
            ->forProject($context->projectId)
            ->whereDate('report_date', $context->date->toDateString())
            ->where('period_preset', $context->period)
            ->latest('generated_at')
            ->first();

        return $existing ? $this->formatter->format($existing) : null;
    }

    public function generate(ProjectPulseContext $context): array
    {
        $actor = $this->actor($context->organizationId, $context->userId, $context->projectId);
        $facts = $this->factCollector->collect($context);
        $policy = app(AssistantDataAccessPolicy::class);
        $domains = ['reports', 'projects'];
        foreach (['contracts', 'finance', 'warehouse', 'people', 'procurement', 'schedule', 'documents', 'quality', 'safety', 'change_management', 'handover_acceptance', 'machinery', 'production_labor', 'site_requests'] as $domain) {
            if ($policy->canReadDomain($actor, $context->organizationId, $domain)) {
                $domains[] = $domain;
            }
        }
        $refs = app(AssistantReportAccessService::class)->scopeReferences($actor, $context->organizationId, $context->projectId);
        $referenceForFact = static function ($fact): ?array {
            $ref = $fact->relatedEntity;
            if (! is_array($ref) || ! isset($ref['type'], $ref['id'])) { return null; }
            $type = match ($ref['type']) { 'contract_performance_act' => 'performance_act', 'warehouse_allocation' => 'warehouse_project_allocation', 'project_schedule_task' => 'schedule_task', default => $ref['type'] };
            return ['entity_type' => $type, 'entity_id' => (string) $ref['id']];
        };
        $facts = $facts->filter(static function ($fact) use ($referenceForFact, $policy, $actor, $context): bool {
            $ref = $referenceForFact($fact);
            return $ref !== null && $policy->canReadReference($actor, $context->organizationId, $ref);
        })->values();
        foreach ($facts as $fact) {
            $refs[] = $referenceForFact($fact);
        }
        $categories = $this->ruleEngine->categories($facts);
        $groups = $this->ruleEngine->groups($facts);
        $nextActions = $this->ruleEngine->nextActions($facts);
        $ruleRecommendations = $this->ruleEngine->recommendations($facts);
        $synthesis = $this->aiSynthesizer->synthesize(
            $facts,
            $ruleRecommendations,
            $context->useAi,
            $categories,
            $nextActions,
            $context,
        );
        $status = $this->ruleEngine->status($facts);

        $report = ProjectPulseReport::create([
            'organization_id' => $context->organizationId,
            'project_id' => $context->projectId,
            'scope_type' => $context->projectId ? 'project' : 'organization',
            'report_date' => $context->date->toDateString(),
            'period_preset' => $context->period,
            'period_from' => $context->from,
            'period_to' => $context->to,
            'status' => $status,
            'ai_status' => $synthesis['ai_mode']['status'],
            'ai_provider' => $synthesis['ai_mode']['provider'],
            'summary' => $synthesis['summary'],
            'metrics' => $categories,
            'urgent_actions' => $nextActions,
            'risk_groups' => $groups,
            'finance' => $this->factCollector->finance($context),
            'activity' => $this->ruleEngine->activity($facts),
            'recommendations' => $synthesis['recommendations'],
            'source_refs' => $refs,
            'required_domains' => $domains,
            'raw_facts' => $facts->map->toArray()->values()->all(),
            'created_by_user_id' => $context->userId,
            'generated_at' => now(),
        ]);

        return $this->formatter->format($report);
    }

    public function list(int $organizationId, array $filters, ?User $actor = null): LengthAwarePaginator
    {
        $actor = $this->actor($organizationId, $actor?->id);
        $paginator = app(AssistantDataAccessPolicy::class)->entityQuery($actor, $organizationId, 'project_pulse_report')
            ->forOrganization($organizationId)
            ->with('project')
            ->when(isset($filters['project_id']), fn ($query) => $query->where('project_id', (int) $filters['project_id']))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['ai_status']), fn ($query) => $query->where('ai_status', $filters['ai_status']))
            ->when(isset($filters['period']), fn ($query) => $query->where('period_preset', $filters['period']))
            ->when(isset($filters['date']), fn ($query) => $query->whereDate('report_date', $filters['date']))
            ->when(isset($filters['category']), function ($query) use ($filters): void {
                $category = (string) $filters['category'];

                $query->where(function ($query) use ($category): void {
                    $query
                        ->whereJsonContains('metrics', [['key' => $category]])
                        ->orWhereJsonContains('raw_facts', [['category' => $category]]);
                });
            })
            ->latest()
            ->paginate((int) ($filters['per_page'] ?? 15));

        $paginator->setCollection($paginator->getCollection()->map(
            fn (ProjectPulseReport $report) => $this->formatter->listItem($report)
        ));

        return $paginator;
    }

    public function get(int $organizationId, ProjectPulseReport $report, ?User $actor = null): array
    {
        $scopedReport = $this->findForOrganization($organizationId, $report, $actor);

        return $this->formatter->format($scopedReport);
    }

    public function delete(int $organizationId, ProjectPulseReport $report, ?User $actor = null): void
    {
        $scopedReport = $this->findForOrganization($organizationId, $report, $actor);

        $scopedReport->delete();
    }

    private function findForOrganization(int $organizationId, ProjectPulseReport $report, ?User $actor): ProjectPulseReport
    {
        $actor = $this->actor($organizationId, $actor?->id);
        $scopedReport = app(AssistantDataAccessPolicy::class)->entityQuery($actor, $organizationId, 'project_pulse_report')
            ->forOrganization($organizationId)
            ->whereKey($report->getKey())
            ->first();

        if (!$scopedReport) {
            throw (new ModelNotFoundException())->setModel(ProjectPulseReport::class, [$report->getKey()]);
        }

        return $scopedReport;
    }
    private function actor(int $organizationId, ?int $userId, ?int $projectId = null): User
    {
        $actor = $userId === null ? null : User::find($userId);
        $policy = app(AssistantDataAccessPolicy::class);
        if ($actor === null) { throw new AccessDeniedHttpException(); }

        return $policy->withCurrentChecks($actor, $organizationId, function () use ($actor, $policy, $organizationId, $projectId): User {
            if (! $policy->canReadDomain($actor, $organizationId, 'reports') || ! $policy->canReadDomain($actor, $organizationId, 'finance')
                || ($projectId !== null && ! $policy->canReadEntity($actor, $organizationId, 'project', $projectId))) {
                throw new AccessDeniedHttpException();
            }

            return $actor;
        }, fresh: true);
    }

}
