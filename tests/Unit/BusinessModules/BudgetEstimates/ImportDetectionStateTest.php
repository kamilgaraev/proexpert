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
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Tests\Support\IsolatedPostgresTestDatabase;

final class ImportDetectionStateTest extends TestCase
{
    private Manager $database;

    protected function setUp(): void
    {
        $container = new Container;
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        $container->instance('config', new Repository(['octane' => ['max_request_timeout' => 30]]));
        $container->instance('log', new NullLogger);
        $container->instance('cache', new CacheRepository(new ArrayStore));
        $container->instance('app', new class
        {
            public function getLocale(): string
            {
                return 'ru';
            }
        });
        $container->instance('translator', new Translator(new FileLoader(new Filesystem, dirname(__DIR__, 4).'/lang'), 'ru'));
        $this->database = new Manager($container);
        $this->database->addConnection(IsolatedPostgresTestDatabase::configuration());
        $this->database->setAsGlobal();
        $this->database->bootEloquent();
        $this->database->getConnection()->getSchemaBuilder()->create('import_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('status');
            $table->string('file_path');
            $table->jsonb('stats')->nullable();
            $table->jsonb('options')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        $this->database->getConnection()->disconnect();
        ImportSession::unsetConnectionResolver();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        Mockery::close();
    }

    public function test_storage_failure_is_saved_and_rethrown(): void
    {
        $session = $this->session();
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('existsCurrent')->once()->andThrow(new RuntimeException('storage unavailable'));
        try {
            $this->service($files)->detectEstimateType($session->id);
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
        $session = $this->session();
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
            $service->detectEstimateType($session->id);
            self::fail('Expected failure');
        } catch (RuntimeException $e) {
            self::assertSame('parser failure', $e->getMessage());
        }
        self::assertSame('failed', $session->refresh()->status);
        self::assertSame('test', $session->stats['detection_phase']);
        self::assertSame('test', $service->detectEstimateType($session->id)->detectedType);
        $session->refresh();
        self::assertNull($session->error_message);
        self::assertSame('completed', $session->stats['detection_phase']);
        self::assertSame('test', $session->options['format_handler']);
    }

    public function test_interrupted_detection_is_recovered_on_status_read(): void
    {
        $session = $this->session();
        $session->update(['status' => 'detecting', 'stats' => [
            'detection_phase' => 'recognizing', 'detection_deadline_at' => time() - 1,
        ]]);
        $status = $this->service(Mockery::mock(FileService::class))->getImportStatus($session->id);
        self::assertSame('failed', $status['status']);
        self::assertStringContainsString('прервана', $status['error']);
        self::assertSame('estimate.import_detection_interrupted', $session->refresh()->error_message);
    }

    public function test_unsupported_format_has_a_safe_stored_message(): void
    {
        $session = $this->session();
        try {
            $this->service($this->readableFiles())->detectEstimateType($session->id);
            self::fail('Expected unsupported format');
        } catch (\App\BusinessModules\Features\BudgetEstimates\Services\Import\Exceptions\UnsupportedEstimateImportFormatException $e) {
            self::assertSame('Формат файла не поддерживается.', $e->getMessage());
        }
        self::assertSame('failed', $session->refresh()->status);
        self::assertSame('estimate.import_unsupported_format', $session->error_message);
    }

    public function test_successful_and_active_detection_are_not_marked_interrupted(): void
    {
        $session = $this->session();
        $service = $this->service(Mockery::mock(FileService::class));
        foreach ([['completed', time() - 100], ['recognizing', time() + 100]] as [$phase, $deadline]) {
            $session->update(['status' => 'detecting', 'stats' => [
                'detection_phase' => $phase, 'detection_deadline_at' => $deadline,
            ]]);
            $service->getImportStatus($session->id);
            self::assertSame('detecting', $session->refresh()->status);
            self::assertNull($session->error_message);
        }
    }

    private function session(): ImportSession
    {
        return ImportSession::create(['status' => 'uploading', 'file_path' => 'org-1/estimate-imports/test.xlsx', 'stats' => ['progress' => 0], 'options' => []]);
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
