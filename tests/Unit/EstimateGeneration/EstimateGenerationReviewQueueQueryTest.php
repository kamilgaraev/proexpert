<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationReviewQueueQuery;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationReviewQueueQueryTest extends TestCase
{
    public function test_sql_contract_pages_canonical_projection_with_latest_revision_fence_and_factual_totals(): void
    {
        $query = new EstimateGenerationReviewQueueQuery;
        $base = $query->baseSql();
        $summary = $query->summarySql("review_item->>'severity' = ?");
        $page = $query->pageSql("review_item->>'required_action' = ?");

        self::assertStringContainsString('CROSS JOIN LATERAL jsonb_array_elements', $base);
        self::assertStringContainsString("draft_payload #> '{quality_summary,review_queue_items}'", $base);
        self::assertStringContainsString('DISTINCT ON (items.package_id, COALESCE(items.logical_key, items.key))', $base);
        self::assertStringContainsString('items.revision DESC, items.id DESC', $base);
        self::assertStringContainsString('packages.session_id = ?', $base);
        self::assertStringContainsString('sessions.organization_id = ?', $base);
        self::assertStringContainsString('COUNT(*) FILTER', $summary);
        self::assertStringContainsString('LIMIT ? OFFSET ?', $page);
        self::assertStringContainsString("review_item->>'key'", $page);
        self::assertStringNotContainsString('cursor', $base);
        self::assertStringNotContainsString('SELECT *', $base);
    }
}
