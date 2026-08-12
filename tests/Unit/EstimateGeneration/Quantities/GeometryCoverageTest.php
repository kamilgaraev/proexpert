<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryCoverage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GeometryCoverageTest extends TestCase
{
    private const SOURCE = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    #[Test]
    public function coverage_states_fail_closed_and_bind_representation_lineage(): void
    {
        foreach (['unknown', 'incomplete'] as $status) {
            $coverage = GeometryCoverage::fromFact($this->fact($status, 0), 'wall_openings');
            self::assertSame('geometry_coverage_incomplete', $coverage?->issue('confirmed', 0)['code'] ?? null);
        }
        foreach (['stale', 'conflicted'] as $status) {
            $coverage = GeometryCoverage::fromFact($this->fact($status, 0), 'wall_openings');
            self::assertSame('geometry_coverage_blocked', $coverage?->issue('confirmed', 0)['code'] ?? null);
        }

        $empty = GeometryCoverage::fromFact($this->fact('covered_empty', 0), 'wall_openings');
        self::assertNull($empty?->issue('confirmed', 0));
        self::assertSame('artifact:plan', $empty?->identity()['representation']['source_artifact_id'] ?? null);
        self::assertSame('geometry_coverage_conflict', $empty?->issue('confirmed', 1)['code'] ?? null);

        $covered = GeometryCoverage::fromFact($this->fact('covered_with_entities', 2), 'roof_facets');
        self::assertNull($covered?->issue('confirmed', 2));
        self::assertSame('geometry_coverage_conflict', $covered?->issue('confirmed', 1)['code'] ?? null);
        self::assertSame('geometry_coverage_blocked', $covered?->issue('invalidated', 2)['code'] ?? null);
    }

    private function fact(string $status, int $count): Fact
    {
        return new Fact(
            'fact:coverage:'.$status.':'.$count,
            1,
            2,
            3,
            self::SOURCE,
            $status === 'covered_with_entities' ? 'roof:1' : 'wall:1',
            'geometry_coverage',
            [
                'relation' => $status === 'covered_with_entities' ? 'roof_facets' : 'wall_openings',
                'status' => $status,
                'entity_count' => $count,
                'representation' => [
                    'type' => 'cad_geometry',
                    'id' => 'representation:1',
                    'source_artifact_id' => 'artifact:plan',
                    'source_version' => self::SOURCE,
                ],
            ],
            null,
            1.0,
            'document',
            'confirmed',
            ['evidence:coverage'],
        );
    }
}
