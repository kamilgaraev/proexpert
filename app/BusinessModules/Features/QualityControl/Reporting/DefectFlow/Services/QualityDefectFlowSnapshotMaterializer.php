<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Services;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models\QualityDefectFlowPolicyVersion;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models\QualityDefectFlowRow;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models\QualityDefectFlowSnapshot;
use App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models\QualityDefectTransitionEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class QualityDefectFlowSnapshotMaterializer
{
    public function __construct(private QualityDefectFlowFormula $formula) {}

    public function materialize(ReportExecutionContext $context, ReportQuery $query): QualityDefectFlowSnapshot
    {
        $organizationId = $context->scope->organizationId;
        $asOf = CarbonImmutable::instance($query->asOf);
        $periodFrom = $this->periodFrom($query, $asOf);
        $policy = $this->policy($organizationId, $context->scope->projectIds, $asOf);
        $events = QualityDefectTransitionEvent::query()
            ->where('organization_id', $organizationId)
            ->where('occurred_at', '<=', $asOf)
            ->when(
                $context->scope->projectIds !== [],
                static fn ($builder) => $builder->whereIn('project_id', $context->scope->projectIds),
            )
            ->orderBy('quality_defect_id')
            ->orderBy('event_version')
            ->orderBy('id')
            ->get();
        $sourceHash = hash('sha256', CanonicalJson::encode([
            'events' => $events->pluck('event_hash')->all(),
            'period_from' => $periodFrom->toAtomString(),
            'policy_hash' => (string) $policy->source_hash,
            'query_hash' => $query->queryHash->value,
        ]));
        $scopeHash = hash('sha256', CanonicalJson::encode($context->scope->canonicalIdentity()));

        $existing = QualityDefectFlowSnapshot::query()
            ->where('organization_id', $organizationId)
            ->where('scope_hash', $scopeHash)
            ->where('as_of', $asOf)
            ->where('formula_version', $query->definition->formulaVersion)
            ->where('source_hash', $sourceHash)
            ->first();
        if ($existing instanceof QualityDefectFlowSnapshot) {
            return $existing;
        }

        return DB::transaction(function () use (
            $organizationId,
            $asOf,
            $periodFrom,
            $policy,
            $events,
            $sourceHash,
            $scopeHash,
            $query,
        ): QualityDefectFlowSnapshot {
            $analysis = $this->analyze($events, $periodFrom, $asOf, $policy);
            $generatedAt = CarbonImmutable::now();
            $snapshot = QualityDefectFlowSnapshot::query()->create([
                'id' => (string) Str::ulid(),
                'organization_id' => $organizationId,
                'project_id' => count($query->scope->projectIds) === 1 ? $query->scope->projectIds[0] : null,
                'policy_version_id' => $policy->id,
                'scope_hash' => $scopeHash,
                'definition_hash' => $query->definition->definitionHash->value,
                'formula_version' => $query->definition->formulaVersion,
                'source_hash' => $sourceHash,
                'as_of' => $asOf,
                'source_watermark' => $events->max('occurred_at') ?? $asOf,
                'row_count' => count($analysis['rows']),
                'opening_count' => $analysis['opening'],
                'created_count' => $analysis['created'],
                'reopened_count' => $analysis['reopened'],
                'closed_count' => $analysis['closed'],
                'closing_count' => $analysis['closing'],
                'eligible_count' => count($analysis['rows']),
                'projected_count' => count($analysis['rows']),
                'gap_count' => $analysis['gaps'],
                'unknown_count' => $analysis['unknowns'],
                'generated_at' => $generatedAt,
                'stale_at' => $generatedAt->addDay(),
            ]);

            foreach ($analysis['rows'] as $row) {
                QualityDefectFlowRow::query()->create([
                    'organization_id' => $organizationId,
                    'snapshot_id' => $snapshot->id,
                ] + $row);
            }

            return $snapshot;
        });
    }

    private function policy(int $organizationId, array $projectIds, CarbonImmutable $asOf): QualityDefectFlowPolicyVersion
    {
        $projectId = count($projectIds) === 1 ? $projectIds[0] : null;
        $policy = QualityDefectFlowPolicyVersion::query()
            ->where('organization_id', $organizationId)
            ->whereDate('effective_from', '<=', $asOf->toDateString())
            ->where(static function ($builder) use ($asOf): void {
                $builder->whereNull('effective_until')->orWhereDate('effective_until', '>=', $asOf->toDateString());
            })
            ->where(static function ($builder) use ($projectId): void {
                $builder->whereNull('project_id');
                if ($projectId !== null) {
                    $builder->orWhere('project_id', $projectId);
                }
            })
            ->orderByRaw('project_id IS NULL')
            ->orderByDesc('effective_from')
            ->first();

        if (! $policy instanceof QualityDefectFlowPolicyVersion) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
        }

        return $policy;
    }

    private function periodFrom(ReportQuery $query, CarbonImmutable $asOf): CarbonImmutable
    {
        $value = $query->filters->values['period_from'] ?? null;
        if (! is_string($value)) {
            return $asOf->startOfMonth();
        }

        try {
            $date = CarbonImmutable::parse($value, $query->scope->timezone);
        } catch (\Throwable $exception) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_RANGE_INVALID, previous: $exception);
        }
        if ($date > $asOf) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_RANGE_INVALID);
        }

        return $date;
    }

    private function analyze(
        Collection $events,
        CarbonImmutable $periodFrom,
        CarbonImmutable $asOf,
        QualityDefectFlowPolicyVersion $policy,
    ): array {
        $openingState = [];
        $currentState = [];
        $firstAt = [];
        $lastVersion = [];
        $lastStatus = [];
        $rows = [];
        $opening = 0;
        $created = 0;
        $reopened = 0;
        $closed = 0;
        $gaps = 0;
        $unknowns = 0;

        foreach ($events as $event) {
            if (! $event instanceof QualityDefectTransitionEvent) {
                continue;
            }

            $defectId = (int) $event->quality_defect_id;
            $occurredAt = CarbonImmutable::instance($event->occurred_at);
            $firstAt[$defectId] ??= $occurredAt;
            $expectedVersion = ($lastVersion[$defectId] ?? 0) + 1;
            if ($event->event_version !== $expectedVersion
                || ($event->event_version > 1 && ($event->from_status ?? null) !== ($lastStatus[$defectId] ?? null))) {
                $gaps++;
            }

            $lastVersion[$defectId] = (int) $event->event_version;
            $lastStatus[$defectId] = (string) $event->to_status;
            $isClosed = $this->formula->isClosure($event, $policy);
            $isReopen = $this->formula->isReopen($event, $policy);
            $currentState[$defectId] = ! $isClosed;

            if ($occurredAt < $periodFrom) {
                $openingState[$defectId] = ! $isClosed;

                continue;
            }

            $createdFlag = $event->from_status === null;
            $reopenedFlag = $isReopen;
            $closedFlag = $isClosed;
            $created += $createdFlag ? 1 : 0;
            $reopened += $reopenedFlag ? 1 : 0;
            $closed += $closedFlag ? 1 : 0;
            if (in_array($event->to_status, $policy->terminal_statuses ?? [], true) && ! $closedFlag) {
                $unknowns++;
            }

            $rows[] = [
                'project_id' => (int) $event->project_id,
                'contractor_id' => $event->contractor_id === null ? null : (int) $event->contractor_id,
                'schedule_task_id' => $event->schedule_task_id === null ? null : (int) $event->schedule_task_id,
                'quality_defect_id' => $defectId,
                'event_version' => (int) $event->event_version,
                'row_key' => sprintf('defect:%d:event:%d', $defectId, $event->event_version),
                'cohort_date' => $firstAt[$defectId]->toDateString(),
                'severity' => (string) $event->severity,
                'status' => (string) $event->to_status,
                'opening_flag' => false,
                'created_flag' => $createdFlag,
                'reopened_flag' => $reopenedFlag,
                'closed_flag' => $closedFlag,
                'closing_flag' => false,
                'cohort_eligible' => $firstAt[$defectId]->addDays((int) $policy->maturity_days) <= $asOf,
                'cycle_days' => $closedFlag ? $firstAt[$defectId]->diffInDays($occurredAt) : null,
                'due_date' => null,
                'evidence_refs' => $event->evidence_refs ?? [],
            ];
        }

        foreach ($openingState as $isOpen) {
            $opening += $isOpen ? 1 : 0;
        }
        $closing = $this->formula->rollForward($opening, $created, $reopened, $closed);
        $actualClosing = count(array_filter($currentState));
        if ($closing !== $actualClosing) {
            $gaps += abs($closing - $actualClosing);
        }

        $lastRowByDefect = [];
        foreach ($rows as $index => $row) {
            $lastRowByDefect[$row['quality_defect_id']] = $index;
        }
        foreach ($lastRowByDefect as $defectId => $index) {
            $rows[$index]['closing_flag'] = $currentState[$defectId] ?? false;
            $rows[$index]['opening_flag'] = $openingState[$defectId] ?? false;
        }

        return compact('rows', 'opening', 'created', 'reopened', 'closed', 'closing', 'gaps', 'unknowns');
    }
}
