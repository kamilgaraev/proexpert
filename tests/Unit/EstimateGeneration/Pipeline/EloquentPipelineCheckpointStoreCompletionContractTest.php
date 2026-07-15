<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EloquentPipelineCheckpointStoreCompletionContractTest extends TestCase
{
    #[Test]
    public function document_checkpoint_completion_does_not_require_the_session_generation_attempt(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Pipeline/EloquentPipelineCheckpointStore.php',
        );

        self::assertIsString($source);
        self::assertSame(1, preg_match(
            '/public function complete\(.*?public function renewLease\(/s',
            $source,
            $completionMethod,
        ));
        self::assertStringContainsString(
            '$claim->context->documentId === null',
            $completionMethod[0],
        );
    }
}
