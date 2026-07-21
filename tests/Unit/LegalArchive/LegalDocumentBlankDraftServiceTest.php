<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\Services\LegalArchive\Editor\LegalDocumentBlankDraftService;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class LegalDocumentBlankDraftServiceTest extends TestCase
{
    public function test_it_creates_a_valid_neutral_docx_package(): void
    {
        $service = (new \ReflectionClass(LegalDocumentBlankDraftService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($service, 'createDocx');
        $method->setAccessible(true);
        $path = $method->invoke($service, 'Черновик договора');
        self::assertIsString($path);

        try {
            $zip = new ZipArchive;
            self::assertTrue($zip->open($path) === true);
            self::assertNotFalse($zip->locateName('[Content_Types].xml'));
            self::assertNotFalse($zip->locateName('word/document.xml'));
            self::assertStringContainsString('Черновик договора', (string) $zip->getFromName('word/document.xml'));
            $zip->close();
        } finally {
            @unlink($path);
        }
    }
}
