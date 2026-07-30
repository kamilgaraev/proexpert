<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Backfill;

use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceHiringOfferReview;
use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Services\ContractorMembershipEvidenceResolver;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportSourceBackfill;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillBatch;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceBackfillResult;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use InvalidArgumentException;

final readonly class ContractorScorecardBackfill implements ReportSourceBackfill
{
    public function __construct(private ContractorMembershipEvidenceResolver $memberships) {}

    public function sourceCode(): string
    {
        return 'marketplace_reviews';
    }

    public function sourceSchemaVersion(): string
    {
        return 'contractor-scorecard.v1';
    }

    public function nextBatch(
        ReportSourceBackfillContext $context,
        ReportSourceBackfillCursor $cursor,
        int $limit,
    ): ReportSourceBackfillBatch {
        if ($limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('contractor_scorecard_backfill_limit_invalid');
        }
        if (
            $cursor->position !== []
            && (
                array_keys($cursor->position) !== ['review_id']
                || ! is_int($cursor->position['review_id'])
                || $cursor->position['review_id'] < 0
            )
        ) {
            throw new InvalidArgumentException('contractor_scorecard_backfill_cursor_invalid');
        }
        $afterId = (int) ($cursor->position['review_id'] ?? 0);
        $ids = MarketplaceHiringOfferReview::query()
            ->where('reviewer_organization_id', $context->organizationId)
            ->where('id', '>', $afterId)
            ->where('created_at', '<=', $context->asOf)
            ->when(
                $context->scope->projectIds !== [],
                fn ($builder) => $builder->whereIn('project_id', $context->scope->projectIds),
            )
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $lastId = $ids === [] ? $afterId : $ids[array_key_last($ids)];
        $to = new ReportSourceBackfillCursor(['review_id' => $lastId]);
        $final = count($ids) < $limit;

        return new ReportSourceBackfillBatch(
            $cursor,
            $to,
            $ids,
            $final,
            hash('sha256', CanonicalJson::encode([
                'as_of' => $context->asOf->toISOString(),
                'final' => $final,
                'from' => $cursor->position,
                'organization_id' => $context->organizationId,
                'review_ids' => $ids,
                'source_watermark' => $context->sourceWatermark,
                'to' => $to->position,
            ])),
        );
    }

    public function apply(
        ReportSourceBackfillContext $context,
        ReportSourceBackfillBatch $batch,
    ): ReportSourceBackfillResult {
        $this->assertBatch($context, $batch);
        $reviews = MarketplaceHiringOfferReview::query()
            ->where('reviewer_organization_id', $context->organizationId)
            ->whereIn('id', $batch->sourceKeys)
            ->where('created_at', '<=', $context->asOf)
            ->when(
                $context->scope->projectIds !== [],
                fn ($builder) => $builder->whereIn('project_id', $context->scope->projectIds),
            )
            ->orderBy('id')
            ->get();
        $memberships = $this->memberships->resolve($context->organizationId, $context->asOf);
        $projection = [];
        $projected = 0;
        $unknown = 0;
        foreach ($reviews as $review) {
            $profileOrganizationId = $memberships
                ->profileOrganizationById[(int) $review->contractor_profile_id] ?? null;
            if (
                $profileOrganizationId === null
                || (int) $review->contractor_organization_id !== (int) $profileOrganizationId
                || (int) $review->category_id < 1
                || (int) $review->project_id < 1
            ) {
                $unknown++;

                continue;
            }
            $projection[] = [
                'category_id' => (int) $review->category_id,
                'contractor_organization_id' => (int) $review->contractor_organization_id,
                'profile_id' => (int) $review->contractor_profile_id,
                'project_id' => (int) $review->project_id,
                'review_id' => (int) $review->id,
            ];
            $projected++;
        }
        $eligible = count($batch->sourceKeys);
        $missingRows = max(0, $eligible - $reviews->count());
        $unknown += $missingRows;
        $gap = max(0, $eligible - $projected - $unknown);

        return new ReportSourceBackfillResult(
            $batch->to,
            $eligible,
            $projected,
            $gap,
            $unknown,
            hash('sha256', CanonicalJson::encode([
                'membership_evidence_hash' => $memberships->sourceHash,
                'projection' => $projection,
            ])),
            $batch->final,
        );
    }

    private function assertBatch(
        ReportSourceBackfillContext $context,
        ReportSourceBackfillBatch $batch,
    ): void {
        foreach ($batch->sourceKeys as $sourceKey) {
            if (! is_int($sourceKey) || $sourceKey < 1) {
                throw new InvalidArgumentException('contractor_scorecard_backfill_batch_invalid');
            }
        }
        $expected = hash('sha256', CanonicalJson::encode([
            'as_of' => $context->asOf->toISOString(),
            'final' => $batch->final,
            'from' => $batch->from->position,
            'organization_id' => $context->organizationId,
            'review_ids' => $batch->sourceKeys,
            'source_watermark' => $context->sourceWatermark,
            'to' => $batch->to->position,
        ]));
        $afterId = (int) ($batch->from->position['review_id'] ?? 0);
        $lastId = $batch->sourceKeys === []
            ? $afterId
            : $batch->sourceKeys[array_key_last($batch->sourceKeys)];
        $ordered = $batch->sourceKeys;
        sort($ordered, SORT_NUMERIC);
        if (
            ! hash_equals($expected, $batch->inputHash)
            || (int) ($batch->to->position['review_id'] ?? -1) !== $lastId
            || $batch->sourceKeys !== array_values(array_unique($batch->sourceKeys))
            || $batch->sourceKeys !== $ordered
            || $batch->sourceKeys !== array_values(array_filter(
                $batch->sourceKeys,
                static fn (int $id): bool => $id > $afterId,
            ))
        ) {
            throw new InvalidArgumentException('contractor_scorecard_backfill_batch_invalid');
        }
    }
}
