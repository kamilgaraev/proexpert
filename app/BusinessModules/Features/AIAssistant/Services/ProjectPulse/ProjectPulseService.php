<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\ProjectPulse;

use App\BusinessModules\Features\AIAssistant\DTOs\ProjectPulse\ProjectPulseContext;
use App\BusinessModules\Features\AIAssistant\Models\ProjectPulseReport;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
        $existing = ProjectPulseReport::query()
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
        $facts = $this->factCollector->collect($context);
        $ruleRecommendations = $this->ruleEngine->recommendations($facts);
        $synthesis = $this->aiSynthesizer->synthesize($facts, $ruleRecommendations, $context->useAi);
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
            'metrics' => $this->factCollector->metrics($context, $facts),
            'urgent_actions' => $this->ruleEngine->urgentActions($facts),
            'risk_groups' => $this->ruleEngine->riskGroups($facts),
            'finance' => $this->factCollector->finance($context),
            'activity' => $this->ruleEngine->activity($facts),
            'recommendations' => $synthesis['recommendations'],
            'raw_facts' => $facts->map->toArray()->values()->all(),
            'created_by_user_id' => $context->userId,
            'generated_at' => now(),
        ]);

        return $this->formatter->format($report);
    }

    public function list(int $organizationId, array $filters): LengthAwarePaginator
    {
        $paginator = ProjectPulseReport::query()
            ->forOrganization($organizationId)
            ->with('project')
            ->when(isset($filters['project_id']), fn ($query) => $query->where('project_id', (int) $filters['project_id']))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->latest()
            ->paginate((int) ($filters['per_page'] ?? 15));

        $paginator->setCollection($paginator->getCollection()->map(
            fn (ProjectPulseReport $report) => $this->formatter->listItem($report)
        ));

        return $paginator;
    }

    public function get(int $organizationId, ProjectPulseReport $report): array
    {
        if ($report->organization_id !== $organizationId) {
            throw (new ModelNotFoundException())->setModel(ProjectPulseReport::class, [$report->id]);
        }

        return $this->formatter->format($report);
    }

    public function delete(int $organizationId, ProjectPulseReport $report): void
    {
        if ($report->organization_id !== $organizationId) {
            throw (new ModelNotFoundException())->setModel(ProjectPulseReport::class, [$report->id]);
        }

        $report->delete();
    }
}
