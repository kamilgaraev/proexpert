<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureCategory;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\EloquentGenerationPipelineDataGateway;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PipelineDataGatewayBoundsTest extends TestCase
{
    #[Test]
    public function byte_limit_accepts_exact_boundary_and_rejects_first_byte_over_without_truncation(): void
    {
        $gateway = (new ReflectionClass(EloquentGenerationPipelineDataGateway::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(EloquentGenerationPipelineDataGateway::class, 'boundedBytes');
        $method->setAccessible(true);
        $encoded = '{"x":"y"}';
        $before = EloquentGenerationPipelineDataGateway::MAX_SOURCE_BYTES - strlen($encoded);

        self::assertSame(EloquentGenerationPipelineDataGateway::MAX_SOURCE_BYTES, $method->invoke($gateway, $before, ['x' => 'y']));

        try {
            $method->invoke($gateway, $before + 1, ['x' => 'y']);
            self::fail('First byte over the source budget must fail.');
        } catch (\ReflectionException $error) {
            throw $error;
        } catch (\Throwable $error) {
            $actual = $error instanceof \ReflectionException ? $error : ($error->getPrevious() ?? $error);
            self::assertInstanceOf(PipelineStageException::class, $actual);
            self::assertSame(FailureCategory::UserActionRequired, $actual->category);
            self::assertSame('pipeline_source_too_large', $actual->safeCode);
        }
    }

    #[Test]
    public function query_contract_requests_one_extra_row_for_exact_overflow_detection(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Pipeline/EloquentGenerationPipelineDataGateway.php');

        self::assertSame(500, EloquentGenerationPipelineDataGateway::MAX_DOCUMENTS);
        self::assertSame(10_000, EloquentGenerationPipelineDataGateway::MAX_SOURCE_ROWS);
        self::assertStringContainsString('self::MAX_DOCUMENTS + 1', $source);
        self::assertStringContainsString('++$rows > self::MAX_SOURCE_ROWS', $source);
        self::assertStringContainsString('->cursor()', $source);
        self::assertStringNotContainsString('->take(self::MAX', $source);
    }

    #[Test]
    public function source_projection_does_not_copy_heavy_structured_document_payload(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Pipeline/EloquentGenerationPipelineDataGateway.php');

        self::assertStringNotContainsString("'structured_payload' => \$this->json(\$row->structured_payload)", $source);
        self::assertStringNotContainsString("'structured_payload', 'facts_summary'", $source);
        self::assertStringContainsString("'facts_summary' => \$this->json(\$row->facts_summary)", $source);
    }
}
