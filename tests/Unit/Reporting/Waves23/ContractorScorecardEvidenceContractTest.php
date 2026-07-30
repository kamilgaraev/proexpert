<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContractorScorecardEvidenceContractTest extends TestCase
{
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
}
