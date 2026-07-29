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
            ->where('as_of', '<=', $query->asOf)
            ->whereNotNull('snapshot_id')
            ->whereNotNull('source_hash')
            ->orderByDesc('as_of')
            ->orderByDesc('ready_at')
            ->limit(20)
            ->get();
        $record = $candidates->first(static function (ReportRunRecord $candidate) use ($query): bool {
            $projects = array_map('intval', $candidate->scope_project_ids ?? []);

            return $projects === $query->scope->projectIds
                && $candidate->scope_holding_organization_ids === $query->scope->holdingOrganizationIds;
        });
        if (!$record instanceof ReportRunRecord || $record->snapshot_stale_at === null) {
            throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
        }

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
                'as_of' => $query->asOf->format(DATE_ATOM),
                'cohort_key' => $query->filters->values['cohort'] ?? null,
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
            ->get();
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
        $sourceHash = new Sha256Hash(hash('sha256', CanonicalJson::encode($projection)));
        $generatedAt = CarbonImmutable::instance($query->asOf);

        return new ReportSnapshotRef(
            'marketplace_reviews',
            'reviews_'.$sourceHash->value,
            $query->scope,
            new Sha256Hash(hash('sha256', 'marketplace-reviews.v1')),
            'marketplace-reviews.v1',
            $sourceHash,
            DateTimeImmutable::createFromInterface($generatedAt),
            DateTimeImmutable::createFromInterface($generatedAt->addDay()),
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
}
