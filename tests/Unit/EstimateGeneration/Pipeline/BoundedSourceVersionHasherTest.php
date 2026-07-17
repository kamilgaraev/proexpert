<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\BoundedSourceVersionHasher;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BoundedSourceVersionHasherTest extends TestCase
{
    #[Test]
    public function exact_row_limit_is_stable_and_a_field_mutation_changes_digest(): void
    {
        $digest = static function (bool $mutate): string {
            $hasher = new BoundedSourceVersionHasher;
            $hasher->assertCounts(10_000, 10_000);
            $hasher->start(1, ['quality' => 1]);
            for ($id = 1; $id <= 10_000; $id++) {
                $hasher->update(1, ['id' => $id, 'value' => $mutate && $id === 5000 ? 'b' : 'a']);
            }

            return $hasher->finish()[1];
        };

        self::assertSame($digest(false), $digest(false));
        self::assertNotSame($digest(false), $digest(true));
    }

    #[Test]
    public function row_overflow_is_rejected_before_any_row_is_consumed_and_structure_bytes_are_bounded(): void
    {
        $hasher = new BoundedSourceVersionHasher;
        try {
            $hasher->assertCounts(10_001, 10_001);
            self::fail('Overflow accepted.');
        } catch (PipelineStageException $error) {
            self::assertSame('pipeline_source_too_large', $error->safeCode);
        }

        $hasher = new BoundedSourceVersionHasher;
        $hasher->assertCounts(1, 1);
        $this->expectException(PipelineStageException::class);
        $hasher->start(1, [str_repeat('key', intdiv(BoundedSourceVersionHasher::MAX_BYTES, 3)) => 'value']);
    }

    #[Test]
    public function large_opaque_payload_is_hashed_without_rejecting_valid_pipeline_input(): void
    {
        $digest = static function (string $suffix): string {
            $hasher = new BoundedSourceVersionHasher;
            $hasher->assertCounts(1, 1);
            $hasher->start(1, ['structured_payload' => str_repeat('x', BoundedSourceVersionHasher::MAX_BYTES).$suffix]);

            return $hasher->finish()[1];
        };

        self::assertSame($digest('a'), $digest('a'));
        self::assertNotSame($digest('a'), $digest('b'));
    }
}
