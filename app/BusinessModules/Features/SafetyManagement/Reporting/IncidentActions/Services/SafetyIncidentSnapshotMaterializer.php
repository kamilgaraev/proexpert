<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Services;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\DTO\SafetyTransitionFact;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetyExposureDay;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetyIncidentPolicyVersion;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetyIncidentRow;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetyIncidentSnapshot;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetySite;
use App\BusinessModules\Features\SafetyManagement\Reporting\IncidentActions\Models\SafetyTransitionEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class SafetyIncidentSnapshotMaterializer
{
    public function __construct(private SafetyIncidentFormula $formula) {}

    public function materialize(ReportExecutionContext $context, ReportQuery $query): SafetyIncidentSnapshot
    {
        $organizationId = $context->scope->organizationId;
        $asOf = CarbonImmutable::instance($query->asOf);
        $periodFrom = $this->periodFrom($query, $asOf);
        $policy = $this->policy($organizationId, $context->scope->projectIds, $asOf);
        $events = SafetyTransitionEvent::query()
            ->where('organization_id', $organizationId)
            ->where('occurred_at', '<=', $asOf)
            ->when($context->scope->projectIds !== [], static fn ($query) => $query->whereIn('project_id', $context->scope->projectIds))
            ->orderBy('subject_type')
            ->orderBy('subject_id')
            ->orderBy('event_version')
            ->get();
        $exposure = SafetyExposureDay::query()
            ->where('organization_id', $organizationId)
            ->whereBetween('exposure_date', [$periodFrom->toDateString(), $asOf->toDateString()])
            ->when($context->scope->projectIds !== [], static fn ($query) => $query->whereIn('project_id', $context->scope->projectIds))
            ->orderBy('safety_site_id')
            ->orderBy('exposure_date')
            ->get();
        $sourceHash = hash('sha256', CanonicalJson::encode([
            'events' => $events->pluck('event_hash')->all(),
            'exposure' => $exposure->pluck('source_hash')->all(),
            'period_from' => $periodFrom->toAtomString(),
            'policy_hash' => (string) $policy->source_hash,
            'query_hash' => $query->queryHash->value,
        ]));
        $scopeHash = hash('sha256', CanonicalJson::encode($context->scope->canonicalIdentity()));
        $existing = SafetyIncidentSnapshot::query()
            ->where('organization_id', $organizationId)
            ->where('scope_hash', $scopeHash)
            ->where('as_of', $asOf)
            ->where('formula_version', $query->definition->formulaVersion)
            ->where('source_hash', $sourceHash)
            ->first();
        if ($existing instanceof SafetyIncidentSnapshot) {
            return $existing;
        }

        return DB::transaction(function () use (
            $context,
            $query,
            $organizationId,
            $asOf,
            $periodFrom,
            $policy,
            $events,
            $exposure,
            $sourceHash,
            $scopeHash,
        ): SafetyIncidentSnapshot {
            $analysis = $this->analyze($events, $periodFrom, $asOf, $policy);
            $coverage = $this->exposureCoverage($organizationId, $context->scope->projectIds, $periodFrom, $asOf, $exposure);
            $qualifying = count(array_filter(
                $analysis['rows'],
                static fn (array $row): bool => $row['subject_type'] === 'incident'
                    && $row['created_flag']
                    && in_array($row['category'], $policy->qualifying_incident_types ?? [], true),
            ));
            $frequency = $coverage['complete']
                ? $this->formula->frequency($qualifying, $coverage['hours'], (int) $policy->frequency_multiplier)
                : null;
            $generatedAt = CarbonImmutable::now();
            $unknowns = $analysis['unknowns'] + ($coverage['complete'] ? 0 : 1);
            $snapshot = SafetyIncidentSnapshot::query()->create([
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
                'incident_count' => $analysis['incident_count'],
                'violation_count' => $analysis['violation_count'],
                'action_due_count' => $analysis['action_due_count'],
                'action_overdue_count' => $analysis['action_overdue_count'],
                'action_closed_on_time_count' => $analysis['action_closed_on_time_count'],
                'exposure_hours' => $coverage['complete'] ? $coverage['hours'] : null,
                'exposure_complete' => $coverage['complete'],
                'incident_frequency' => $frequency,
                'eligible_count' => count($analysis['rows']),
                'projected_count' => count($analysis['rows']),
                'gap_count' => $analysis['gaps'],
                'unknown_count' => $unknowns,
                'generated_at' => $generatedAt,
                'stale_at' => $generatedAt->addDay(),
            ]);
            foreach ($analysis['rows'] as $row) {
                SafetyIncidentRow::query()->create([
                    'organization_id' => $organizationId,
                    'snapshot_id' => $snapshot->id,
                ] + $row);
            }

            return $snapshot;
        });
    }

    private function policy(int $organizationId, array $projectIds, CarbonImmutable $asOf): SafetyIncidentPolicyVersion
    {
        $projectId = count($projectIds) === 1 ? $projectIds[0] : null;
        $policy = SafetyIncidentPolicyVersion::query()
            ->where('organization_id', $organizationId)
            ->whereDate('effective_from', '<=', $asOf->toDateString())
            ->where(static function ($query) use ($asOf): void {
                $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $asOf->toDateString());
            })
            ->where(static function ($query) use ($projectId): void {
                $query->whereNull('project_id');
                if ($projectId !== null) {
                    $query->orWhere('project_id', $projectId);
                }
            })
            ->orderByRaw('project_id IS NULL')
            ->orderByDesc('effective_from')
            ->first();
        if (! $policy instanceof SafetyIncidentPolicyVersion) {
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
        SafetyIncidentPolicyVersion $policy,
    ): array {
        $rows = [];
        $lastVersion = [];
        $lastStatus = [];
        $firstAt = [];
        $state = [];
        $gaps = 0;
        $unknowns = 0;
        $incidentSubjects = [];
        $violationSubjects = [];
        $actionDue = [];
        $actionOverdue = [];
        $actionClosedOnTime = [];

        foreach ($events as $event) {
            if (! $event instanceof SafetyTransitionEvent) {
                continue;
            }
            $key = $event->subject_type.':'.$event->subject_id;
            $occurredAt = CarbonImmutable::instance($event->occurred_at);
            $firstAt[$key] ??= $occurredAt;
            $expectedVersion = ($lastVersion[$key] ?? 0) + 1;
            if ($event->event_version !== $expectedVersion
                || ($event->event_version > 1 && ($event->from_status ?? null) !== ($lastStatus[$key] ?? null))) {
                $gaps++;
            }
            $lastVersion[$key] = (int) $event->event_version;
            $lastStatus[$key] = (string) $event->to_status;

            $fact = new SafetyTransitionFact(
                subjectType: (string) $event->subject_type,
                subjectId: (int) $event->subject_id,
                fromStatus: $event->from_status,
                toStatus: (string) $event->to_status,
                occurredAt: $occurredAt->toDateTimeImmutable(),
                dueAt: $event->due_date?->toDateTimeImmutable(),
                resolvedAt: in_array($event->to_status, ['resolved', 'verified'], true) ? $occurredAt->toDateTimeImmutable() : null,
                verifiedAt: $event->to_status === 'verified' ? $occurredAt->toDateTimeImmutable() : null,
                evidenceId: $event->evidence_id,
            );
            $closureVerified = $this->formula->actionClosure($fact, $policy);
            $terminal = $event->subject_type === 'corrective_action'
                ? $closureVerified
                : in_array($event->to_status, $policy->terminal_statuses ?? [], true);
            $reopened = in_array($event->from_status, $policy->terminal_statuses ?? [], true) && ! $terminal;
            $state[$key] = ! $terminal;
            if ($event->safety_site_id === null) {
                $unknowns++;
            }
            if ($event->subject_type === 'corrective_action' && in_array($event->to_status, ['resolved', 'verified'], true) && ! $closureVerified) {
                $unknowns++;
            }
            if ($occurredAt < $periodFrom) {
                continue;
            }

            $created = $event->from_status === null;
            if ($created && $event->subject_type === 'incident') {
                $incidentSubjects[$event->subject_id] = true;
            }
            if ($created && $event->subject_type === 'violation') {
                $violationSubjects[$event->subject_id] = true;
            }
            if ($event->subject_type === 'corrective_action' && $event->due_date !== null && $event->due_date <= $asOf) {
                $actionDue[$event->subject_id] = true;
                if (! $terminal && $event->due_date < $asOf->startOfDay()) {
                    $actionOverdue[$event->subject_id] = true;
                }
                if ($closureVerified && $occurredAt->startOfDay() <= $event->due_date) {
                    $actionClosedOnTime[$event->subject_id] = true;
                }
            }

            $rows[] = [
                'project_id' => (int) $event->project_id,
                'safety_site_id' => $event->safety_site_id === null ? null : (int) $event->safety_site_id,
                'subject_type' => (string) $event->subject_type,
                'subject_id' => (int) $event->subject_id,
                'event_version' => (int) $event->event_version,
                'row_key' => sprintf('%s:%d:event:%d', $event->subject_type, $event->subject_id, $event->event_version),
                'event_date' => $occurredAt->toDateString(),
                'category' => $event->category,
                'severity' => (string) $event->severity,
                'status' => (string) $event->to_status,
                'owner_user_id' => $event->owner_user_id === null ? null : (int) $event->owner_user_id,
                'due_date' => $event->due_date?->toDateString(),
                'opening_flag' => false,
                'created_flag' => $created,
                'reopened_flag' => $reopened,
                'closed_flag' => $terminal,
                'closing_flag' => false,
                'closure_verified' => $closureVerified,
                'closure_days' => $terminal ? $firstAt[$key]->diffInDays($occurredAt) : null,
                'evidence_type' => $event->evidence_type,
                'evidence_id' => $event->evidence_id,
            ];
        }

        $lastRow = [];
        foreach ($rows as $index => $row) {
            $lastRow[$row['subject_type'].':'.$row['subject_id']] = $index;
        }
        foreach ($lastRow as $key => $index) {
            $rows[$index]['closing_flag'] = $state[$key] ?? false;
        }

        return [
            'rows' => $rows,
            'gaps' => $gaps,
            'unknowns' => $unknowns,
            'incident_count' => count($incidentSubjects),
            'violation_count' => count($violationSubjects),
            'action_due_count' => count($actionDue),
            'action_overdue_count' => count($actionOverdue),
            'action_closed_on_time_count' => count($actionClosedOnTime),
        ];
    }

    private function exposureCoverage(
        int $organizationId,
        array $projectIds,
        CarbonImmutable $periodFrom,
        CarbonImmutable $asOf,
        Collection $exposure,
    ): array {
        $siteCount = SafetySite::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->when($projectIds !== [], static fn ($query) => $query->whereIn('project_id', $projectIds))
            ->count();
        $expected = $siteCount * ($periodFrom->diffInDays($asOf->startOfDay()) + 1);
        $complete = $expected > 0 && $exposure->count() === $expected && $exposure->every('complete');
        $scaledHours = 0;
        foreach ($exposure as $day) {
            $value = (string) $day->exposure_hours;
            [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
            $scaledHours += ((int) $whole * 10_000) + (int) str_pad($fraction, 4, '0');
        }

        return [
            'complete' => $complete,
            'hours' => sprintf('%d.%04d', intdiv($scaledHours, 10_000), $scaledHours % 10_000),
        ];
    }
}
