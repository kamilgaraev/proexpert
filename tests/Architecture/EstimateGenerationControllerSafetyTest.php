<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationControllerSafetyTest extends TestCase
{
    #[Test]
    public function public_read_endpoints_delegate_to_the_safe_responder(): void
    {
        $sessionController = $this->source('EstimateGenerationController.php');
        $documentController = $this->source('EstimateGenerationDocumentController.php');

        self::assertGreaterThanOrEqual(7, substr_count($sessionController, '$this->safeReadResponse('));
        self::assertGreaterThanOrEqual(2, substr_count($documentController, '$this->safeReadResponse('));
        self::assertStringContainsString('catch (HttpExceptionInterface $exception)', $sessionController);
        self::assertStringContainsString('catch (HttpExceptionInterface $exception)', $documentController);
        self::assertStringContainsString('AdminResponse::error', $sessionController);
        self::assertStringContainsString('AdminResponse::error', $documentController);
    }

    private function source(string $file): string
    {
        $source = file_get_contents(
            dirname(__DIR__, 2).'/app/BusinessModules/Addons/EstimateGeneration/Http/Controllers/'.$file,
        );
        self::assertIsString($source);

        return $source;
    }
}
