<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Ocr;

use PHPUnit\Framework\TestCase;

final class MultiPagePdfOcrContractTest extends TestCase
{
    public function test_processor_does_not_block_multi_page_pdf_without_text_layer(): void
    {
        $source = (string) file_get_contents($this->projectPath('app/BusinessModules/Addons/EstimateGeneration/Services/Ocr/OcrDocumentProcessor.php'));

        self::assertStringNotContainsString('$pageCount > 1', $source);
        self::assertStringNotContainsString('ocr_pdf_text_layer_missing', $source);
    }

    public function test_timeweb_pdf_recognition_is_not_limited_to_single_page_pdf(): void
    {
        $source = (string) file_get_contents($this->projectPath('app/BusinessModules/Addons/EstimateGeneration/Services/Ocr/Clients/TimewebVisionOcrClient.php'));

        self::assertStringNotContainsString('$input->pageCount === 1', $source);
    }

    private function projectPath(string $path): string
    {
        return dirname(__DIR__, 4).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}
