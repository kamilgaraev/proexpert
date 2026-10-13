<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Operational;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeImpact;
use App\BusinessModules\Features\ChangeManagement\Services\ChangeManagementService;
use App\Models\User;

final readonly class AssistantProjectChangeReader extends AssistantProjectOperationalReader
{
    public const TYPE = 'live_project_change_readiness_projection';

    public function __construct(AssistantDataAccessPolicy $access, private ChangeManagementService $changes)
    {
        parent::__construct($access);
    }

    public static function matches(string $query): bool
    {
        return preg_match('/вопрос[а-яё]*\s+проектировщик|rfi|изменен[а-яё]*.*(?:согласован|решени|влияни|срок|задерж)|согласован[а-яё]*.*изменен/iu', $query) === 1
            && preg_match('/создай|измени|удали|согласуй|отправь|оплат|оплач|плат[её]ж|сч[её]т|бюджет|потрач|выручк|затрат|при[её]мк|журнал|охран[а-яё]*\s+труд|происшеств|табел|комплект|техник|критическ[а-яё]*\s+путь/iu', $query) !== 1;
    }

    protected function domain(): string { return 'change_management'; }

    protected function collect(User $actor, int $organizationId, int $projectId): array
    {
        $rfis = $this->changes->rfiQuery($organizationId, ['project_id' => $projectId], false, (int) $actor->id);
        $rfiCounts = self::statusCounts($rfis);
        $unanswered = (clone $rfis)->whereIn('status', ['sent', 'overdue', 'clarification_requested']);
        $rfiExamples = (clone $unanswered)->orderByRaw('response_due_date IS NULL')->orderBy('response_due_date')->orderBy('id')->limit(3)
            ->select(['id', 'rfi_number', 'subject', 'status', 'response_due_date', 'organization_id', 'recipient_organization_id', 'created_by_user_id'])->get()
            ->map(static fn ($row): array => $row->getAttributes())->all();
        $query = $this->projectQuery($actor, $organizationId, $projectId, 'change_request');
        $changeData = ['available' => false];
        if ($query !== null) {
            $counts = self::statusCounts($query);
            $pending = (clone $query)->whereIn('status', ['submitted', 'impact_assessment', 'internal_review', 'customer_review']);
            $examples = (clone $pending)->orderBy('id')->limit(20)->select(['id', 'change_number', 'title', 'status', 'created_by_user_id', 'reporting_currency'])->get()
                ->map(static fn ($row): array => $row->getAttributes())->all();
            $impactQuery = ChangeImpact::query()->where('organization_id', $organizationId)->whereIn('change_request_id', (clone $pending)->select('change_management_change_requests.id'));
            $columns = ['change_request_id', 'schedule_delta_days', 'requires_contract_change', 'requires_estimate_revision', 'requires_procurement_update', 'requires_customer_approval'];
            if ($this->access->canCurrentPermission($actor, $organizationId, 'finance.view')) { $columns[] = 'cost_delta'; }
            $impacts = (clone $impactQuery)->whereIn('change_request_id', array_column($examples, 'id'))->select($columns)->get()
                ->mapWithKeys(static fn ($row): array => [(int) $row->change_request_id => $row->getAttributes()])->all();
            $changeData = ['available' => true, 'status_counts' => $counts, 'pending' => $pending->count(),
                'positive_schedule_impacts' => (clone $impactQuery)->where('schedule_delta_days', '>', 0)->count(), 'examples' => $examples, 'impacts' => $impacts];
        }
        return ['rfi_counts' => $rfiCounts, 'unanswered' => $unanswered->count(), 'overdue_rfis' => (clone $unanswered)->whereDate('response_due_date', '<', today()->toDateString())->count(),
            'rfi_examples' => $rfiExamples, 'changes' => $changeData];
    }

    protected function format(array $data): string
    {
        $lines = [trans_message('ai_assistant_operational.change_scope', ['project' => self::text($data['project']['name']), 'date' => $data['as_of_date']]),
            trans_message('ai_assistant_operational.rfi_counts', ['unanswered' => $data['unanswered'], 'overdue' => $data['overdue_rfis'],
                'draft' => $data['rfi_counts']['draft'] ?? 0, 'answered' => $data['rfi_counts']['answered'] ?? 0])];
        foreach ($data['rfi_examples'] as $row) {
            $lines[] = '- #'.$row['id'].' '.self::text($row['rfi_number']).': '.self::text($row['subject']).'; '
                .trans_message('ai_assistant_operational.rfi_date', ['date' => $row['response_due_date'] === null ? trans_message('ai_assistant_operational.not_recorded') : substr((string) $row['response_due_date'], 0, 10)]);
        }
        if ($data['changes']['available']) {
            $lines[] = trans_message('ai_assistant_operational.change_counts', ['pending' => $data['changes']['pending'], 'schedule' => $data['changes']['positive_schedule_impacts']]);
            foreach ($data['changes']['examples'] as $row) {
                $lines[] = '- #'.$row['id'].' '.self::text($row['change_number']).': '.self::text($row['title']).'; '.trans_message('ai_assistant_operational.change_status_'.$row['status']);
                $impact = $data['changes']['impacts'][$row['id']] ?? null;
                if ($impact === null) { $lines[] = trans_message('ai_assistant_operational.impact_unknown'); }
                else {
                    $lines[] = trans_message('ai_assistant_operational.impact_days', ['days' => $impact['schedule_delta_days']]);
                    if (array_key_exists('cost_delta', $impact)) {
                        $lines[] = trans_message('ai_assistant_operational.impact_cost', ['cost' => $impact['cost_delta'], 'currency' => $row['reporting_currency'] ?? trans_message('ai_assistant_operational.not_recorded')]);
                    }
                }
                $lines[] = trans_message('ai_assistant_operational.change_step_'.$row['status']);
            }
        } else { $lines[] = trans_message('ai_assistant_operational.change_unavailable'); }
        $lines[] = trans_message('ai_assistant_operational.change_priority');
        $lines[] = trans_message('ai_assistant_operational.change_basis');
        $lines[] = trans_message('ai_assistant_operational.examples_basis');
        return implode("\n", $lines);
    }
}
