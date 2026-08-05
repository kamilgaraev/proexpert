<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Services;

use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\DTO\ContractorComponentMetric;
use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\DTO\ContractorComponentSignal;
use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\DTO\ContractorObjectiveObservationIndex;
use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Models\ContractorScorecardPolicyVersion;
use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Models\ContractorScorecardRow;
use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Models\ContractorScorecardSnapshot;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ContractorScorecardSnapshotMaterializer
{
    public const FORMULA_VERSION = 'contractor-scorecard.v1';

    private const REVIEW_FIELDS = [
        'marketplace_quality' => 'quality_score',
        'marketplace_deadline' => 'deadline_score',
        'marketplace_communication' => 'communication_score',
        'marketplace_safety' => 'safety_score',
        'marketplace_financial_discipline' => 'financial_discipline_score',
    ];

    public function __construct(
        private ContractorScorecardSourceResolver $sources,
        private ContractorScorecardObservationReader $observations,
        private ContractorScorecardFormula $formula,
    ) {}

    public function materialize(ReportExecutionContext $context, ReportQuery $query): ReportSnapshotRef
    {
        $asOf = $query->filters->values['as_of'] ?? null;
        if (
            $query->definition->code !== 'contractor_scorecard'
            || $context->scope->canonicalIdentity() !== $query->scope->canonicalIdentity()
            || ! is_string($asOf)
            || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $asOf) !== 1
            || $asOf !== $query->asOf->format('Y-m-d')
        ) {
            throw new InvalidArgumentException('contractor_scorecard_context_invalid');
        }
        $policy = ContractorScorecardPolicyVersion::query()
            ->where('organization_id', $query->scope->organizationId)
            ->where('effective_from', '<=', $query->asOf)
            ->where(static function ($builder) use ($query): void {
                $builder->whereNull('effective_to')->orWhere('effective_to', '>', $query->asOf);
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
        if (! $policy instanceof ContractorScorecardPolicyVersion) {
            throw new InvalidArgumentException('contractor_scorecard_policy_unavailable');
        }
        $cohortPeriod = $policy->cohort_rules['period'] ?? null;
        if (! in_array($cohortPeriod, ['month', 'quarter', 'year'], true)) {
            throw new InvalidArgumentException('contractor_scorecard_cohort_invalid');
        }
        $cohortKey = $query->filters->values['cohort'] ?? $this->cohortKey(
            CarbonImmutable::instance($query->asOf),
            $cohortPeriod,
        );
        if (! is_string($cohortKey)) {
            throw new InvalidArgumentException('contractor_scorecard_cohort_invalid');
        }
        $query = new ReportQuery(
            $query->definition,
            $query->scope,
            new ReportFilterSet([...$query->filters->values, 'cohort' => $cohortKey]),
            $query->comparison,
            $query->asOf,
            $query->locale,
        );
        [$periodFrom, $periodTo] = $this->cohortBounds($cohortKey, $cohortPeriod);
        $tuple = $this->sources->resolve($context, $query, $periodFrom, $periodTo);
        $components = $this->components($policy);
        $this->assertPinnedSources($tuple, $components);
        $objectiveObservations = $this->observations->load($tuple);
        if ((int) ($tuple->marketplaceReviews->watermarks['unknown_count'] ?? 0) !== 0) {
            throw new InvalidArgumentException('contractor_scorecard_review_membership_unknown');
        }
        $reviews = DB::table('contractor_scorecard_review_snapshot_rows')
            ->where('organization_id', $query->scope->organizationId)
            ->where('snapshot_id', $tuple->marketplaceReviews->id)
            ->orderBy('review_id')
            ->get()
            ->filter(fn (object $review): bool => $this->matchesRequestedCohort(
                CarbonImmutable::parse($review->created_at),
                $policy,
                $cohortKey,
            ));
        $groups = $this->groups(
            $reviews,
            $objectiveObservations,
            $policy,
            $query->asOf,
            $cohortKey,
        );
        $sourceHash = hash('sha256', CanonicalJson::encode([
            'filters' => $query->filters->values,
            'policy_id' => (int) $policy->id,
            'policy_source_hash' => (string) $policy->source_hash,
            'policy_version' => (string) $policy->version,
            'scope' => $query->scope->canonicalIdentity(),
            'as_of' => $query->asOf->format(DATE_ATOM),
            'source_tuple_hash' => $tuple->hash(),
        ]));
        $generatedAt = CarbonImmutable::now('UTC');
        $staleAt = collect($tuple->refs())
            ->map(static fn (ReportSnapshotRef $ref): ?CarbonImmutable => $ref->staleAt === null ? null : CarbonImmutable::instance($ref->staleAt))
            ->filter()
            ->sort()
            ->first();
        $snapshotId = (string) Str::ulid();
        $rowCount = $groups->count() * count($components);

        DB::transaction(function () use (
            $query,
            $tuple,
            $policy,
            $cohortPeriod,
            $components,
            $groups,
            $objectiveObservations,
            $sourceHash,
            $generatedAt,
            $staleAt,
            $snapshotId,
            $rowCount,
        ): void {
            DB::table('organizations')
                ->where('id', $query->scope->organizationId)
                ->lockForUpdate()
                ->firstOrFail();
            $existing = ContractorScorecardSnapshot::query()
                ->where('organization_id', $query->scope->organizationId)
                ->where('source_hash', $sourceHash)
                ->where('definition_hash', $query->definition->definitionHash->value)
                ->lockForUpdate()
                ->first();
            if ($existing instanceof ContractorScorecardSnapshot) {
                return;
            }
            ContractorScorecardSnapshot::query()->create([
                'id' => $snapshotId,
                'organization_id' => $query->scope->organizationId,
                'policy_version_id' => (int) $policy->id,
                'definition_hash' => $query->definition->definitionHash->value,
                'source_hash' => $sourceHash,
                'source_tuple_hash' => $tuple->hash(),
                'formula_version' => self::FORMULA_VERSION,
                'scope_identity' => $query->scope->canonicalIdentity(),
                'filters' => $query->filters->values,
                'as_of' => $query->asOf,
                'generated_at' => $generatedAt,
                'stale_at' => $staleAt,
                'watermarks' => [
                    'as_of' => $query->asOf->format(DATE_ATOM),
                    'cohort_key' => $cohortKey,
                    'filters_hash' => hash('sha256', CanonicalJson::encode($query->filters->values)),
                    'source_schema_version' => 'contractor-scorecard.v1',
                    'source_tuple_hash' => $tuple->hash(),
                ],
                'row_count' => $rowCount,
            ]);
            foreach ($tuple->refs() as $ref) {
                DB::table('contractor_scorecard_snapshot_sources')->insert([
                    'organization_id' => $query->scope->organizationId,
                    'snapshot_id' => $snapshotId,
                    'source_report_code' => $ref->kind,
                    'source_snapshot_id' => $ref->id,
                    'source_hash' => $ref->sourceHash->value,
                    'formula_version' => $ref->formulaVersion,
                    'source_schema_version' => (string) $ref->watermarks['source_schema_version'],
                    'watermark' => $this->watermark($ref->watermarks),
                ]);
            }

            foreach ($groups as $group) {
                $profileId = $group['profile_id'];
                $categoryId = $group['category_id'];
                $projectId = $group['project_id'];
                $cohortKey = $group['cohort_key'];
                $groupReviews = $group['reviews'];
                foreach ($components as $component) {
                    $observations = $this->componentObservations(
                        $groupReviews,
                        $component,
                        $objectiveObservations,
                        $profileId,
                        $projectId,
                        $cohortKey,
                        $cohortPeriod,
                    );
                    $rawMetric = $this->formula->component(
                        $component['code'],
                        $component['unit_code'],
                        $observations['signals'],
                    );
                    $metric = $this->applyPublicationThresholds($rawMetric, $policy);
                    $rowKey = implode(':', [
                        $profileId,
                        $categoryId,
                        $projectId,
                        $cohortKey,
                        $component['code'],
                    ]);
                    ContractorScorecardRow::query()->create([
                        'organization_id' => $query->scope->organizationId,
                        'snapshot_id' => $snapshotId,
                        'profile_id' => $profileId,
                        'category_id' => $categoryId,
                        'project_id' => $projectId,
                        'cohort_key' => $cohortKey,
                        'component_code' => $metric->componentCode,
                        'unit_code' => $metric->unitCode,
                        'component_mean' => $metric->mean,
                        'sample_size' => $metric->sampleSize,
                        'eligible_count' => $metric->eligibleCount,
                        'coverage' => $metric->coverage,
                        'evidence_refs' => $observations['evidence'],
                        'row_key' => $rowKey,
                    ]);
                }
            }
        }, 3);

        $snapshot = ContractorScorecardSnapshot::query()
            ->where('organization_id', $query->scope->organizationId)
            ->where('source_hash', $sourceHash)
            ->where('definition_hash', $query->definition->definitionHash->value)
            ->firstOrFail();

        return new ReportSnapshotRef(
            'contractor_scorecard',
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

    private function components(ContractorScorecardPolicyVersion $policy): array
    {
        if (! is_array($policy->components) || ! array_is_list($policy->components) || $policy->components === []) {
            throw new InvalidArgumentException('contractor_scorecard_components_invalid');
        }
        $codes = [];
        foreach ($policy->components as $component) {
            if (
                ! is_array($component)
                || ! is_string($component['code'] ?? null)
                || ! is_string($component['unit_code'] ?? null)
                || ! is_string($component['source_report_code'] ?? null)
                || ! is_string($component['source_formula_version'] ?? null)
                || ! is_string($component['source_schema_version'] ?? null)
                || ! is_string($component['source_metric'] ?? null)
                || isset($codes[$component['code']])
            ) {
                throw new InvalidArgumentException('contractor_scorecard_components_invalid');
            }
            $codes[$component['code']] = true;
        }

        return $policy->components;
    }

    private function componentObservations(
        Collection $reviews,
        array $component,
        ContractorObjectiveObservationIndex $objectiveObservations,
        int $profileId,
        int $projectId,
        string $cohortKey,
        string $cohortPeriod,
    ): array {
        $field = $component['source_metric'] ?? self::REVIEW_FIELDS[$component['code']] ?? null;
        if ($component['source_report_code'] === 'marketplace_reviews') {
            if ($field === null || ! in_array($field, self::REVIEW_FIELDS, true)) {
                throw new InvalidArgumentException('contractor_scorecard_review_component_invalid');
            }

            return [
                'signals' => $reviews->map(static fn (object $review): ContractorComponentSignal => new ContractorComponentSignal(
                    $review->{$field} === null ? null : (string) $review->{$field},
                    true,
                ))->all(),
                'evidence' => $reviews->map(static fn (object $review): array => [
                    'offer_id' => (int) $review->offer_id,
                    'review_id' => (int) $review->review_id,
                ])->values()->all(),
            ];
        }

        return $objectiveObservations->observations(
            $component['source_report_code'],
            $profileId,
            $projectId,
            $component['source_metric'],
            $component['unit_code'],
            $cohortKey,
            $cohortPeriod,
        );
    }

    private function assertPinnedSources(
        \App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\DTO\ContractorScorecardSourceTuple $tuple,
        array $components,
    ): void {
        $refs = [];
        foreach ($tuple->refs() as $ref) {
            $refs[$ref->kind] = $ref;
        }
        foreach ($components as $component) {
            $ref = $refs[$component['source_report_code']] ?? null;
            if (
                ! $ref instanceof ReportSnapshotRef
                || ! hash_equals($ref->formulaVersion, $component['source_formula_version'])
                || ! hash_equals(
                    (string) $ref->watermarks['source_schema_version'],
                    $component['source_schema_version'],
                )
            ) {
                throw new InvalidArgumentException('contractor_scorecard_policy_source_incompatible');
            }
        }
    }

    private function applyPublicationThresholds(
        ContractorComponentMetric $metric,
        ContractorScorecardPolicyVersion $policy,
    ): ContractorComponentMetric {
        $publishable = $metric->sampleSize >= (int) $policy->minimum_sample_size
            && $metric->coverage !== null
            && bccomp($metric->coverage, (string) $policy->minimum_coverage, 8) >= 0;

        return new ContractorComponentMetric(
            $metric->componentCode,
            $metric->unitCode,
            $publishable ? $metric->mean : null,
            $metric->sampleSize,
            $metric->eligibleCount,
            $metric->coverage,
        );
    }

    private function groups(
        Collection $reviews,
        ContractorObjectiveObservationIndex $objectiveObservations,
        ContractorScorecardPolicyVersion $policy,
        \DateTimeInterface $asOf,
        mixed $requestedCohort,
    ): Collection {
        $period = $policy->cohort_rules['period'] ?? null;
        if (! in_array($period, ['month', 'quarter', 'year'], true)) {
            throw new InvalidArgumentException('contractor_scorecard_cohort_invalid');
        }
        $groups = [];
        foreach ($reviews as $review) {
            $cohortKey = $this->cohortKey(CarbonImmutable::parse($review->created_at), $period);
            $key = implode(':', [
                (int) $review->contractor_profile_id,
                (int) $review->category_id,
                (int) $review->project_id,
                $cohortKey,
            ]);
            $groups[$key] ??= [
                'profile_id' => (int) $review->contractor_profile_id,
                'category_id' => (int) $review->category_id,
                'project_id' => (int) $review->project_id,
                'cohort_key' => $cohortKey,
                'reviews' => collect(),
            ];
            $groups[$key]['reviews']->push($review);
        }

        if ($requestedCohort !== null && ! is_string($requestedCohort)) {
            throw new InvalidArgumentException('contractor_scorecard_cohort_invalid');
        }
        $objectiveCohort = $requestedCohort ?? $this->cohortKey(CarbonImmutable::instance($asOf), $period);
        $objectiveDimensions = $requestedCohort === null
            ? $objectiveObservations->profileProjectCohorts($period)
            : $this->singleCohortDimensions(
                $objectiveObservations->profileProjects($objectiveCohort, $period),
                $objectiveCohort,
            );
        foreach ($objectiveDimensions as $profileId => $projects) {
            foreach ($projects as $projectId => $cohorts) {
                foreach (array_keys($cohorts) as $cohortKey) {
                    foreach ($objectiveObservations->categoryIdsForDimension(
                        (int) $profileId,
                        (int) $projectId,
                        $cohortKey,
                        $period,
                    ) as $categoryId) {
                        $key = implode(':', [$profileId, $categoryId, $projectId, $cohortKey]);
                        $groups[$key] ??= [
                            'profile_id' => (int) $profileId,
                            'category_id' => $categoryId,
                            'project_id' => (int) $projectId,
                            'cohort_key' => $cohortKey,
                            'reviews' => collect(),
                        ];
                    }
                }
            }
        }

        return collect(array_values($groups));
    }

    private function singleCohortDimensions(array $profileProjects, string $cohortKey): array
    {
        $dimensions = [];
        foreach ($profileProjects as $profileId => $projects) {
            foreach (array_keys($projects) as $projectId) {
                $dimensions[(int) $profileId][(int) $projectId][$cohortKey] = true;
            }
        }

        return $dimensions;
    }

    private function matchesRequestedCohort(
        CarbonImmutable $occurredAt,
        ContractorScorecardPolicyVersion $policy,
        mixed $requestedCohort,
    ): bool {
        if ($requestedCohort === null) {
            return true;
        }
        $period = $policy->cohort_rules['period'] ?? null;
        if (! is_string($requestedCohort) || ! in_array($period, ['month', 'quarter', 'year'], true)) {
            throw new InvalidArgumentException('contractor_scorecard_cohort_invalid');
        }

        return hash_equals($this->cohortKey($occurredAt, $period), $requestedCohort);
    }

    private function cohortKey(CarbonImmutable $date, string $period): string
    {
        return match ($period) {
            'month' => $date->format('Y-m'),
            'quarter' => $date->year.'-Q'.$date->quarter,
            'year' => $date->format('Y'),
        };
    }

    private function cohortBounds(string $cohortKey, string $period): array
    {
        if ($period === 'month' && preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/D', $cohortKey, $parts) === 1) {
            $start = CarbonImmutable::create((int) $parts[1], (int) $parts[2], 1, 0, 0, 0, 'UTC');

            return [$start->toDateString(), $start->endOfMonth()->toDateString()];
        }
        if ($period === 'quarter' && preg_match('/^(\d{4})-Q([1-4])$/D', $cohortKey, $parts) === 1) {
            $start = CarbonImmutable::create((int) $parts[1], ((int) $parts[2] - 1) * 3 + 1, 1, 0, 0, 0, 'UTC');

            return [$start->toDateString(), $start->endOfQuarter()->toDateString()];
        }
        if ($period === 'year' && preg_match('/^(\d{4})$/D', $cohortKey, $parts) === 1) {
            $start = CarbonImmutable::create((int) $parts[1], 1, 1, 0, 0, 0, 'UTC');

            return [$start->toDateString(), $start->endOfYear()->toDateString()];
        }

        throw new InvalidArgumentException('contractor_scorecard_cohort_invalid');
    }

    private function watermark(array $watermarks): string
    {
        $hash = hash('sha256', CanonicalJson::encode($watermarks));

        return 'watermark_'.substr($hash, 0, 32);
    }
}
