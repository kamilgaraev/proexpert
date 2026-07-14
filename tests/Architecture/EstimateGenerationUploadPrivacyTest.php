<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationUploadPrivacyTest extends TestCase
{
    #[Test]
    public function ai_uploads_enable_redacted_file_service_logging_without_changing_other_callers(): void
    {
        $storage = file_get_contents(dirname(__DIR__, 2).'/app/BusinessModules/Addons/EstimateGeneration/Services/Ocr/OcrDocumentStorageService.php');
        $files = file_get_contents(dirname(__DIR__, 2).'/app/Services/Storage/FileService.php');

        self::assertIsString($storage);
        self::assertIsString($files);
        self::assertStringContainsString('privacyMode: true', $storage);
        self::assertStringContainsString('bool $privacyMode = false', $files);
        self::assertStringContainsString("\$privacyMode ? 'redacted' : \$fullPath", $files);
        self::assertStringContainsString("\$privacyMode ? 'redacted' : \$realPath", $files);
        self::assertStringContainsString("\$privacyMode ? hash('sha256'", $files);
    }
}
