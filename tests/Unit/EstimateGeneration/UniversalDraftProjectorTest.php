<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use PHPUnit\Framework\TestCase;

final class UniversalDraftProjectorTest extends TestCase
{
    public function test_unaccepted_model_volume_and_legacy_zero_price_stay_unknown_without_hiding_the_work(): void
    {
        $items = [['key' => 'unknown', 'quantity' => '123', 'unit' => 'm2', 'total_cost' => 0],
            ['key' => 'supported', 'quantity' => '999', 'unit' => 'm2', 'total_cost' => 0,
                'quantity_evidence' => ['amount' => '1.25', 'unit' => '100 m2', 'assumptions' => []]],
            ['key' => 'assumed', 'unit' => 'm2', 'quantity_evidence' => ['amount' => '20', 'unit' => 'm2', 'assumptions' => ['area_scenario']]]];
        $draft = ['local_estimates' => [['key' => 'finish_finishing', 'sections' => [['work_items' => $items]]]]];
        $result = (new UniversalDraftProjector)->project($draft, static fn (array $item): bool => $item['key'] !== 'unknown');
        $rows = $result['local_estimates'][0]['sections'][0]['work_items'];
        self::assertCount(3, $rows);
        self::assertNull($rows[0]['quantity']);
        self::assertNull($rows[0]['total_cost']);
        self::assertSame('125.00', $rows[1]['quantity']);
        self::assertSame('assumed', $rows[2]['quantity_status']);
        self::assertSame('finishing', $rows[1]['evaluation_section']);
        self::assertSame([], $result['stage6_candidate_rows']);
    }

    public function test_incompatible_units_cannot_turn_an_accepted_fact_into_a_work_volume(): void
    {
        $draft = ['local_estimates' => [['key' => 'earthworks', 'sections' => [['work_items' => [
            ['key' => 'excavation', 'unit' => 'm3', 'quantity_evidence' => ['amount' => '50', 'unit' => 'm2']]]]]]]];
        $result = (new UniversalDraftProjector)->project($draft, static fn (): bool => true);
        self::assertNull($result['local_estimates'][0]['sections'][0]['work_items'][0]['quantity']);
    }
}
