<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\EstimateImportService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\FileStorageService;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportDetectionResult;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportPreviewResult;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportStructureResult;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Exceptions\UnsupportedEstimateImportFormatException;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportFormatDetector;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportFormatRegistry;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\RuntimeImportFormatHandlerInterface;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\SignatureGenerator;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\TemplateService;
use App\Models\ImportSession;
use App\Http\Controllers\Api\V1\Admin\EstimateImportController;
use App\Http\Requests\Admin\Estimate\DetectEstimateImportRequest;
use App\Http\Requests\Admin\Estimate\MapEstimateImportRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Bus;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Exceptions\ImportSessionStateException;
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

    public function test_detection_claim_and_deadline_are_persisted_before_storage_io(): void
    {
        $session = $this->createImportSession();
        $transactionLevel = DB::transactionLevel();
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('existsCurrent')->once()->andReturnUsing(function () use ($session, $transactionLevel): bool {
            self::assertSame($transactionLevel, DB::transactionLevel());
            $durable = $session->fresh();
            self::assertSame('detecting', $durable->status);
            self::assertSame('storage', $durable->stats['detection_phase']);
            self::assertTrue(\Illuminate\Support\Str::isUuid($durable->stats['detection_token']));
            self::assertGreaterThan(now()->timestamp, $durable->stats['detection_deadline_at']);
            throw new RuntimeException('storage unavailable');
        });
        try {
            $this->service($files)->detectEstimateType($session->id, $this->context->access());
            self::fail('Expected storage failure after published claim');
        } catch (RuntimeException $exception) {
            self::assertSame('storage unavailable', $exception->getMessage());
        }
        self::assertSame('failed', $session->refresh()->status);
    }

    public function test_active_detection_locks_settings_and_execution_during_storage_io(): void
    {
        $session = $this->createImportSession();
        $session->update(['options' => array_merge($session->options, ['format_handler' => 'test'])]);
        Bus::fake();
        $files = Mockery::mock(FileService::class);
        $service = $this->service($files);
        $files->shouldReceive('existsCurrent')->once()->andReturnUsing(function () use ($session, $service): bool {
            $options = $session->fresh()->options;
            $blocked = 0;
            foreach (['detectFormat', 'preview', 'execute'] as $operation) {
                try {
                    if ($operation === 'execute') {
                        $service->execute($session->id, [], ['name' => 'Concurrent request'], false, $this->context->access());
                    } else {
                        $service->$operation($session->id, null, $this->context->access());
                    }
                } catch (ImportSessionStateException $exception) {
                    self::assertSame('import_session_locked', $exception->reasonCode);
                    $blocked++;
                }
            }
            self::assertSame(3, $blocked);
            self::assertSame($options, $session->fresh()->options);
            Bus::assertNothingDispatched();
            throw new RuntimeException('storage unavailable');
        });
        try {
            $service->detectEstimateType($session->id, $this->context->access());
            self::fail('Expected storage failure after concurrent requests');
        } catch (RuntimeException $exception) {
            self::assertSame('storage unavailable', $exception->getMessage());
        }
    }

    public function test_recovered_detection_retry_rejects_stale_completion_and_preserves_current_result(): void
    {
        $session = $this->createImportSession();
        $handler = $this->createMock(RuntimeImportFormatHandlerInterface::class);
        $handler->method('slug')->willReturn('test');
        $handler->method('supportedExtensions')->willReturn(['xlsx']);
        $service = $this->service($this->readableFiles(), [$handler]);
        $attempts = 0;
        $handler->method('detect')->willReturnCallback(function () use (&$attempts, $session, $service): ImportDetectionResult {
            if (++$attempts === 1) {
                $current = $session->fresh();
                $current->update(['stats' => array_merge($current->stats, ['detection_deadline_at' => now()->subMinute()->timestamp])]);
                self::assertSame('failed', $service->getImportStatus($session->id, $this->context->access())['status']);
                self::assertSame('current', $service->detectEstimateType($session->id, $this->context->access())->detectedType);

                return new ImportDetectionResult('stale', 'test', 'Test', 1.0);
            }

            return new ImportDetectionResult('current', 'test', 'Test', 1.0);
        });
        try {
            $service->detectEstimateType($session->id, $this->context->access());
            self::fail('Stale detection cannot complete over a newer claim');
        } catch (ImportSessionStateException $exception) {
            self::assertSame('import_detection_interrupted', $exception->reasonCode);
        }
        self::assertSame(2, $attempts);
        self::assertSame('detecting', $session->refresh()->status);
        self::assertSame('completed', $session->stats['detection_phase']);
        self::assertSame('current', $session->options['runtime_detection']['detected_type']);
        self::assertNull($session->error_message);
    }

    public function test_implicit_detection_storage_failure_persists_for_detect_and_map_preview(): void
    {
        foreach (['detectFormat', 'preview'] as $operation) {
            $session = $this->createImportSession();
            $files = Mockery::mock(FileService::class);
            $files->shouldReceive('existsCurrent')->once()->andThrow(new RuntimeException('storage unavailable'));
            try {
                $this->service($files)->$operation($session->id, null, $this->context->access());
                self::fail('Expected implicit detection failure');
            } catch (RuntimeException $exception) {
                self::assertSame('storage unavailable', $exception->getMessage());
            }
            self::assertSame('failed', $session->refresh()->status);
            self::assertSame('estimate.import_detect_type_error', $session->error_message);
            self::assertSame('storage', $session->stats['detection_phase']);
        }
    }

    public function test_detect_and_map_controllers_preserve_implicit_detection_failure(): void
    {
        foreach (['detect', 'map'] as $operation) {
            $session = $this->createImportSession();
            $files = Mockery::mock(FileService::class);
            $files->shouldReceive('existsCurrent')->once()->andThrow(new RuntimeException('storage unavailable'));
            $this->app->instance(EstimateImportService::class, $this->service($files));
            $input = ['file_id' => $session->id, 'column_mapping' => []];
            $uri = '/projects/'.$this->context->project->id.'/estimates/import/'.$operation;
            $request = $operation === 'detect'
                ? DetectEstimateImportRequest::create($uri, 'POST', $input)
                : MapEstimateImportRequest::create($uri, 'POST', $input);
            $route = new Route(['POST'], 'projects/{project}/estimates/import/'.$operation, []);
            $route->bind($request);
            $request->setContainer($this->app);
            $request->setRedirector(app('redirect'));
            $request->setUserResolver(fn () => $this->context->user);
            $request->setRouteResolver(static fn () => $route);
            $request->validateResolved();
            $controller = app(EstimateImportController::class);
            $response = $operation === 'detect' ? $controller->detect($request) : $controller->map($request);
            self::assertSame(422, $response->getStatusCode());
            self::assertStringNotContainsString('storage unavailable', $response->getContent());
            self::assertSame('failed', $session->refresh()->status);
            self::assertSame('estimate.import_detect_type_error', $session->error_message);
            self::assertSame('storage', $session->stats['detection_phase']);
        }
    }

    public function test_implicit_unsupported_detection_persists_for_detect_and_map_preview(): void
    {
        foreach (['detectFormat', 'preview'] as $operation) {
            $session = $this->createImportSession();
            try {
                $this->service($this->readableFiles())->$operation($session->id, null, $this->context->access());
                self::fail('Expected unsupported implicit detection');
            } catch (UnsupportedEstimateImportFormatException) {
                self::assertSame('failed', $session->refresh()->status);
                self::assertSame('estimate.import_unsupported_format', $session->error_message);
                self::assertSame('recognizing', $session->stats['detection_phase']);
            }
        }
    }

    public function test_failed_implicit_preview_detection_can_recover_with_detect_and_manual_map(): void
    {
        $session = $this->createImportSession();
        $handler = $this->createMock(RuntimeImportFormatHandlerInterface::class);
        $handler->method('slug')->willReturn('test');
        $handler->method('supportedExtensions')->willReturn(['xlsx']);
        $attempts = 0;
        $handler->method('detect')->willReturnCallback(function () use (&$attempts): ImportDetectionResult {
            if (++$attempts === 1) {
                throw new RuntimeException('temporary parser failure');
            }
            return new ImportDetectionResult('test', 'test', 'Test', 1.0);
        });
        $mapping = ['item_name' => 'A', 'quantity' => 'B', 'unit_price' => 'C'];
        $handler->method('detectStructure')->willReturn(new ImportStructureResult('test', 2, $mapping));
        $handler->method('preview')->willReturn(new ImportPreviewResult('test', totals: ['total_amount' => 12]));
        $service = $this->service($this->readableFiles(), [$handler]);
        try {
            $service->preview($session->id, null, $this->context->access());
            self::fail('Expected first parser attempt failure');
        } catch (RuntimeException $exception) {
            self::assertSame('temporary parser failure', $exception->getMessage());
        }
        self::assertSame('failed', $session->refresh()->status);
        $structure = $service->detectFormat($session->id, 2, $this->context->access());
        self::assertSame(2, $structure['header_row']);
        $preview = $service->preview($session->id, $mapping, $this->context->access(), ['manual_mapping' => true]);
        self::assertSame(12.0, $preview->getTotalAmount());
        self::assertSame('mapped', $session->refresh()->status);
        self::assertNull($session->error_message);
        self::assertSame('completed', $session->stats['detection_phase']);
        self::assertTrue($session->options['manual_structure_confirmed']);
        self::assertEquals($mapping, $session->options['structure']['column_mapping']);
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
