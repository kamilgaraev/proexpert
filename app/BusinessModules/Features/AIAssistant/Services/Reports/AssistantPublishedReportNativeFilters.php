<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceCloseIdentity;
use App\BusinessModules\Features\Budgeting\DTOs\PlanFactReportFilters;
use App\BusinessModules\Features\Budgeting\DTOs\ProjectMarginReportFilters;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectMarginCandidateContract;
use App\BusinessModules\Features\Budgeting\Services\BudgetingReportSourceCloseService;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementCycleSnapshotRequest;
use App\Enums\Schedule\TaskStatusEnum;
use DateTimeImmutable;
use DomainException;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class AssistantPublishedReportNativeFilters
{
    private const DATES = ['as_of', 'period_from', 'period_to', 'period_start', 'period_end', 'day_from', 'day_to', 'due_from', 'due_to', 'horizon_from', 'horizon_to', 'status_date'];

    private const STRING_LISTS = ['statuses', 'task_statuses', 'constraint_types', 'severities', 'source_types', 'workflow_types', 'issue_codes', 'employment_types', 'rate_types', 'currencies', 'scenarios', 'risk_levels', 'project_statuses', 'contract_statuses', 'work_type_categories', 'party_keys', 'directions', 'instruments', 'aging_buckets', 'initiator_types', 'reasons', 'group_by'];

    public function __construct(
        private BudgetingReportSourceCloseService $closes,
        private ProjectMarginCandidateContract $margin,
        private BudgetPlanFactCandidateContract $planFact,
    ) {}

    public function validate(ReportExecutionContext $context, ReportDefinition $definition, array $input, DateTimeImmutable $asOf): array
    {
        try {
            if ($definition->code === 'procurement_cycle') {
                return (new ProcurementCycleSnapshotRequest($context->scope, $input, $asOf, null))->filters;
            }
            foreach ($input as $key => $value) {
                if ($value === null) {
                    continue;
                }
                $type = $this->type($definition->code, $key);
                $valid = match ($type) {
                    'date' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1
                        && DateTimeImmutable::createFromFormat('!Y-m-d', $value)?->format('Y-m-d') === $value,
                    'month' => is_string($value) && preg_match('/^\d{4}-\d{2}$/D', $value) === 1
                        && DateTimeImmutable::createFromFormat('!Y-m', $value)?->format('Y-m') === $value,
                    'integer' => is_int($value) && $value > 0,
                    'boolean' => is_bool($value),
                    'uuid' => is_string($value) && Str::isUuid($value),
                    'ulid' => is_string($value) && Str::isUlid($value),
                    'reference' => (is_int($value) && $value > 0) || (is_string($value) && (Str::isUuid($value) || (ctype_digit($value) && (int) $value > 0))),
                    'ids' => $this->list($value, static fn ($item): bool => is_int($item) && $item > 0),
                    'strings' => $this->list($value, static fn ($item): bool => is_string($item) && trim($item) !== ''),
                    'string_or_list' => (is_string($value) && trim($value) !== '') || $this->list($value, static fn ($item): bool => is_string($item) && trim($item) !== ''),
                    'string' => is_string($value) && trim($value) !== '',
                    default => false,
                };
                if (! $valid) {
                    $this->invalid($key);
                }
            }
            foreach ($definition->filters as $filter) {
                if (($filter['required'] ?? false) && ($input[$filter['id']] ?? null) === []) {
                    $this->invalid($filter['id']);
                }
            }
            foreach ($this->enums($definition->code) as $key => $values) {
                if (isset($input[$key]) && array_diff(is_array($input[$key]) ? $input[$key] : [$input[$key]], $values) !== []) {
                    $this->invalid($key);
                }
            }
            foreach (['period_from' => 'period_to', 'period_start' => 'period_end', 'day_from' => 'day_to', 'due_from' => 'due_to', 'horizon_from' => 'horizon_to', 'month_from' => 'month_to'] as $from => $to) {
                if (isset($input[$from], $input[$to]) && $input[$from] > $input[$to]) {
                    $this->invalid($from);
                }
            }
            if (in_array($definition->code, ['project_labor_cost', 'attendance_execution', 'customer_sla'], true)) {
                $end = $input['period_to'] ?? $input['day_to'] ?? null;
                if ($end !== null && $end > $asOf->format('Y-m-d')) {
                    $this->invalid('period');
                }
            }
            if (in_array($definition->code, ['safety_incident_actions', 'quality_defect_flow'], true)
                && isset($input['period_from']) && $input['period_from'] > $asOf->format('Y-m-d')) {
                $this->invalid('period_from');
            }
            if ($definition->code === 'workforce_capacity' && $input['month_to'] > $asOf->format('Y-m')) {
                $this->invalid('month_to');
            }
            if (in_array($definition->code, ['baseline_schedule_variance', 'handover_readiness', 'contractor_scorecard'], true)
                && $input['as_of'] !== $asOf->format('Y-m-d')) {
                $this->invalid('as_of');
            }
            if ($definition->code === 'lookahead_readiness' && isset($input['horizon_days']) && $input['horizon_days'] > 366) {
                $this->invalid('horizon_days');
            }
            if (($definition->code === 'inventory_risk' && ($input['statuses'] ?? null) === [])
                || ($definition->code === 'customer_sla' && ($input['workflow_types'] ?? null) === [])) {
                $this->invalid('statuses');
            }
            if (isset($input['currencies']) && ! $this->list($input['currencies'], static fn ($item): bool => is_string($item) && preg_match('/^[A-Z]{3}$/D', $item) === 1)) {
                $this->invalid('currencies');
            }
            if (isset($input['currency']) && preg_match('/^[A-Z]{3}$/D', $input['currency']) !== 1) {
                $this->invalid('currency');
            }
            if (in_array($definition->code, ['project_margin', 'budget_plan_fact'], true)) {
                $this->assertClose($context, $definition->code, $input, $asOf);
            }
        } catch (InvalidArgumentException|DomainException) {
            $this->invalid('filters');
        }

        return $input;
    }

    public function describe(string $code, array $filters): array
    {
        $enums = $this->enums($code);

        return array_map(function (array $filter) use ($code, $enums): array {
            if (isset($filter['type'], $filter['operators']) || $code === 'accepted_production_progress') {
                return $filter;
            }
            $key = $filter['id'];
            $extra = ['input_type' => $this->type($code, $key)];
            if (isset($enums[$key])) {
                $extra['allowed_values'] = $enums[$key];
            }
            if (($code === 'inventory_risk' && $key === 'statuses') || ($code === 'customer_sla' && $key === 'workflow_types') || ($filter['required'] ?? false)) {
                $extra['empty_list_allowed'] = false;
            }

            return $filter + $extra;
        }, $filters);
    }

    private function type(string $code, string $key): string
    {
        if (in_array($key, self::DATES, true)) {
            return 'date';
        }
        if (in_array($key, ['month_from', 'month_to'], true)) {
            return 'month';
        }
        if (in_array($key, ['billable', 'critical', 'mandatory', 'blocked', 'verified'], true)) {
            return 'boolean';
        }
        if ($key === 'close_id') {
            return 'ulid';
        }
        if (in_array($code, ['project_margin', 'budget_plan_fact'], true) && in_array($key, ['responsibility_center_id', 'budget_article_id'], true)) {
            return 'reference';
        }
        if (str_ends_with($key, '_uuid')) {
            return 'uuid';
        }
        if ($key === 'wbs_ids') {
            return 'strings';
        }
        if (str_ends_with($key, '_ids')) {
            return 'ids';
        }
        if (str_ends_with($key, '_id') || $key === 'horizon_days') {
            return 'integer';
        }
        if (in_array($key, self::STRING_LISTS, true)) {
            return 'strings';
        }
        if (in_array($key, ['status', 'severity', 'subject_type'], true)) {
            return 'string_or_list';
        }

        return in_array($key, ['currency', 'cohort', 'cohort_basis', 'current_stage', 'outcome', 'priority', 'award_amount_min', 'award_amount_max'], true) ? 'string' : 'unsupported';
    }

    private function enums(string $code): array
    {
        return match ($code) {
            'inventory_risk' => ['statuses' => ['healthy', 'reorder', 'excess']],
            'project_labor_cost' => ['statuses' => ['approved']],
            'attendance_execution' => ['statuses' => ['covered', 'unexplained_absence']],
            'customer_sla' => ['workflow_types' => ['issue', 'request']],
            'baseline_schedule_variance' => ['statuses' => array_column(TaskStatusEnum::cases(), 'value')],
            'project_portfolio_health' => ['risk_levels' => ['low', 'medium', 'high', 'critical']],
            'management_pnl' => ['scenarios' => ['actual', 'forecast', 'plan'], 'currencies' => ['RUB', 'USD', 'EUR']],
            'procurement_cycle' => ['cohort_basis' => ['start', 'outcome']],
            'project_margin' => ['group_by' => ProjectMarginReportFilters::ALLOWED_GROUP_BY],
            'budget_plan_fact' => ['group_by' => PlanFactReportFilters::ALLOWED_GROUP_BY],
            'safety_incident_actions' => ['subject_type' => ['incident', 'violation', 'corrective_action'], 'severity' => ['minor', 'major', 'high', 'critical'],
                'status' => ['reported', 'triage', 'investigation', 'corrective_actions', 'open', 'resolved', 'verified', 'closed', 'cancelled']],
            'quality_defect_flow' => ['severity' => ['minor', 'major', 'critical'], 'status' => ['draft', 'open', 'assigned', 'in_progress', 'ready_for_review', 'resolved', 'rejected', 'cancelled']],
            default => [],
        };
    }

    private function assertClose(ReportExecutionContext $context, string $code, array $input, DateTimeImmutable $asOf): void
    {
        $identity = new BudgetingReportSourceCloseIdentity($context->scope->organizationId, $input['period_start'], $input['period_end'], $input['scenario_uuid'], $input['budget_version_uuid']);
        $close = $this->closes->validatedCloseForReporting($input['close_id'], $code, $identity, $asOf);
        unset($input['close_id']);
        ($code === 'project_margin' ? $this->margin : $this->planFact)->assertSnapshotRequest($context->scope, $input, $close);
    }

    private function list(mixed $value, callable $valid): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (! $valid($item)) {
                return false;
            }
        }

        return true;
    }

    private function invalid(string $field): never
    {
        throw ReportContractException::fromCode(ReportErrorCode::REPORT_REQUEST_INVALID, ['fields' => $field]);
    }
}
