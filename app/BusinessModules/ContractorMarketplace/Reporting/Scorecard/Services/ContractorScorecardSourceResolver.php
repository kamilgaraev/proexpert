<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Services;

use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceHiringOfferReview;
use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\DTO\ContractorScorecardSourceTuple;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportQuery;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\Models\ReportRunRecord;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class ContractorScorecardSourceResolver
{
    private const REPORT_CODES = [
        'baseline_schedule_variance',
        'supply_reliability',
        'quality_defect_flow',
        'safety_incident_actions',
    ];

    public function resolve(
        ReportExecutionContext $context,
        ReportQuery $query,
    ): ContractorScorecardSourceTuple {
        try {
            $refs = [];
            foreach (self::REPORT_CODES as $code) {
                $refs[$code] = $this->reportSnapshot($context, $query, $code);
            }
            $tuple = new ContractorScorecardSourceTuple(
                $refs['baseline_schedule_variance'],
                $refs['supply_reliability'],
                $refs['quality_defect_flow'],
                $refs['safety_incident_actions'],
                $this->marketplaceReviewsSnapshot($context, $query),
            );
            $tuple->assertCompatible($context, $query);

            return $tuple;
        } catch (ReportContractException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ReportContractException::fromCode(
                ReportErrorCode::REPORT_SOURCE_UNAVAILABLE,
                [],
                $exception,
            );
        }
    }

    private function reportSnapshot(
        ReportExecutionContext $context,
        ReportQuery $query,
        string $code,
    ): ReportSnapshotRef {
        $candidates = ReportRunRecord::query()
            ->where('organization_id', $context->scope->organizationId)
            ->where('report_code', $code)
            ->where('status', 'ready')
            ->where('as_of', $query->asOf)
            ->whereNotNull('snapshot_id')
            ->whereNotNull('source_hash')
            ->orderByDesc('as_of')
            ->orderByDesc('ready_at')
            ->limit(20)
            ->get();
        $record = $candidates->first(static function (ReportRunRecord $candidate) use ($query): bool {
            $projects = array_map('intval', $candidate->scope_project_ids ?? []);

            return $projects === $query->scope->projectIds
                && $candidate->scope_holding_organization_ids === $query->scope->holdingOrganizationIds
                && ($candidate->filters ?? []) === $query->filters->values;
        });
        if (
            ! $record instanceof ReportRunRecord
            || $record->snapshot_stale_at === null
            || $record->snapshot_generated_at === null
        ) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
        }
        $this->assertOwnerSnapshotReady($record, $code, $query);

        return new ReportSnapshotRef(
            $code,
            (string) $record->snapshot_id,
            $query->scope,
            new Sha256Hash((string) $record->definition_hash),
            (string) $record->formula_version,
            new Sha256Hash((string) $record->source_hash),
            DateTimeImmutable::createFromInterface($record->snapshot_generated_at),
            DateTimeImmutable::createFromInterface($record->snapshot_stale_at),
            array_merge($record->snapshot_watermarks ?? [], [
                'source_schema_version' => (string) $record->source_schema_version,
                'as_of' => $record->as_of->format(DATE_ATOM),
                'cohort_key' => ($record->filters ?? [])['cohort'] ?? null,
                'project_ids' => $query->scope->projectIds,
            ]),
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );
    }

    private function marketplaceReviewsSnapshot(
        ReportExecutionContext $context,
        ReportQuery $query,
    ): ReportSnapshotRef {
        $reviews = MarketplaceHiringOfferReview::query()
            ->where('reviewer_organization_id', $context->scope->organizationId)
            ->where('created_at', '<=', $query->asOf)
            ->when(
                $query->scope->projectIds !== [],
                fn ($builder) => $builder->whereIn('project_id', $query->scope->projectIds),
            )
            ->orderBy('id')
            ->get()
            ->filter(fn (MarketplaceHiringOfferReview $review): bool => $this->reviewMatchesCohort(
                CarbonImmutable::instance($review->created_at),
                $query->filters->values['cohort'] ?? null,
            ));
        $projection = $reviews->map(static fn (MarketplaceHiringOfferReview $review): array => [
            'id' => (int) $review->id,
            'offer_id' => (int) $review->offer_id,
            'project_id' => (int) $review->project_id,
            'contractor_profile_id' => (int) $review->contractor_profile_id,
            'category_id' => (int) $review->category_id,
            'quality_score' => (string) $review->quality_score,
            'deadline_score' => (string) $review->deadline_score,
            'communication_score' => (string) $review->communication_score,
            'safety_score' => $review->safety_score === null ? null : (string) $review->safety_score,
            'financial_discipline_score' => $review->financial_discipline_score === null
                ? null
                : (string) $review->financial_discipline_score,
            'created_at' => $review->created_at?->toISOString(),
        ])->all();
        $sourceHash = new Sha256Hash(hash('sha256', CanonicalJson::encode([
            'as_of' => $query->asOf->format(DATE_ATOM),
            'rows' => $projection,
            'scope' => $query->scope->canonicalIdentity(),
        ])));
        $generatedAt = CarbonImmutable::instance($query->asOf);

        return new ReportSnapshotRef(
            'marketplace_reviews',
            'reviews_'.$sourceHash->value,
            $query->scope,
            new Sha256Hash(hash('sha256', 'marketplace-reviews.v1')),
            'marketplace-reviews.v1',
            $sourceHash,
            DateTimeImmutable::createFromInterface($generatedAt),
            null,
            [
                'source_schema_version' => 'marketplace-reviews.v1',
                'as_of' => $query->asOf->format(DATE_ATOM),
                'cohort_key' => $query->filters->values['cohort'] ?? null,
                'project_ids' => $query->scope->projectIds,
                'last_review_id' => (int) ($reviews->max('id') ?? 0),
                'row_count' => $reviews->count(),
            ],
            ReportSnapshotClassification::OPERATIONAL,
            null,
        );
    }

    private function reviewMatchesCohort(CarbonImmutable $createdAt, mixed $cohort): bool
    {
        if ($cohort === null) {
            return true;
        }
        if (! is_string($cohort)) {
            return false;
        }

        if (preg_match('/^(\d{4})-(\d{2})$/D', $cohort, $matches) === 1) {
            return $createdAt->format('Y-m') === $cohort;
        }
        if (preg_match('/^(\d{4})-Q([1-4])$/D', $cohort, $matches) === 1) {
            return (int) $createdAt->format('Y') === (int) $matches[1]
                && $createdAt->quarter === (int) $matches[2];
        }
        if (preg_match('/^\d{4}$/D', $cohort) === 1) {
            return $createdAt->format('Y') === $cohort;
        }

        return false;
    }

    private function assertOwnerSnapshotReady(
        ReportRunRecord $record,
        string $code,
        ReportQuery $query,
    ): void {
        $snapshot = match ($code) {
            'baseline_schedule_variance' => DB::table('baseline_schedule_variance_snapshots')
                ->where('id', $record->snapshot_id)
                ->where('organization_id', $record->organization_id)
                ->first(),
            'supply_reliability' => DB::table('supply_reliability_snapshots')
                ->where('id', $record->snapshot_id)
                ->where('organization_id', $record->organization_id)
                ->where('quality_status', 'complete')
                ->where('reconciliation_status', 'matched')
                ->where('gap_count', 0)
                ->first(),
            'quality_defect_flow' => DB::table('quality_defect_flow_snapshots')
                ->where('id', $record->snapshot_id)
                ->where('organization_id', $record->organization_id)
                ->where('gap_count', 0)
                ->where('unknown_count', 0)
                ->whereColumn('eligible_count', 'projected_count')
                ->first(),
            'safety_incident_actions' => DB::table('safety_incident_snapshots')
                ->where('id', $record->snapshot_id)
                ->where('organization_id', $record->organization_id)
                ->where('gap_count', 0)
                ->where('unknown_count', 0)
                ->whereColumn('eligible_count', 'projected_count')
                ->first(),
            default => null,
        };
        if (
            ! is_object($snapshot)
            || ! hash_equals((string) $snapshot->source_hash, (string) $record->source_hash)
            || ! hash_equals((string) $snapshot->formula_version, (string) $record->formula_version)
            || CarbonImmutable::parse((string) $snapshot->generated_at)
                ->notEqualTo(CarbonImmutable::instance($record->snapshot_generated_at))
            || $snapshot->stale_at === null
            || CarbonImmutable::parse((string) $snapshot->stale_at)->lessThanOrEqualTo(CarbonImmutable::now('UTC'))
        ) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
        }

        $snapshotAsOf = CarbonImmutable::parse((string) $snapshot->as_of);
        $sameAsOf = $code === 'baseline_schedule_variance'
            ? $snapshotAsOf->toDateString() === CarbonImmutable::instance($query->asOf)->toDateString()
            : $snapshotAsOf->equalTo(CarbonImmutable::instance($query->asOf));
        if (! $sameAsOf) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
        }
    }
}
