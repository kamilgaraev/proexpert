<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\ControlDimensionData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\ScaleCandidateData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\ScaleResolver;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScaleResolverTest extends TestCase
{
    #[Test]
    public function no_dimension_keeps_scale_missing(): void
    {
        $result = (new ScaleResolver)->resolve([], [], null);

        self::assertSame('missing', $result->status);
        self::assertNull($result->metersPerUnit);
        self::assertSame('geometry_scale_unconfirmed', $result->blockingIssue);
    }

    #[Test]
    public function user_control_dimension_confirms_scale_with_provenance(): void
    {
        $control = self::control(10.0);
        $result = (new ScaleResolver)->resolve([], [], $control);

        self::assertSame('confirmed', $result->status);
        self::assertSame(0.02, $result->metersPerUnit);
        self::assertSame(['control-1'], $result->evidenceRefs);
    }

    #[Test]
    public function exact_two_percent_is_accepted_but_more_is_conflict(): void
    {
        $accepted = (new ScaleResolver)->resolve([
            self::candidate('vector', 0.01, 'v1'),
            self::candidate('vector', 0.0102, 'v2'),
        ], [], null);
        $conflict = (new ScaleResolver)->resolve([
            self::candidate('vector', 0.01, 'v1'),
            self::candidate('vector', 0.01020001, 'v2'),
        ], [], null);

        self::assertSame('confirmed', $accepted->status);
        self::assertSame('conflict', $conflict->status);
        self::assertSame('geometry_scale_conflict', $conflict->blockingIssue);
    }

    #[Test]
    public function higher_priority_source_does_not_hide_confirmed_conflict(): void
    {
        $result = (new ScaleResolver)->resolve(
            [self::candidate('vector', 0.01, 'v1')],
            [self::candidate('vision', 0.02, 'ai1'), self::candidate('vision', 0.02, 'ai2')],
            self::control(10.0),
        );

        self::assertSame('conflict', $result->status);
        self::assertSame(['ai1', 'ai2', 'control-1', 'v1'], $result->evidenceRefs);
    }

    #[Test]
    public function vision_requires_two_unique_evidence_references(): void
    {
        $single = (new ScaleResolver)->resolve([], [self::candidate('vision', 0.01, 'ai1')], null);
        $duplicate = (new ScaleResolver)->resolve([], [self::candidate('vision', 0.01, 'ai1'), self::candidate('vision', 0.01, 'ai1')], null);

        self::assertSame('missing', $single->status);
        self::assertSame('missing', $duplicate->status);
    }

    #[Test]
    public function invalid_dimensions_and_cross_context_control_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ControlDimensionData([1.0, 1.0], [1.0, 1.0], INF, 7, 'control-1', 'sha256:'.str_repeat('a', 64), 1, 'transform:v1');
    }

    #[Test]
    public function control_context_must_match_candidates(): void
    {
        $result = (new ScaleResolver)->resolve(
            [self::candidate('vector', 0.01, 'v1', 2)],
            [],
            self::control(5.0),
        );

        self::assertSame('conflict', $result->status);
    }

    private static function candidate(string $source, float $scale, string $evidence, int $page = 1): ScaleCandidateData
    {
        return new ScaleCandidateData($source, $scale, $evidence, 'sha256:'.str_repeat('a', 64), $page, 'transform:v1', 'runtime:v1', 'model:v1', 0.9);
    }

    private static function control(float $meters): ControlDimensionData
    {
        return new ControlDimensionData([100.0, 100.0], [600.0, 100.0], $meters, 7, 'control-1', 'sha256:'.str_repeat('a', 64), 1, 'transform:v1');
    }
}
