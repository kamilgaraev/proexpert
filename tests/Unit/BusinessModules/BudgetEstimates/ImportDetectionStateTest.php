<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\EstimateImportService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\FileStorageService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportDetectionResult;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportFormatDetector;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportFormatRegistry;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\RuntimeImportFormatHandlerInterface;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\SignatureGenerator;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\TemplateService;
use App\Models\ImportSession;
use App\Services\Storage\FileService;
use Mockery;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\EstimateImportTestContext;
use RuntimeException;

final class ImportDetectionStateTest extends TestCase
{
    use RefreshDatabase;

    private EstimateImportTestContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = EstimateImportTestContext::create();
        config(['octane.max_request_timeout' => 30]);
    }

    public function test_storage_failure_is_saved_and_rethrown(): void
    {
        $session = $this->createImportSession();
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('existsCurrent')->once()->andThrow(new RuntimeException('storage unavailable'));
        try {
            $this->service($files)->detectEstimateType($session->id, $this->context->access());
            self::fail('Expected failure');
        } catch (RuntimeException $e) {
            self::assertSame('storage unavailable', $e->getMessage());
        }
        $session->refresh();
        self::assertSame('failed', $session->status);
        self::assertSame('estimate.import_detect_type_error', $session->error_message);
        self::assertSame('storage', $session->stats['detection_phase']);
    }

    public function test_parser_failure_is_saved_and_retry_clears_error(): void
    {
        $session = $this->createImportSession();
        $files = $this->readableFiles();
        $handler = $this->createMock(RuntimeImportFormatHandlerInterface::class);
        $handler->method('slug')->willReturn('test');
        $handler->method('supportedExtensions')->willReturn(['xlsx']);
        $attempts = 0;
        $handler->method('detect')->willReturnCallback(function () use (&$attempts): ImportDetectionResult {
            if (++$attempts === 1) {
                throw new RuntimeException('parser failure');
            }

            return new ImportDetectionResult('test', 'test', 'Test', 1.0);
        });
        $service = $this->service($files, [$handler]);
        try {
            $service->detectEstimateType($session->id, $this->context->access());
            self::fail('Expected failure');
        } catch (RuntimeException $e) {
            self::assertSame('parser failure', $e->getMessage());
        }
        self::assertSame('failed', $session->refresh()->status);
        self::assertSame('test', $session->stats['detection_phase']);
        self::assertSame('test', $service->detectEstimateType($session->id, $this->context->access())->detectedType);
        $session->refresh();
        self::assertNull($session->error_message);
        self::assertSame('completed', $session->stats['detection_phase']);
        self::assertSame('test', $session->options['format_handler']);
    }

    public function test_interrupted_detection_is_recovered_on_status_read(): void
    {
        $session = $this->createImportSession();
        $session->update(['status' => 'detecting', 'stats' => [
            'detection_phase' => 'recognizing', 'detection_deadline_at' => time() - 1,
        ]]);
        $status = $this->service(Mockery::mock(FileService::class))->getImportStatus($session->id, $this->context->access());
        self::assertSame('failed', $status['status']);
        self::assertStringContainsString('прервана', $status['error']);
        self::assertSame('estimate.import_detection_interrupted', $session->refresh()->error_message);
    }

    public function test_unsupported_format_has_a_safe_stored_message(): void
    {
        $session = $this->createImportSession();
        try {
            $this->service($this->readableFiles())->detectEstimateType($session->id, $this->context->access());
            self::fail('Expected unsupported format');
        } catch (\App\BusinessModules\Features\BudgetEstimates\Services\Import\Exceptions\UnsupportedEstimateImportFormatException $e) {
            self::assertSame('Формат файла не поддерживается.', $e->getMessage());
        }
        self::assertSame('failed', $session->refresh()->status);
        self::assertSame('estimate.import_unsupported_format', $session->error_message);
    }

    public function test_successful_and_active_detection_are_not_marked_interrupted(): void
    {
        $session = $this->createImportSession();
        $service = $this->service(Mockery::mock(FileService::class));
        foreach ([['completed', time() - 100], ['recognizing', time() + 100]] as [$phase, $deadline]) {
            $session->update(['status' => 'detecting', 'stats' => [
                'detection_phase' => $phase, 'detection_deadline_at' => $deadline,
            ]]);
            $service->getImportStatus($session->id, $this->context->access());
            self::assertSame('detecting', $session->refresh()->status);
            self::assertNull($session->error_message);
        }
    }

    private function createImportSession(): ImportSession
    {
        return $this->context->session(['file_path' => 'org-'.$this->context->organization->id.'/estimate-imports/test.xlsx', 'stats' => ['progress' => 0]]);
    }

    private function readableFiles(): FileService
    {
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('existsCurrent')->andReturn(true);
        $files->shouldReceive('readCurrent')->andReturnUsing(function () {
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, 'test');
            rewind($stream);

            return $stream;
        });

        return $files;
    }

    private function service(FileService $files, array $handlers = []): EstimateImportService
    {
        $registry = new ImportFormatRegistry($handlers);

        return new EstimateImportService(new FileStorageService($files), new TemplateService, new SignatureGenerator, new ImportFormatDetector($registry), $registry);
    }
}
