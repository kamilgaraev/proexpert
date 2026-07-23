<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentVersion;
use App\Http\Responses\AdminResponse;
use App\Services\LegalArchive\Files\LegalDocumentScanFailed;
use Illuminate\Support\Facades\Facade;
use RuntimeException;
use Tests\TestCase;

use function trans_message;

final class LegalDocumentScanFailureApiContractTest extends TestCase
{
    public function refreshDatabase(): void {}

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();

        parent::tearDown();
    }

    public function test_failed_scan_is_an_accepted_saved_resource_response(): void
    {
        $response = AdminResponse::success(
            ['id' => 41, 'document_id' => 17, 'processing_status' => 'failed'],
            trans_message('legal_archive.messages.version_file_processing_failed'),
            202,
            [
                'processing_status' => 'failed',
                'operation_result' => 'version_created',
                'retry_action' => 'retry_upload',
                'retry_document_id' => 17,
            ],
        );
        $payload = $response->getData(true);

        self::assertSame(202, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertSame(41, $payload['data']['id']);
        self::assertSame('failed', $payload['data']['processing_status']);
        self::assertSame('failed', $payload['meta']['processing_status']);
        self::assertSame('retry_upload', $payload['meta']['retry_action']);
        self::assertSame(17, $payload['meta']['retry_document_id']);
        self::assertSame(
            'Версия сохранена, но файл не прошёл проверку безопасности. Загрузите новую версию файла.',
            $payload['message'],
        );
    }

    public function test_scan_failure_exposes_a_safe_processing_reason(): void
    {
        $version = new LegalArchiveDocumentVersion;

        self::assertSame(
            'scanner_unavailable',
            (new LegalDocumentScanFailed($version, new RuntimeException('legal_document_scanner_unavailable')))->failureCode(),
        );
        self::assertSame(
            'malware_detected',
            (new LegalDocumentScanFailed($version, new RuntimeException('legal_document_malware_detected')))->failureCode(),
        );
        self::assertSame(
            'scan_failed',
            (new LegalDocumentScanFailed($version, new RuntimeException('unexpected_scanner_failure')))->failureCode(),
        );
    }
}
