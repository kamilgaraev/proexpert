<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeMatchTelemetry;
use PHPUnit\Framework\TestCase;

final class NormativeMatchTelemetryTest extends TestCase
{
    public function test_it_aggregates_pipeline_outcomes_without_work_item_data(): void
    {
        $telemetry = new NormativeMatchTelemetry;

        $telemetry->required();
        $telemetry->missingPinnedCandidate();
        $telemetry->required();
        $telemetry->pinnedCandidatesFound(2);
        $telemetry->rejected(['normative_section_mismatch', 'unit_mismatch']);
        $telemetry->required();
        $telemetry->rejected(['unit_mismatch']);
        $telemetry->required();
        $telemetry->blocked('catalog_content_not_pinned');
        $telemetry->required();
        $telemetry->matched();

        $context = $telemetry->context();

        self::assertSame([
            'required_items_count' => 5,
            'pinned_candidates_missing_count' => 1,
            'pinned_candidates_found_count' => 2,
            'workflow_rejected_count' => 2,
            'matched_items_count' => 1,
            'blocked_reason_counts' => [
                'catalog_content_not_pinned' => 1,
                'pinned_candidate_missing' => 1,
                'workflow_rejected' => 2,
            ],
            'rejection_reason_counts' => [
                'normative_section_mismatch' => 1,
                'unit_mismatch' => 2,
            ],
        ], $context);
        self::assertSame(
            $context['required_items_count'],
            $context['matched_items_count'] + array_sum($context['blocked_reason_counts']),
        );
    }
}
