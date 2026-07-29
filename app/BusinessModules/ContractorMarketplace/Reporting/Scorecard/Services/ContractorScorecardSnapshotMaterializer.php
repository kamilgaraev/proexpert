<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Services;

use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceHiringOfferReview;
use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\DTO\ContractorComponentMetric;
use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\DTO\ContractorComponentSignal;
use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\DTO\ContractorObjectiveObservationIndex;
use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Models\ContractorScorecardPolicyVersion;
use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Models\ContractorScorecardRow;
use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Models\ContractorScorecardSnapshot;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
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
        if (
            $query->definition->code !== 'contractor_scorecard'
            || $context->scope->canonicalIdentity() !== $query->scope->canonicalIdentity()
        ) {
            throw new InvalidArgumentException('contractor_scorecard_context_invalid');
        }
        $tuple = $this->sources->resolve($context, $query);
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
        $components = $this->components($policy);
        $this->assertPinnedSources($tuple, $components);
        $objectiveObservations = $this->observations->load($tuple);
        $reviews = MarketplaceHiringOfferReview::query()
            ->where('reviewer_organization_id', $query->scope->organizationId)
            ->where('created_at', '<=', $query->asOf)
            ->when(
                $query->scope->projectIds !== [],
                fn ($builder) => $builder->whereIn('project_id', $query->scope->projectIds),
            )
            ->orderBy('id')
            ->get();
        $groups = $reviews->groupBy(static fn (MarketplaceHiringOfferReview $review): string => implode(':', [
            (int) $review->contractor_profile_id,
            (int) $review->category_id,
            (int) $review->project_id,
        ]));
        $sourceHash = hash('sha256', CanonicalJson::encode([
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
            $components,
            $groups,
            $objectiveObservations,
            $sourceHash,
            $generatedAt,
            $staleAt,
            $snapshotId,
            $rowCount,
        ): void {
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
                $first = $group->first();
                if (! $first instanceof MarketplaceHiringOfferReview) {
                    continue;
                }
                $cohortKey = $this->cohortKey($group, $policy);
                foreach ($components as $component) {
                    $observations = $this->componentObservations(
                        $group,
                        $component,
                        $objectiveObservations,
                        (int) $first->contractor_profile_id,
                        (int) $first->project_id,
                    );
                    $rawMetric = $this->formula->component(
                        $component['code'],
                        $component['unit_code'],
                        $observations['signals'],
                    );
                    $metric = $this->applyPublicationThresholds($rawMetric, $policy);
                    $rowKey = implode(':', [
                        (int) $first->contractor_profile_id,
                        (int) $first->category_id,
                        (int) $first->project_id,
                        $cohortKey,
                        $component['code'],
                    ]);
                    ContractorScorecardRow::query()->create([
                        'organization_id' => $query->scope->organizationId,
                        'snapshot_id' => $snapshotId,
                        'profile_id' => (int) $first->contractor_profile_id,
                        'category_id' => (int) $first->category_id,
                        'project_id' => (int) $first->project_id,
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
    ): array {
        $field = $component['source_metric'] ?? self::REVIEW_FIELDS[$component['code']] ?? null;
        if ($component['source_report_code'] === 'marketplace_reviews') {
            if ($field === null || ! in_array($field, self::REVIEW_FIELDS, true)) {
                throw new InvalidArgumentException('contractor_scorecard_review_component_invalid');
            }

            return [
                'signals' => $reviews->map(static fn (MarketplaceHiringOfferReview $review): ContractorComponentSignal => new ContractorComponentSignal(
                    $review->getAttribute($field) === null ? null : (string) $review->getAttribute($field),
                    true,
                ))->all(),
                'evidence' => $reviews->map(static fn (MarketplaceHiringOfferReview $review): array => [
                'offer_id' => (int) $review->offer_id,
                'review_id' => (int) $review->id,
                ])->values()->all(),
            ];
        }

        return $objectiveObservations->observations(
            $component['source_report_code'],
            $profileId,
            $projectId,
            $component['unit_code'],
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

    private function cohortKey(Collection $reviews, ContractorScorecardPolicyVersion $policy): string
    {
        $period = $policy->cohort_rules['period'] ?? null;
        $last = $reviews->max('created_at');
        if (! $last instanceof \DateTimeInterface || ! in_array($period, ['month', 'quarter', 'year'], true)) {
            throw new InvalidArgumentException('contractor_scorecard_cohort_invalid');
        }
        $date = CarbonImmutable::instance($last);

        return match ($period) {
            'month' => $date->format('Y-m'),
            'quarter' => $date->year.'-Q'.$date->quarter,
            'year' => $date->format('Y'),
        };
    }

    private function watermark(array $watermarks): string
    {
        $hash = hash('sha256', CanonicalJson::encode($watermarks));

        return 'watermark_'.substr($hash, 0, 32);
    }
}
