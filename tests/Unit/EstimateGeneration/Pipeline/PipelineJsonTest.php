<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineJson;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PipelineJsonTest extends TestCase
{
    #[Test]
    public function empty_map_is_encoded_as_a_json_object(): void
    {
        self::assertSame('{}', PipelineJson::object([]));
    }

    #[Test]
    public function dependency_map_preserves_its_keys(): void
    {
        self::assertSame(
            '{"understand_documents":"sha256:version"}',
            PipelineJson::object(['understand_documents' => 'sha256:version']),
        );
    }
}
