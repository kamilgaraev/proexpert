<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\ControlDimensionData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\ScaleCandidateData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\ScaleContextData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\ScaleResolutionData;
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
    public function next_float_above_boundary_conflicts_even_for_tiny_scales(): void
    {
        $base = 1.0e-12;
        $boundary = $base * 1.02;
        $below = $boundary * (1.0 - PHP_FLOAT_EPSILON);
        $above = $boundary * (1.0 + PHP_FLOAT_EPSILON);

        self::assertSame('confirmed', (new ScaleResolver)->resolve([self::candidate('vector', $base, 'v1'), self::candidate('vector', $below, 'v2')], [], null)->status);
        self::assertSame('confirmed', (new ScaleResolver)->resolve([self::candidate('vector', $base, 'v1'), self::candidate('vector', $boundary, 'v2')], [], null)->status);
        self::assertSame('conflict', (new ScaleResolver)->resolve([self::candidate('vector', $base, 'v1'), self::candidate('vector', $above, 'v2')], [], null)->status);
    }

    #[Test]
    public function unconfirmed_single_vision_does_not_block_valid_vector_or_control(): void
    {
        $resolver = new ScaleResolver;

        self::assertSame('confirmed', $resolver->resolve([self::candidate('vector', 0.01, 'v1')], [self::candidate('vision', 0.2, 'ai1')], null)->status);
        self::assertSame('confirmed', $resolver->resolve([], [self::candidate('vision', 0.2, 'ai1')], self::control(10.0))->status);
        self::assertSame('confirmed', $resolver->resolve([self::candidate('vector', 0.01, 'v1')], [self::candidate('vision', 0.2, 'ai1'), self::candidate('vision', 0.2, 'ai1')], null)->status);
        self::assertSame('confirmed', $resolver->resolve([], [self::candidate('vision', 0.2, 'ai1'), self::candidate('vision', 0.2, 'ai1')], self::control(10.0))->status);
    }

    #[Test]
    public function contexts_must_match_within_and_across_confirmed_groups(): void
    {
        $resolver = new ScaleResolver;

        self::assertSame('conflict', $resolver->resolve([self::candidate('vector', 0.01, 'v1'), self::candidate('vector', 0.01, 'v2', 2)], [], null)->status);
        self::assertSame('conflict', $resolver->resolve([], [self::candidate('vision', 0.01, 'ai1'), self::candidate('vision', 0.01, 'ai2', 2)], null)->status);
        self::assertSame('conflict', $resolver->resolve([self::candidate('vector', 0.01, 'v1')], [self::candidate('vision', 0.01, 'ai1', 2), self::candidate('vision', 0.01, 'ai2', 2)], null)->status);
    }

    #[Test]
    public function duplicate_scale_evidence_uses_one_measurement_and_discards_ambiguous_values(): void
    {
        $same = self::candidate('vector', 0.01, 'v1');
        self::assertSame(0.01, (new ScaleResolver)->resolve([$same, $same], [], null)->metersPerUnit);

        $metadataVariant = new ScaleCandidateData(
            'vector', 0.01, 'v1', 'sha256:'.str_repeat('a', 64), 1,
            'transform:v1', 'runtime:v2', 'model:v2', 0.7,
        );
        self::assertSame(0.01, (new ScaleResolver)->resolve([$same, $metadataVariant], [], null)->metersPerUnit);

        $ambiguous = (new ScaleResolver)->resolve([$same, self::candidate('vector', 0.02, 'v1')], [], null);
        self::assertSame('missing', $ambiguous->status);
        self::assertNull($ambiguous->metersPerUnit);
    }

    #[Test]
    public function scale_resolution_rejects_invalid_state_combinations(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new \App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\ScaleResolutionData('confirmed', null, [], null);
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidResolutionStates')]
    public function scale_resolution_rejects_every_invalid_state(string $status, ?float $scale, array $evidence, ?string $issue, ?ScaleContextData $context): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ScaleResolutionData($status, $scale, $evidence, $issue, $context);
    }

    public static function invalidResolutionStates(): array
    {
        $context = new ScaleContextData('sha256:'.str_repeat('a', 64), 1, 'transform:v1', 'source_units_v1');

        return [
            ['other', null, [], null, null],
            ['confirmed', 0.01, [], null, $context],
            ['confirmed', 0.01, ['e1'], null, null],
            ['missing', 0.01, [], 'geometry_scale_unconfirmed', null],
            ['missing', null, ['e1'], 'geometry_scale_unconfirmed', null],
            ['conflict', null, ['e1'], 'geometry_scale_conflict', null],
            ['conflict', null, ['e1', 'e1'], 'geometry_scale_conflict', null],
            ['conflict', null, [''], 'geometry_scale_conflict', null],
            ['missing', null, [], 'geometry_scale_conflict', null],
            ['conflict', null, ['e1', 'e2'], 'geometry_scale_unconfirmed', null],
            ['confirmed', 0.01, ['e1'], 'geometry_scale_conflict', $context],
            ['confirmed', 0.0, ['e1'], null, $context],
            ['confirmed', INF, ['e1'], null, $context],
            ['confirmed', 0.01, [''], null, $context],
            ['missing', null, [], 'geometry_scale_unconfirmed', $context],
            ['conflict', null, ['e1', 'e2'], 'geometry_scale_conflict', $context],
        ];
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
