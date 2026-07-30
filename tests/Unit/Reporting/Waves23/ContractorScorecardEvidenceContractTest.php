<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Services\ContractorReviewEventProjector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContractorScorecardEvidenceContractTest extends TestCase
{
    #[Test]
    public function review_projection_uses_observed_at_for_membership_and_excludes_unrelated_events_from_identity(): void
    {
        if (! class_exists(ContractorReviewEventProjector::class)) {
            require_once dirname(__DIR__, 4)
                .'/app/BusinessModules/ContractorMarketplace/Reporting/Scorecard/Services/'
                .'ContractorReviewEventProjector.php';
        }
        $projection = (new ContractorReviewEventProjector)->project(
            [
                $this->reviewEvent(
                    10,
                    101,
                    7,
                    70,
                    '2025-01-01T00:00:00+00:00',
                    '2026-07-30T10:00:00+00:00',
                    'hash-101-v1',
                ),
                $this->reviewEvent(
                    11,
                    101,
                    7,
                    70,
                    '2025-01-01T00:00:00+00:00',
                    '2026-07-30T11:00:00+00:00',
                    'hash-101-v2',
                ),
                $this->reviewEvent(
                    12,
                    202,
                    7,
                    99,
                    '2026-07-01T00:00:00+00:00',
                    '2026-07-30T12:00:00+00:00',
                    'hash-202',
                ),
            ],
            7,
            [70],
            null,
        );

        self::assertSame([101], array_keys($projection));
        self::assertSame(
            '2026-07-30T11:00:00+00:00',
            $projection[101]['membership_observed_at']->format(DATE_ATOM),
        );
        self::assertSame('2025-01-01T00:00:00+00:00', $projection[101]['created_at']->format(DATE_ATOM));
        self::assertSame(
            [['event_id' => 11, 'evidence_hash' => 'hash-101-v2']],
            ContractorReviewEventProjector::identityEvents($projection),
        );
    }

    #[Test]
    public function marketplace_reviews_are_pinned_before_scorecard_materialization(): void
    {
        $root = dirname(__DIR__, 4);
        $sourceResolver = (string) file_get_contents(
            $root.'/app/BusinessModules/ContractorMarketplace/Reporting/Scorecard/Services/'
            .'ContractorScorecardSourceResolver.php',
        );
        $materializer = (string) file_get_contents(
            $root.'/app/BusinessModules/ContractorMarketplace/Reporting/Scorecard/Services/'
            .'ContractorScorecardSnapshotMaterializer.php',
        );
        $backfill = (string) file_get_contents(
            $root.'/app/BusinessModules/ContractorMarketplace/Reporting/Scorecard/Backfill/'
            .'ContractorScorecardBackfill.php',
        );

        self::assertStringContainsString('ContractorReviewSnapshotResolver', $sourceResolver);
        self::assertStringContainsString('contractor_scorecard_review_snapshot_rows', $materializer);
        self::assertStringNotContainsString('MarketplaceHiringOfferReview::query()', $materializer);
        self::assertStringContainsString('contractor_scorecard_review_events', $backfill);
        self::assertStringNotContainsString('MarketplaceHiringOfferReview::query()', $backfill);
    }

    #[Test]
    public function review_evidence_migration_has_trigger_backfill_and_immutable_fences(): void
    {
        $migration = (string) file_get_contents(
            dirname(__DIR__, 4)
            .'/database/migrations/2026_07_30_140000_create_contractor_scorecard_review_evidence.php',
        );

        self::assertStringContainsString(
            'AFTER INSERT OR UPDATE OR DELETE ON marketplace_hiring_offer_reviews',
            $migration,
        );
        self::assertStringContainsString("'BACKFILL'", $migration);
        self::assertStringContainsString('contractor_scorecard_review_events', $migration);
        self::assertStringContainsString('contractor_scorecard_review_snapshots', $migration);
        self::assertStringContainsString('contractor_scorecard_review_snapshot_rows', $migration);
        self::assertStringContainsString('contractor_review_evidence_is_immutable', $migration);
    }

    private function reviewEvent(
        int $eventId,
        int $reviewId,
        int $organizationId,
        int $projectId,
        string $createdAt,
        string $observedAt,
        string $evidenceHash,
    ): object {
        return (object) [
            'id' => $eventId,
            'review_id' => $reviewId,
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'observed_at' => $observedAt,
            'is_deleted' => false,
            'evidence_hash' => $evidenceHash,
            'payload' => [
                'reviewer_organization_id' => $organizationId,
                'project_id' => $projectId,
                'created_at' => $createdAt,
            ],
        ];
    }
}
