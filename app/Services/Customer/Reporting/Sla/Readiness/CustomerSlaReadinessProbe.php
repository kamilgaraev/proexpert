<?php

declare(strict_types=1);

namespace App\Services\Customer\Reporting\Sla\Readiness;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceReadinessProbe;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceReadiness;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSourceReadinessStatus;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class CustomerSlaReadinessProbe implements ReportSourceReadinessProbe
{
    public function supports(ReportDefinition $definition): bool
    {
        return $definition->code === 'customer_sla'
            && $definition->formulaVersion === 'customer-sla.v1'
            && $definition->sourceSchemaVersion === 'customer-sla.v1';
    }

    public function reportCodes(): array
    {
        return ['customer_sla'];
    }

    public function inspect(
        ReportExecutionContext $context,
        ReportQuery $query,
    ): ReportSourceReadiness {
        [$periodFrom, $periodTo, $workflowTypes] = $this->filters($query);
        $issues = ! in_array('issue', $workflowTypes, true) ? collect() : DB::table('customer_issues')
            ->where('organization_id', $context->scope->organizationId)
            ->where('created_at', '<=', $query->asOf)
            ->whereBetween('created_at', [$periodFrom, $periodTo])
            ->when(
                $query->scope->projectIds !== [],
                fn ($builder) => $builder->whereIn('project_id', $query->scope->projectIds),
            )
            ->get(['id', 'project_id', 'created_at', 'updated_at'])
            ->map(static fn (object $row): array => [
                'type' => 'issue',
                'id' => (int) $row->id,
                'project_id' => $row->project_id === null ? null : (int) $row->project_id,
                'created_at' => (string) $row->created_at,
                'updated_at' => (string) $row->updated_at,
            ]);
        $requests = ! in_array('request', $workflowTypes, true) ? collect() : DB::table('customer_requests')
            ->where('organization_id', $context->scope->organizationId)
            ->where('created_at', '<=', $query->asOf)
            ->whereBetween('created_at', [$periodFrom, $periodTo])
            ->when(
                $query->scope->projectIds !== [],
                fn ($builder) => $builder->whereIn('project_id', $query->scope->projectIds),
            )
            ->get(['id', 'project_id', 'created_at', 'updated_at'])
            ->map(static fn (object $row): array => [
                'type' => 'request',
                'id' => (int) $row->id,
                'project_id' => $row->project_id === null ? null : (int) $row->project_id,
                'created_at' => (string) $row->created_at,
                'updated_at' => (string) $row->updated_at,
            ]);
        $workflows = $issues->concat($requests)->values();
        $eligible = $workflows->count();
        $events = DB::table('customer_workflow_events')
            ->where('organization_id', $context->scope->organizationId)
            ->where('occurred_at', '<=', $query->asOf)
            ->when(
                $query->scope->projectIds !== [],
                fn ($builder) => $builder->whereIn('project_id', $query->scope->projectIds),
            )
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
        $unknownActorWorkflows = $events
            ->where('actor_side', 'unknown')
            ->map(static fn (object $event): string => $event->workflow_type.':'.$event->workflow_id)
            ->flip();
        $openedByWorkflow = $events
            ->where('event_type', 'opened')
            ->groupBy(static fn (object $event): string => $event->workflow_type.':'.$event->workflow_id);
        $policies = DB::table('customer_sla_policy_versions')
            ->where('organization_id', $context->scope->organizationId)
            ->where('effective_from', '<=', $query->asOf)
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get();
        $projected = 0;
        $unknown = 0;
        $selectedPolicyIds = [];
        foreach ($workflows as $workflow) {
            $openingEvents = $openedByWorkflow->get($workflow['type'].':'.$workflow['id'], collect());
            if ($openingEvents->isEmpty()) {
                continue;
            }
            if ($openingEvents->count() !== 1) {
                $unknown++;

                continue;
            }
            $opened = $openingEvents->first();
            if (
                ! is_object($opened)
                || $opened->actor_side !== 'customer'
                || $opened->customer_organization_id === null
                || $unknownActorWorkflows->has($workflow['type'].':'.$workflow['id'])
            ) {
                $unknown++;

                continue;
            }
            $policy = $this->selectPolicy($policies, $opened);
            if ($policy === null) {
                continue;
            }
            $selectedPolicyIds[] = (int) $policy->id;
            $projected++;
        }
        $gap = max(0, $eligible - $projected - $unknown);
        $eventHashes = $events->pluck('evidence_hash')->all();
        $ready = $gap === 0 && $unknown === 0;
        $output = [
            'event_hashes' => $eventHashes,
            'policy_ids' => array_values(array_unique($selectedPolicyIds)),
        ];

        return new ReportSourceReadiness(
            $ready ? ReportSourceReadinessStatus::READY : ReportSourceReadinessStatus::PARTIAL,
            $eligible,
            $projected,
            $gap,
            $unknown,
            'customer_event_'.(string) ($events->max('id') ?? 0),
            hash('sha256', CanonicalJson::encode($workflows->all())),
            hash('sha256', CanonicalJson::encode($output)),
            $ready ? CarbonImmutable::now('UTC') : null,
        );
    }

    private function filters(ReportQuery $query): array
    {
        $from = $query->filters->values['period_from'] ?? null;
        $to = $query->filters->values['period_to'] ?? null;
        $types = $query->filters->values['workflow_types'] ?? ['issue', 'request'];
        if (! is_string($from)
            || ! is_string($to)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $from) !== 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $to) !== 1
            || ! is_array($types)
            || ! array_is_list($types)
            || $types === []
            || array_filter($types, static fn (mixed $type): bool => ! is_string($type)
                || ! in_array($type, ['issue', 'request'], true)) !== []) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_VALUE_NOT_FOUND);
        }
        $periodFrom = CarbonImmutable::createFromFormat('!Y-m-d', $from, 'UTC');
        $periodTo = CarbonImmutable::createFromFormat('!Y-m-d', $to, 'UTC')?->endOfDay();
        if (! $periodFrom instanceof CarbonImmutable
            || ! $periodTo instanceof CarbonImmutable
            || $periodFrom->greaterThan($periodTo)
            || $periodTo->toDateString() > CarbonImmutable::instance($query->asOf)->toDateString()) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_VALUE_NOT_FOUND);
        }

        return [$periodFrom, $periodTo, array_values(array_unique($types))];
    }

    private function selectPolicy(\Illuminate\Support\Collection $policies, object $event): ?object
    {
        $selected = null;
        $selectedKey = null;
        foreach ($policies as $policy) {
            if (
                ($policy->project_id !== null && (int) $policy->project_id !== (int) $event->project_id)
                || ($policy->customer_organization_id !== null
                    && (int) $policy->customer_organization_id !== (int) $event->customer_organization_id)
                || ($policy->workflow_type !== null && $policy->workflow_type !== $event->workflow_type)
                || ($policy->priority !== null && $policy->priority !== $event->priority)
                || CarbonImmutable::parse((string) $policy->effective_from)
                    > CarbonImmutable::parse((string) $event->occurred_at)
                || ($policy->effective_to !== null
                    && CarbonImmutable::parse((string) $policy->effective_to)
                        <= CarbonImmutable::parse((string) $event->occurred_at))
            ) {
                continue;
            }
            $specificity = ($policy->project_id !== null ? 1 : 0)
                + ($policy->customer_organization_id !== null ? 1 : 0)
                + ($policy->workflow_type !== null ? 1 : 0)
                + ($policy->priority !== null ? 1 : 0);
            $key = sprintf(
                '%d:%s:%020d',
                $specificity,
                CarbonImmutable::parse((string) $policy->effective_from)->format('U.u'),
                (int) $policy->id,
            );
            if ($selectedKey === null || strcmp($key, $selectedKey) > 0) {
                $selected = $policy;
                $selectedKey = $key;
            }
        }

        return $selected;
    }
}
