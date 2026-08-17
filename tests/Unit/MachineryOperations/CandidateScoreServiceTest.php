<?php

declare(strict_types=1);

namespace Tests\Unit\MachineryOperations;

use App\BusinessModules\Features\MachineryOperations\Services\CandidateScoreService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CandidateScoreServiceTest extends TestCase
{
    #[DataProvider('locationCases')]
    public function test_location_points_are_bounded_and_missing_coordinates_are_neutral(
        bool $sameProject,
        ?float $distance,
        float $expected,
    ): void {
        $scored = (new CandidateScoreService)->score(new Collection([
            $this->candidate(true, $sameProject, $distance, 1000),
        ]))->first();

        self::assertSame($expected, $scored['score_breakdown']['location']['points']);
        self::assertGreaterThanOrEqual(0, $scored['score']);
        self::assertLessThanOrEqual(100, $scored['score']);
    }

    public static function locationCases(): array
    {
        return [
            'same project wins without coordinates' => [true, null, 30.0],
            'missing coordinates are neutral' => [false, null, 15.0],
            'zero distance' => [false, 0.0, 30.0],
            'half distance' => [false, 50.0, 15.0],
            'distance is capped' => [false, 1000.0, 0.0],
        ];
    }

    public function test_cost_is_normalized_within_eligible_candidates_and_equal_costs_do_not_penalize(): void
    {
        $service = new CandidateScoreService;
        $different = $service->score(new Collection([
            $this->candidate(true, false, null, 1000),
            $this->candidate(true, false, null, 3000),
        ]));
        $equal = $service->score(new Collection([
            $this->candidate(true, false, null, 2000),
            $this->candidate(true, false, null, 2000),
        ]));

        self::assertSame(30.0, $different[0]['score_breakdown']['cost']['points']);
        self::assertSame(0.0, $different[1]['score_breakdown']['cost']['points']);
        self::assertSame(30.0, $equal[0]['score_breakdown']['cost']['points']);
        self::assertSame(30.0, $equal[1]['score_breakdown']['cost']['points']);
    }

    public function test_hard_exclusion_has_no_score_or_suitability_number(): void
    {
        $scored = (new CandidateScoreService)->score(new Collection([
            $this->candidate(false, true, 0, 0),
        ]))->first();

        self::assertNull($scored['score']);
        self::assertSame('excluded', $scored['suitability']);
        self::assertSame('Не подходит', $scored['suitability_label']);
        self::assertNull($scored['score_breakdown']);
    }

    /** @return array<string, mixed> */
    private function candidate(bool $eligible, bool $sameProject, ?float $distance, float $cost): array
    {
        return [
            'eligible' => $eligible,
            'same_project' => $sameProject,
            'distance_km' => $distance,
            'operating_cost_per_hour' => $cost,
        ];
    }
}
