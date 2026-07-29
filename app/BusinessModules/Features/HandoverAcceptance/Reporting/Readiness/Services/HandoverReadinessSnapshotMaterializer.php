<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Services;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\DTO\HandoverChecklistFact;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\DTO\HandoverEvidenceFact;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\DTO\HandoverGateDefinition;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Models\HandoverEvidenceEvent;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Models\HandoverGateVersion;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Models\HandoverReadinessRow;
use App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\Models\HandoverReadinessSnapshot;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class HandoverReadinessSnapshotMaterializer
{
    public const FORMULA_VERSION = 'handover.v1';

    public function __construct(private HandoverReadinessFormula $formula) {}

    public function materialize(ReportExecutionContext $context, ReportQuery $query): ReportSnapshotRef
    {
        $this->assertContext($context, $query);
        $gates = HandoverGateVersion::query()
            ->where('organization_id', $query->scope->organizationId)
            ->where('effective_from', '<=', $query->asOf)
            ->where(static function ($builder) use ($query): void {
                $builder->whereNull('effective_to')->orWhere('effective_to', '>', $query->asOf);
            })
            ->when(
                $query->scope->projectIds !== [],
                fn ($builder) => $builder->whereIn('project_id', $query->scope->projectIds),
            )
            ->orderBy('project_id')
            ->orderBy('acceptance_scope_id')
            ->orderBy('gate_code')
            ->orderByDesc('gate_version')
            ->get()
            ->unique(static fn (HandoverGateVersion $gate): string => $gate->acceptance_scope_id.':'.$gate->gate_code)
            ->values();

        if ($gates->isEmpty()) {
            throw new InvalidArgumentException('handover_gate_policy_unavailable');
        }

        $events = HandoverEvidenceEvent::query()
            ->where('organization_id', $query->scope->organizationId)
            ->whereIn('acceptance_scope_id', $gates->pluck('acceptance_scope_id')->all())
            ->where('occurred_at', '<=', $query->asOf)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
        $sourceProjection = [
            'as_of' => $query->asOf->format(DATE_ATOM),
            'event_hashes' => $events->pluck('evidence_hash')->all(),
            'gate_versions' => $gates->map(static fn (HandoverGateVersion $gate): array => [
                'id' => (int) $gate->id,
                'source_hash' => (string) $gate->source_hash,
                'version' => (int) $gate->gate_version,
            ])->all(),
            'scope' => $query->scope->canonicalIdentity(),
        ];
        $sourceHash = hash('sha256', CanonicalJson::encode($sourceProjection));
        $generatedAt = CarbonImmutable::now('UTC');
        $snapshotId = (string) Str::ulid();

        DB::transaction(function () use (
            $query,
            $gates,
            $events,
            $sourceHash,
            $generatedAt,
            $snapshotId,
        ): void {
            $existing = HandoverReadinessSnapshot::query()
                ->where('organization_id', $query->scope->organizationId)
                ->where('source_hash', $sourceHash)
                ->where('definition_hash', $query->definition->definitionHash->value)
                ->lockForUpdate()
                ->first();
            if ($existing instanceof HandoverReadinessSnapshot) {
                return;
            }

            HandoverReadinessSnapshot::query()->create([
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
                    'as_of' => $query->asOf->format(DATE_ATOM),
                    'source_schema_version' => 'handover-readiness.v1',
                    'last_event_id' => (int) ($events->max('id') ?? 0),
                    'last_gate_version_id' => (int) ($gates->max('id') ?? 0),
                ],
                'row_count' => $gates->count(),
            ]);

            foreach ($gates as $gate) {
                $scopeEvents = $events->where('acceptance_scope_id', (int) $gate->acceptance_scope_id);
                $checklists = [];
                $evidence = [];
                foreach ($scopeEvents as $event) {
                    if ($event->event_type === 'checklist_reviewed' && $event->source_code !== null) {
                        $checklists[] = new HandoverChecklistFact(
                            (string) $event->source_code,
                            (string) $event->status,
                        );
                    }
                    $evidence[] = new HandoverEvidenceFact(
                        (string) $event->event_type,
                        (string) $event->source_type,
                        (int) $event->source_id,
                        $event->source_code === null ? null : (string) $event->source_code,
                        (string) $event->status,
                        CarbonImmutable::instance($event->occurred_at),
                        (int) $event->source_version,
                    );
                }
                $metric = $this->formula->evaluate(
                    new HandoverGateDefinition(
                        (string) $gate->gate_code,
                        $gate->required_checklist_codes,
                        $gate->required_document_codes,
                        $gate->hard_blocker_source_types,
                        (bool) $gate->explicitly_empty_requirements,
                    ),
                    $checklists,
                    $evidence,
                );
                $rowKey = implode(':', [
                    (int) $gate->project_id,
                    (int) $gate->acceptance_scope_id,
                    (string) $gate->gate_code,
                    (int) $gate->gate_version,
                ]);
                HandoverReadinessRow::query()->create([
                    'organization_id' => $query->scope->organizationId,
                    'snapshot_id' => $snapshotId,
                    'project_id' => (int) $gate->project_id,
                    'acceptance_scope_id' => (int) $gate->acceptance_scope_id,
                    'location_id' => $gate->location_id,
                    'package_id' => $gate->package_id,
                    'gate_code' => (string) $gate->gate_code,
                    'due_on' => $gate->due_policy['due_on'] ?? null,
                    'mandatory_completeness' => $metric->mandatoryCompleteness,
                    'document_completeness' => $metric->documentCompleteness,
                    'open_hard_blocker_count' => $metric->openHardBlockerCount,
                    'attempt_count' => $metric->attemptCount,
                    'successful_result_count' => $metric->successfulResultCount,
                    'ready' => $metric->ready,
                    'evidence_refs' => $scopeEvents->map(static fn (HandoverEvidenceEvent $event): array => [
                        'event_id' => (string) $event->event_id,
                        'source_type' => (string) $event->source_type,
                        'source_id' => (int) $event->source_id,
                    ])->values()->all(),
                    'row_key' => $rowKey,
                ]);
            }
        }, 3);

        $snapshot = HandoverReadinessSnapshot::query()
            ->where('organization_id', $query->scope->organizationId)
            ->where('source_hash', $sourceHash)
            ->where('definition_hash', $query->definition->definitionHash->value)
            ->firstOrFail();

        return $this->reference($query, $snapshot);
    }

    public function reference(ReportQuery $query, HandoverReadinessSnapshot $snapshot): ReportSnapshotRef
    {
        return new ReportSnapshotRef(
            'handover_readiness',
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

    private function assertContext(ReportExecutionContext $context, ReportQuery $query): void
    {
        if (
            $query->definition->code !== 'handover_readiness'
            || $context->scope->canonicalIdentity() !== $query->scope->canonicalIdentity()
        ) {
            throw new InvalidArgumentException('handover_readiness_context_invalid');
        }
    }
}
