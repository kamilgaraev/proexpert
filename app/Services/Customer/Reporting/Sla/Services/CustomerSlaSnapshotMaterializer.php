<?php

declare(strict_types=1);

namespace App\Services\Customer\Reporting\Sla\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\Services\Customer\Reporting\Sla\DTO\CustomerSlaPauseWindow;
use App\Services\Customer\Reporting\Sla\DTO\CustomerSlaPolicy;
use App\Services\Customer\Reporting\Sla\DTO\CustomerWorkflowFact;
use App\Services\Customer\Reporting\Sla\Enums\CustomerActorSide;
use App\Services\Customer\Reporting\Sla\Enums\CustomerWorkflowEventType;
use App\Services\Customer\Reporting\Sla\Models\CustomerSlaPolicyVersion;
use App\Services\Customer\Reporting\Sla\Models\CustomerSlaRow;
use App\Services\Customer\Reporting\Sla\Models\CustomerSlaSnapshot;
use App\Services\Customer\Reporting\Sla\Models\CustomerWorkflowEvent;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class CustomerSlaSnapshotMaterializer
{
    public const FORMULA_VERSION = 'customer-sla.v1';

    public function __construct(private CustomerSlaFormula $formula)
    {
    }

    public function materialize(ReportExecutionContext $context, ReportQuery $query): ReportSnapshotRef
    {
        if (
            $query->definition->code !== 'customer_sla'
            || $context->scope->canonicalIdentity() !== $query->scope->canonicalIdentity()
        ) {
            throw new InvalidArgumentException('customer_sla_context_invalid');
        }
        $events = CustomerWorkflowEvent::query()
            ->where('organization_id', $query->scope->organizationId)
            ->where('occurred_at', '<=', $query->asOf)
            ->when(
                $query->scope->projectIds !== [],
                fn ($builder) => $builder->whereIn('project_id', $query->scope->projectIds),
            )
            ->orderBy('workflow_type')
            ->orderBy('workflow_id')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
        if ($events->isEmpty()) {
            throw new InvalidArgumentException('customer_sla_event_source_unavailable');
        }
        $policies = CustomerSlaPolicyVersion::query()
            ->where('organization_id', $query->scope->organizationId)
            ->where('effective_from', '<=', $query->asOf)
            ->where(static function ($builder) use ($query): void {
                $builder->whereNull('effective_to')->orWhere('effective_to', '>', $query->asOf);
            })
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get();
        if ($policies->isEmpty()) {
            throw new InvalidArgumentException('customer_sla_policy_unavailable');
        }

        $sourceHash = hash('sha256', CanonicalJson::encode([
            'event_hashes' => $events->pluck('evidence_hash')->all(),
            'policy_versions' => $policies->map(static fn (CustomerSlaPolicyVersion $policy): array => [
                'id' => (int) $policy->id,
                'version' => (string) $policy->version,
                'updated_at' => $policy->updated_at?->toISOString(),
            ])->all(),
        ]));
        $generatedAt = CarbonImmutable::now('UTC');
        $snapshotId = (string) Str::ulid();
        $groups = $events->groupBy(static fn (CustomerWorkflowEvent $event): string =>
            $event->workflow_type.':'.$event->workflow_id);

        DB::transaction(function () use (
            $query,
            $events,
            $policies,
            $sourceHash,
            $generatedAt,
            $snapshotId,
            $groups,
        ): void {
            $existing = CustomerSlaSnapshot::query()
                ->where('organization_id', $query->scope->organizationId)
                ->where('source_hash', $sourceHash)
                ->where('definition_hash', $query->definition->definitionHash->value)
                ->lockForUpdate()
                ->first();
            if ($existing instanceof CustomerSlaSnapshot) {
                return;
            }
            CustomerSlaSnapshot::query()->create([
                'id' => $snapshotId,
                'organization_id' => $query->scope->organizationId,
                'definition_hash' => $query->definition->definitionHash->value,
                'source_hash' => $sourceHash,
                'formula_version' => self::FORMULA_VERSION,
                'scope_identity' => $query->scope->canonicalIdentity(),
                'filters' => $query->filters->values,
                'as_of' => $query->asOf,
                'generated_at' => $generatedAt,
                'stale_at' => $generatedAt->addMinutes(15),
                'watermarks' => [
                    'source_schema_version' => 'customer-sla.v1',
                    'last_event_id' => (int) ($events->max('id') ?? 0),
                    'last_policy_version_id' => (int) ($policies->max('id') ?? 0),
                ],
                'row_count' => $groups->count(),
            ]);

            foreach ($groups as $workflowEvents) {
                $first = $workflowEvents->first();
                if (!$first instanceof CustomerWorkflowEvent) {
                    continue;
                }
                $policyRecord = $this->selectPolicy($policies, $first);
                $policy = $this->policy($policyRecord);
                $opened = $workflowEvents->first(
                    static fn (CustomerWorkflowEvent $event): bool =>
                        $event->event_type === CustomerWorkflowEventType::OPENED,
                );
                if (!$opened instanceof CustomerWorkflowEvent) {
                    throw new InvalidArgumentException('customer_sla_open_event_missing');
                }
                $fact = new CustomerWorkflowFact(
                    (string) $first->workflow_type,
                    (int) $first->workflow_id,
                    CarbonImmutable::instance($opened->occurred_at),
                    CarbonImmutable::instance($query->asOf),
                    $workflowEvents->map(static fn (CustomerWorkflowEvent $event): array => [
                        'type' => $event->event_type,
                        'actor_side' => $event->actor_side,
                        'occurred_at' => CarbonImmutable::instance($event->occurred_at),
                    ])->all(),
                    $this->pauseWindows($workflowEvents, $policy, CarbonImmutable::instance($query->asOf)),
                );
                $metric = $this->formula->evaluate($fact, $policy);
                $last = $workflowEvents->last();
                $rowKey = $first->workflow_type.':'.$first->workflow_id;
                CustomerSlaRow::query()->create([
                    'organization_id' => $query->scope->organizationId,
                    'snapshot_id' => $snapshotId,
                    'project_id' => $first->project_id,
                    'customer_organization_id' => $first->customer_organization_id,
                    'workflow_type' => (string) $first->workflow_type,
                    'workflow_id' => (int) $first->workflow_id,
                    'priority' => $last?->priority,
                    'owner_id' => $last?->owner_id,
                    'status' => (string) $last?->current_status,
                    'opened_at' => $opened->occurred_at,
                    'first_response_seconds' => $metric->firstResponseSeconds,
                    'resolution_seconds' => $metric->resolutionSeconds,
                    'open_aging_seconds' => $metric->openAgingSeconds,
                    'first_response_breached' => $metric->firstResponseBreached,
                    'resolution_breached' => $metric->resolutionBreached,
                    'actor_side_complete' => $metric->actorSideComplete && $first->customer_organization_id !== null,
                    'event_refs' => $workflowEvents->map(static fn (CustomerWorkflowEvent $event): array => [
                        'event_id' => (string) $event->event_id,
                        'event_type' => $event->event_type->value,
                    ])->all(),
                    'row_key' => $rowKey,
                ]);
            }
        }, 3);

        $snapshot = CustomerSlaSnapshot::query()
            ->where('organization_id', $query->scope->organizationId)
            ->where('source_hash', $sourceHash)
            ->where('definition_hash', $query->definition->definitionHash->value)
            ->firstOrFail();

        return new ReportSnapshotRef(
            'customer_sla',
            (string) $snapshot->id,
            $query->scope,
            new Sha256Hash((string) $snapshot->definition_hash),
            (string) $snapshot->formula_version,
            new Sha256Hash((string) $snapshot->source_hash),
            DateTimeImmutable::createFromInterface($snapshot->generated_at),
            $snapshot->stale_at === null ? null : DateTimeImmutable::createFromInterface($snapshot->stale_at),
            $snapshot->watermarks,
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );
    }

    private function selectPolicy(Collection $policies, CustomerWorkflowEvent $event): CustomerSlaPolicyVersion
    {
        $matching = $policies->filter(static function (CustomerSlaPolicyVersion $policy) use ($event): bool {
            return ($policy->project_id === null || (int) $policy->project_id === (int) $event->project_id)
                && ($policy->customer_organization_id === null || (int) $policy->customer_organization_id === (int) $event->customer_organization_id)
                && ($policy->workflow_type === null || $policy->workflow_type === $event->workflow_type)
                && ($policy->priority === null || $policy->priority === $event->priority)
                && $policy->effective_from <= $event->occurred_at
                && ($policy->effective_to === null || $policy->effective_to > $event->occurred_at);
        })->sortByDesc(static function (CustomerSlaPolicyVersion $policy): string {
            $specificity = ($policy->project_id !== null ? 1 : 0)
                + ($policy->customer_organization_id !== null ? 1 : 0)
                + ($policy->workflow_type !== null ? 1 : 0)
                + ($policy->priority !== null ? 1 : 0);

            return sprintf('%d:%s:%020d', $specificity, $policy->effective_from->format('U.u'), (int) $policy->id);
        });
        $selected = $matching->first();
        if (!$selected instanceof CustomerSlaPolicyVersion) {
            throw new InvalidArgumentException('customer_sla_policy_unavailable');
        }

        return $selected;
    }

    private function policy(CustomerSlaPolicyVersion $record): CustomerSlaPolicy
    {
        $weekdays = [];
        foreach ($record->weekday_intervals as $weekday => $intervals) {
            $weekdays[(int) $weekday] = $intervals;
        }

        return new CustomerSlaPolicy(
            (string) $record->timezone,
            $weekdays,
            $record->holidays,
            $record->pause_statuses,
            (int) $record->first_response_target_seconds,
            (int) $record->resolution_target_seconds,
            (string) $record->version,
        );
    }

    private function pauseWindows(
        Collection $events,
        CustomerSlaPolicy $policy,
        CarbonImmutable $asOf,
    ): array {
        $windows = [];
        $startedAt = null;
        foreach ($events as $event) {
            $isPaused = in_array((string) $event->current_status, $policy->pauseStatuses, true);
            if ($isPaused && $startedAt === null) {
                $startedAt = CarbonImmutable::instance($event->occurred_at);
            }
            if (!$isPaused && $startedAt !== null) {
                $windows[] = new CustomerSlaPauseWindow($startedAt, CarbonImmutable::instance($event->occurred_at));
                $startedAt = null;
            }
        }
        if ($startedAt !== null && $asOf > $startedAt) {
            $windows[] = new CustomerSlaPauseWindow($startedAt, $asOf);
        }

        return $windows;
    }
}
