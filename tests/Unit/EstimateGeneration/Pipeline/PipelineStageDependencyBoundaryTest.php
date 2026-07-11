<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PipelineStageDependencyBoundaryTest extends TestCase
{
    #[Test]
    #[DataProvider('stages')]
    public function stage_declares_exact_processing_stage(string $class, ProcessingStage $expected): void
    {
        $stage = (new ReflectionClass($class))->newInstanceWithoutConstructor();

        self::assertInstanceOf(PipelineStage::class, $stage);
        self::assertSame($expected, $stage->stage());
    }

    #[Test]
    #[DataProvider('dependentStages')]
    public function missing_prior_output_fails_before_any_dependency_is_used(string $class, ProcessingStage $expected): void
    {
        $stage = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $this->expectException(DomainException::class);

        $stage->execute(new PipelineContext(1, 2, 3, 4, 'attempt-1', 'generating'));
    }

    public static function stages(): array
    {
        return array_map(static fn (ProcessingStage $stage): array => [
            'App\\BusinessModules\\Addons\\EstimateGeneration\\Pipeline\\Stages\\'.str_replace(' ', '', ucwords(str_replace('_', ' ', $stage->value))).'Stage',
            $stage,
        ], ProcessingStage::cases());
    }

    public static function dependentStages(): array
    {
        return array_slice(self::stages(), 1);
    }
}
