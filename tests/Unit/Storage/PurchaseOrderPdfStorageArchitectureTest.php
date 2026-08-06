<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;

final class PurchaseOrderPdfStorageArchitectureTest extends TestCase
{
    public function test_purchase_order_pdf_uses_only_private_current_object_gateway(): void
    {
        $source = file_get_contents(
            __DIR__.'/../../../app/BusinessModules/Features/Procurement/Services/PurchaseOrderPdfService.php',
        );
        self::assertIsString($source);

        self::assertStringContainsString('FileService', $source);
        self::assertStringContainsString('OrganizationStoragePath', $source);
        self::assertStringContainsString('putPrivate(', $source);
        self::assertStringContainsString('readCurrent(', $source);
        self::assertStringContainsString('deleteCurrent(', $source);
        self::assertStringContainsString('CurrentStoredFile', $source);
        self::assertStringNotContainsString('OrgBucketService', $source);
        self::assertStringNotContainsString('getDisk(', $source);
        self::assertStringNotContainsString('->url(', $source);

        $workflow = file_get_contents(
            __DIR__.'/../../../app/BusinessModules/Features/Procurement/Services/PurchaseOrderService.php',
        );
        self::assertIsString($workflow);
        self::assertStringContainsString("'pdf_sha256'", $workflow);
        self::assertStringContainsString("'pdf_etag'", $workflow);
        self::assertStringContainsString("'pdf_size_bytes'", $workflow);
        self::assertStringNotContainsString("'pdf_temporary_url'", $workflow);
        self::assertStringContainsString('compensateFailedPdf(', $workflow);

        $mail = file_get_contents(
            __DIR__.'/../../../app/BusinessModules/Features/Procurement/Mail/PurchaseOrderSentMail.php',
        );
        self::assertIsString($mail);
        self::assertStringContainsString('PurchaseOrderPdfService', $mail);
        self::assertStringContainsString('->read(', $mail);
        self::assertStringContainsString('$pdfPath', $mail);
        self::assertStringNotContainsString('$pdfUrl', $mail);
        self::assertStringNotContainsString('file_get_contents(', $mail);
    }
}
