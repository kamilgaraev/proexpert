<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement\Reporting\Award;

use PHPUnit\Framework\TestCase;

final class ProcurementAwardSelectionSourceContractTest extends TestCase
{
    public function test_source_bounds_and_orders_the_locked_candidate_set_before_loading_evidence(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 5).'/app/BusinessModules/Features/Procurement/Reporting/Award/Services/EloquentProcurementAwardSelectionSource.php',
        );

        self::assertIsString($source);
        self::assertStringContainsString('->orderBy(\'proposal.id\')', $source);
        self::assertStringContainsString('->limit(ProcurementAwardManifestBuilder::CANDIDATE_LIMIT + 1)', $source);
        self::assertStringContainsString("throw new DomainException('procurement_award_candidate_limit_exceeded')", $source);
        self::assertStringContainsString("->lock('FOR UPDATE OF proposal')", $source);
    }

    public function test_event_history_loads_candidates_in_one_bounded_bulk_query(): void
    {
        $store = file_get_contents(
            dirname(__DIR__, 5).'/app/BusinessModules/Features/Procurement/Reporting/Award/Services/EloquentProcurementAwardEvidenceStore.php',
        );

        self::assertIsString($store);
        self::assertStringContainsString("->whereIn('event_id', \$rows->pluck('id')->all())", $store);
        self::assertStringContainsString("->groupBy('event_id')", $store);
    }
}
