<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Evidence;

use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneBGateArtifactRecorder;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportExportLimits;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportPdfDocument;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportPdfDocumentBuilder;
use App\BusinessModules\Core\Reporting\Application\Exports\ReportPdfRenderBudget;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\CsvReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\DompdfReportPdfDocumentRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\PdfReportExportRenderer;
use App\BusinessModules\Core\Reporting\Infrastructure\Exports\XlsxReportExportRenderer;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DOMDocument;
use FilesystemIterator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Performance\Reporting\InstrumentedReportChunkSource;
use Tests\Unit\Reporting\Exports\HashingReportArtifactStream;
use Tests\Unit\Reporting\Exports\ReportExportRendererTestCase;

final class PlanOneBGateArtifactRecorderTest extends TestCase
{
    private const REVISION = '1234567890abcdef1234567890abcdef12345678';

    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($directory);
        }
    }

    public function test_records_real_junit_xml_and_maps_each_required_check_to_executed_suites(): void
    {
        $root = $this->temporaryRepository('run_export_observability');
        $recorder = new PlanOneBGateArtifactRecorder($root);
        self::assertTrue(is_callable([$recorder, 'recordPhpUnit']));
        $definition = PlanOneBGateArtifactRecorder::definition('run_export_observability');
        $resultPath = $root.'/'.$definition['producer']['result_artifact_path'];
        $this->writeJunit($resultPath, $root, $definition['producer']['test_paths']);

        $envelope = $recorder->recordPhpUnit(
            'run_export_observability',
            $this->processResult($definition['command']),
            $resultPath,
            null,
            self::REVISION,
        );

        self::assertSame(
            ['tests/Unit/Reporting/Telemetry/ReportExecutionTelemetryTest.php'],
            $envelope['producer']['test_paths'],
        );
        self::assertSame(hash_file('sha256', $resultPath), $envelope['process']['result_artifact_sha256']);
        self::assertSame(4, $envelope['gate']['result']['tests']);
        self::assertSame(43, $envelope['gate']['result']['assertions']);
        self::assertSame(
            ['bounded_run_families', 'bounded_export_families', 'non_run_export_family_absent'],
            array_column($envelope['records'], 'id'),
        );
        foreach ($envelope['records'] as $record) {
            self::assertSame(
                ['tests/Unit/Reporting/Telemetry/ReportExecutionTelemetryTest.php'],
                $record['suites'],
            );
            self::assertSame(4, $record['tests']);
            self::assertSame(43, $record['assertions']);
        }
    }

    public function test_rejects_malformed_junit_nonzero_exit_and_missing_required_suite(): void
    {
        foreach (['malformed', 'nonzero', 'missing_suite'] as $case) {
            $root = $this->temporaryRepository('execution_attempt_leases');
            $recorder = new PlanOneBGateArtifactRecorder($root);
            $definition = PlanOneBGateArtifactRecorder::definition('execution_attempt_leases');
            $resultPath = $root.'/'.$definition['producer']['result_artifact_path'];
            $paths = $definition['producer']['test_paths'];
            $process = $this->processResult($definition['command']);

            if ($case === 'malformed') {
                $this->writeFile($resultPath, '<testsuites>');
            } else {
                if ($case === 'missing_suite') {
                    array_pop($paths);
                }
                $this->writeJunit($resultPath, $root, $paths);
                if ($case === 'nonzero') {
                    $process['exit_code'] = 1;
                }
            }

            $this->assertInvalid(
                fn () => $recorder->recordPhpUnit(
                    'execution_attempt_leases',
                    $process,
                    $resultPath,
                    null,
                    self::REVISION,
                ),
            );
        }
    }

    public function test_records_typed_static_results_for_every_changed_production_file(): void
    {
        $root = $this->temporaryRepository('static_analysis');
        $recorder = new PlanOneBGateArtifactRecorder($root);
        $definition = PlanOneBGateArtifactRecorder::definition('static_analysis');
        $resultPath = $root.'/'.$definition['producer']['result_artifact_path'];
        $this->writeStaticResult($resultPath, $definition);

        $envelope = $recorder->recordStaticAnalysis($resultPath, self::REVISION);

        self::assertSame($definition['producer']['test_paths'], $envelope['producer']['test_paths']);
        self::assertSame(['changed_php_syntax', 'changed_php_phpstan'], array_column($envelope['records'], 'id'));
        foreach ($envelope['records'] as $record) {
            self::assertSame($definition['producer']['test_paths'], $record['suites']);
            self::assertSame(count($definition['producer']['test_paths']), $record['tests']);
            self::assertSame(count($definition['producer']['test_paths']), $record['assertions']);
        }
    }

    public function test_rejects_malformed_or_failed_typed_static_results(): void
    {
        foreach (['malformed', 'syntax_failed', 'phpstan_failed'] as $case) {
            $root = $this->temporaryRepository('static_analysis');
            $recorder = new PlanOneBGateArtifactRecorder($root);
            $definition = PlanOneBGateArtifactRecorder::definition('static_analysis');
            $resultPath = $root.'/'.$definition['producer']['result_artifact_path'];
            $this->writeStaticResult($resultPath, $definition);

            if ($case === 'malformed') {
                $this->writeFile($resultPath, '{"schema_version":"1.0.0"}');
            } else {
                $document = json_decode((string) file_get_contents($resultPath), true, 512, JSON_THROW_ON_ERROR);
                if ($case === 'syntax_failed') {
                    $document['syntax'][0]['exit_code'] = 1;
                } else {
                    $document['phpstan']['exit_code'] = 1;
                }
                $this->writeFile($resultPath, json_encode($document, JSON_THROW_ON_ERROR));
            }

            $this->assertInvalid(fn () => $recorder->recordStaticAnalysis($resultPath, self::REVISION));
        }
    }

    public function test_gate_mapping_uses_real_observability_and_complete_execution_lease_suites(): void
    {
        $observability = PlanOneBGateArtifactRecorder::definition('run_export_observability');
        self::assertSame(
            ['tests/Unit/Reporting/Telemetry/ReportExecutionTelemetryTest.php'],
            $observability['producer']['test_paths'],
        );

        $leases = PlanOneBGateArtifactRecorder::definition('execution_attempt_leases');
        self::assertSame([
            'tests/Architecture/Reporting/ReportQueueRuntimeContractTest.php',
            'tests/Architecture/Reporting/ReportingExecutionBindingsTest.php',
            'tests/Unit/Reporting/Execution/ReportRunExecutionWatchdogTest.php',
            'tests/Unit/Reporting/Execution/ReportRunLeaseRecoveryStoreContractTest.php',
            'tests/Unit/Reporting/Execution/ReportRunAttemptFinalizerTest.php',
            'tests/Unit/Reporting/Jobs/MaterializeReportRunJobTest.php',
            'tests/Feature/Reporting/Persistence/EloquentReportRunLeaseRecoveryStoreTest.php',
            'tests/Feature/Reporting/Persistence/EloquentReportRunAttemptLifecycleStoreTest.php',
            'tests/Unit/Reporting/Execution/ReportExportLeaseRecoveryStoreContractTest.php',
            'tests/Unit/Reporting/Exports/ReportExportAttemptFinalizerTest.php',
            'tests/Unit/Reporting/Exports/FinalizeFailedReportExportAttemptTest.php',
            'tests/Unit/Reporting/Jobs/GenerateReportExportJobTest.php',
            'tests/Feature/Reporting/Persistence/EloquentReportExportLeaseRecoveryStoreTest.php',
            'tests/Feature/Reporting/Persistence/EloquentReportExportAttemptLifecycleStoreTest.php',
            'tests/Integration/Reporting/Jobs/GenerateReportExportJobIntegrationTest.php',
        ], $leases['producer']['test_paths']);

        $pdf = PlanOneBGateArtifactRecorder::definition('pdf_renderer_budget');
        self::assertSame(
            ['tests/Unit/Reporting/Evidence/PlanOneBGateArtifactRecorderTest.php'],
            $pdf['check_suites']['locked_dependency_versions'],
        );
        $parity = PlanOneBGateArtifactRecorder::definition('rows_cursor_drill_parity');
        self::assertSame(
            ['tests/Contract/Reporting/ReportExportParityContractTest.php'],
            $parity['check_suites']['summary_semantic_parity'],
        );

        $repositoryRoot = dirname(__DIR__, 4);
        foreach (PlanOneBGateArtifactRecorder::definitions() as $gateId => $definition) {
            if ($gateId === 'static_analysis') {
                self::assertStringContainsString('--error-format=json', $definition['static_phpstan_command']);
            } else {
                self::assertStringContainsString(
                    $definition['producer']['result_artifact_path'],
                    $definition['command'],
                );
            }
            foreach ($definition['producer']['test_paths'] as $testPath) {
                self::assertFileExists($repositoryRoot.'/'.$testPath);
                self::assertStringContainsString($testPath, $definition['command']);
            }
            foreach ($definition['required_checks'] as $check) {
                self::assertNotSame([], $definition['check_suites'][$check]);
                foreach ($definition['check_suites'][$check] as $suite) {
                    self::assertContains($suite, $definition['producer']['test_paths']);
                }
            }
        }
    }

    public function test_performance_measurements_require_a_typed_current_process_artifact(): void
    {
        $root = $this->temporaryRepository('pdf_renderer_budget');
        $recorder = new PlanOneBGateArtifactRecorder($root);
        $definition = PlanOneBGateArtifactRecorder::definition('pdf_renderer_budget');
        $resultPath = $root.'/'.$definition['producer']['result_artifact_path'];
        $this->writeJunit($resultPath, $root, $definition['producer']['test_paths']);
        $measurements = [
            ['id' => 'pdf_detail_rows', 'value' => 5000, 'unit' => 'rows', 'limit' => 5000, 'status' => 'passed'],
            ['id' => 'pdf_pages', 'value' => 20, 'unit' => 'pages', 'limit' => 20, 'status' => 'passed'],
            ['id' => 'pdf_html_bytes', 'value' => 1000, 'unit' => 'bytes', 'limit' => 2_000_000, 'status' => 'passed'],
            ['id' => 'pdf_output_bytes', 'value' => 500, 'unit' => 'bytes', 'limit' => 2_000_000, 'status' => 'passed'],
            ['id' => 'pdf_memory_delta_bytes', 'value' => 4096, 'unit' => 'bytes', 'limit' => 128 * 1024 * 1024, 'status' => 'passed'],
        ];
        $measurementPath = $this->writeMeasurementResult(
            $root,
            $definition,
            $measurements,
            self::REVISION,
        );

        $envelope = $recorder->recordPhpUnit(
            'pdf_renderer_budget',
            $this->processResult($definition['command']),
            $resultPath,
            $measurementPath,
            self::REVISION,
        );

        self::assertEquals($measurements, $envelope['gate']['measurements']);
        self::assertSame(
            hash_file('sha256', $measurementPath),
            $envelope['process']['measurement_artifact_sha256'],
        );
        unlink($measurementPath);
        $this->assertInvalid(fn () => $recorder->recordPhpUnit(
            'pdf_renderer_budget',
            $this->processResult($definition['command']),
            $resultPath,
            null,
            self::REVISION,
        ));
    }

    public function test_locked_pdf_dependencies_match_the_canonical_versions(): void
    {
        $root = dirname(__DIR__, 4);
        $lock = json_decode(
            (string) file_get_contents($root.'/composer.lock'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $versions = [];
        foreach ($lock['packages'] as $package) {
            if (in_array($package['name'], ['barryvdh/laravel-dompdf', 'dompdf/dompdf'], true)) {
                $versions[$package['name']] = $package['version'];
            }
        }

        self::assertSame([
            'barryvdh/laravel-dompdf' => 'v3.1.1',
            'dompdf/dompdf' => 'v3.1.4',
        ], $versions);
    }

    public function test_writes_requested_performance_measurements(): void
    {
        $gateId = getenv('PLAN_ONE_B_GATE_ID');
        if ($gateId === false) {
            self::assertTrue(true);

            return;
        }
        require_once dirname(__DIR__, 3).'/Performance/Reporting/ReportExportStreamingBudgetTest.php';
        self::assertContains($gateId, ['pdf_renderer_budget', 'streaming_budget']);
        $revision = getenv('PLAN_ONE_B_GATE_REVISION');
        $rawPath = getenv('PLAN_ONE_B_GATE_MEASUREMENT_RAW_PATH');
        $nonce = getenv('PLAN_ONE_B_GATE_MEASUREMENT_NONCE');
        self::assertIsString($revision);
        self::assertMatchesRegularExpression('/^[a-f0-9]{40}$/D', $revision);
        self::assertIsString($rawPath);
        self::assertIsString($nonce);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $nonce);
        $root = dirname(__DIR__, 4);
        $expectedPath = $root.'/build/reports/gates/results/'.$gateId.'.measurements.json.raw';
        self::assertSame(
            strtolower(str_replace('\\', '/', $expectedPath)),
            strtolower(str_replace('\\', '/', $rawPath)),
        );
        $probe = new class('performance_measurement') extends ReportExportRendererTestCase
        {
            public function measurements(string $gateId): array
            {
                return $gateId === 'pdf_renderer_budget'
                    ? $this->pdfMeasurements()
                    : $this->streamingMeasurements();
            }

            private function pdfMeasurements(): array
            {
                [$source, $definition] = $this->source(5000);
                $budget = new ReportPdfRenderBudget(5000, 20, 2_000_000, 2_000_000, 128 * 1024 * 1024);
                $htmlBytes = 0;
                $renderer = (new PdfReportExportRenderer(
                    new ReportPdfDocumentBuilder(ReportExportLimits::pdf()),
                    new DompdfReportPdfDocumentRenderer(
                        htmlRenderer: function (ReportPdfDocument $document) use (&$htmlBytes): string {
                            $html = $this->renderBlade($document);
                            $htmlBytes = strlen($html);

                            return $html;
                        },
                        documentLoader: static fn (): object => new \stdClass,
                        pageCounter: static fn (): int => 20,
                        outputRenderer: static fn (): string => '%PDF bounded',
                    ),
                    [PdfReportExportRenderer::budgetKey(
                        $definition->definitionHash->value,
                        $definition->definition->rendererVersion,
                    ) => $budget],
                ))->forDefinition($definition);
                $provider = new InstrumentedReportChunkSource($source, 5000, 500);
                $stream = new HashingReportArtifactStream;
                if (function_exists('memory_reset_peak_usage')) {
                    memory_reset_peak_usage();
                }
                $memoryAtStart = memory_get_usage(true);
                $rows = $renderer->render($source, $this->data('pdf'), $provider->chunks(), $stream);
                $memory = max(0, memory_get_peak_usage(true) - $memoryAtStart);

                return [
                    ['id' => 'pdf_detail_rows', 'value' => $rows, 'unit' => 'rows', 'limit' => 5000, 'status' => 'passed'],
                    ['id' => 'pdf_pages', 'value' => 20, 'unit' => 'pages', 'limit' => 20, 'status' => 'passed'],
                    ['id' => 'pdf_html_bytes', 'value' => $htmlBytes, 'unit' => 'bytes', 'limit' => 2_000_000, 'status' => 'passed'],
                    ['id' => 'pdf_output_bytes', 'value' => $stream->size(), 'unit' => 'bytes', 'limit' => 2_000_000, 'status' => 'passed'],
                    ['id' => 'pdf_memory_delta_bytes', 'value' => $memory, 'unit' => 'bytes', 'limit' => 128 * 1024 * 1024, 'status' => 'passed'],
                ];
            }

            private function streamingMeasurements(): array
            {
                [$source, $definition] = $this->source(50_000);
                $peakChunkRows = 0;
                $peakMemory = 0;
                $artifactBytes = 0;
                foreach ([
                    'csv' => (new CsvReportExportRenderer)->forDefinition($definition),
                    'xlsx' => (new XlsxReportExportRenderer)->forDefinition($definition),
                ] as $format => $renderer) {
                    $provider = new InstrumentedReportChunkSource($source, 50_000, 500);
                    $stream = new HashingReportArtifactStream;
                    if (function_exists('memory_reset_peak_usage')) {
                        memory_reset_peak_usage();
                    }
                    $memoryAtStart = memory_get_usage(true);
                    $renderer->render($source, $this->data($format), $provider->chunks(), $stream);
                    $peakChunkRows = max($peakChunkRows, $provider->peakRetainedRows());
                    $peakMemory = max($peakMemory, memory_get_peak_usage(true) - $memoryAtStart);
                    $artifactBytes = max($artifactBytes, $stream->size());
                }

                return [
                    ['id' => 'stream_chunk_rows', 'value' => $peakChunkRows, 'unit' => 'rows', 'limit' => 5000, 'status' => 'passed'],
                    ['id' => 'stream_peak_memory_bytes', 'value' => $peakMemory, 'unit' => 'bytes', 'limit' => 128 * 1024 * 1024, 'status' => 'passed'],
                    ['id' => 'stream_artifact_bytes', 'value' => $artifactBytes, 'unit' => 'bytes', 'limit' => 500 * 1024 * 1024, 'status' => 'passed'],
                ];
            }
        };
        $bytes = CanonicalJson::encode([
            'gate_id' => $gateId,
            'repository_revision' => $revision,
            'nonce' => $nonce,
            'measurements' => $probe->measurements($gateId),
        ])."\n";
        self::assertSame(strlen($bytes), file_put_contents($rawPath, $bytes, LOCK_EX));
    }

    private function temporaryRepository(string $gateId): string
    {
        $directory = sys_get_temp_dir().'/most-plan1b-recorder-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        $this->temporaryDirectories[] = $directory;
        $definition = PlanOneBGateArtifactRecorder::definition($gateId);
        foreach ($definition['producer']['test_paths'] as $path) {
            $this->writeFile($directory.'/'.$path, "<?php\n");
        }

        return $directory;
    }

    private function writeJunit(string $path, string $root, array $suitePaths): void
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $suites = $document->createElement('testsuites');
        $document->appendChild($suites);
        foreach ($suitePaths as $index => $suitePath) {
            $suite = $document->createElement('testsuite');
            $suite->setAttribute('name', 'Suite'.($index + 1));
            $suite->setAttribute('file', str_replace('/', DIRECTORY_SEPARATOR, $root.'/'.$suitePath));
            $suite->setAttribute('tests', '4');
            $suite->setAttribute('assertions', '43');
            $suite->setAttribute('errors', '0');
            $suite->setAttribute('failures', '0');
            $suite->setAttribute('skipped', '0');
            $suite->setAttribute('time', '0.010000');
            for ($case = 1; $case <= 4; $case++) {
                $testCase = $document->createElement('testcase');
                $testCase->setAttribute('name', 'test_case_'.$case);
                $testCase->setAttribute('file', str_replace('/', DIRECTORY_SEPARATOR, $root.'/'.$suitePath));
                $testCase->setAttribute('assertions', $case === 1 ? '40' : '1');
                $testCase->setAttribute('time', '0.001000');
                $suite->appendChild($testCase);
            }
            $suites->appendChild($suite);
        }
        $this->writeFile($path, (string) $document->saveXML());
    }

    private function writeStaticResult(string $path, array $definition): void
    {
        $process = static fn (string $command): array => [
            'command' => $command,
            'exit_code' => 0,
            'started_at' => '2026-07-30T11:59:59Z',
            'finished_at' => '2026-07-30T12:00:00Z',
            'duration_ms' => 10,
            'stdout' => '',
            'stderr' => '',
        ];
        $syntax = [];
        foreach ($definition['producer']['test_paths'] as $file) {
            $result = $process('php -l '.$file);
            $result['path'] = $file;
            $result['stdout'] = 'No syntax errors detected in '.$file;
            $syntax[] = $result;
        }
        $phpstan = $process($definition['static_phpstan_command']);
        $phpstan['stdout'] = '{"totals":{"errors":0,"file_errors":0},"files":{},"errors":[]}';
        $this->writeFile($path, json_encode([
            'schema_version' => '1.0.0',
            'command' => $definition['command'],
            'started_at' => '2026-07-30T11:59:59Z',
            'finished_at' => '2026-07-30T12:00:00Z',
            'duration_ms' => 50,
            'syntax' => $syntax,
            'phpstan' => $phpstan,
        ], JSON_THROW_ON_ERROR));
    }

    private function writeMeasurementResult(
        string $root,
        array $definition,
        array $measurements,
        string $revision,
    ): string {
        $relativePath = $definition['producer']['measurement_artifact_path'];
        self::assertIsString($relativePath);
        self::assertIsString($definition['producer']['measurement_command']);
        $path = $root.'/'.$relativePath;
        $nonce = str_repeat('a', 64);
        $rawBytes = CanonicalJson::encode([
            'gate_id' => $definition['gate_id'],
            'repository_revision' => $revision,
            'nonce' => $nonce,
            'measurements' => $measurements,
        ])."\n";
        $this->writeFile($path, CanonicalJson::encode([
            'schema_version' => '1.0.0',
            'gate_id' => $definition['gate_id'],
            'repository_revision' => $revision,
            'nonce' => $nonce,
            'raw_measurements_sha256' => hash('sha256', $rawBytes),
            'process' => [
                'command' => $definition['producer']['measurement_command'],
                'exit_code' => 0,
                'started_at' => '2026-07-30T11:59:59Z',
                'finished_at' => '2026-07-30T12:00:00Z',
                'duration_ms' => 10,
                'stdout' => "PHPUnit 11.5.0\nOK\n",
                'stderr' => '',
            ],
            'measurements' => $measurements,
        ])."\n");

        return $path;
    }

    private function processResult(string $command): array
    {
        return [
            'command' => $command,
            'exit_code' => 0,
            'started_at' => '2026-07-30T11:59:59Z',
            'finished_at' => '2026-07-30T12:00:00Z',
            'duration_ms' => 25,
            'stdout' => "PHPUnit 11.5.0\nOK (4 tests, 43 assertions)\n",
            'stderr' => '',
        ];
    }

    private function writeFile(string $path, string $bytes): void
    {
        $directory = dirname($path);
        if (! is_dir($directory)) {
            self::assertTrue(mkdir($directory, 0777, true));
        }
        self::assertSame(strlen($bytes), file_put_contents($path, $bytes));
    }

    private function assertInvalid(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected invalid gate artifact.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('plan_one_b_gate_process_result_invalid', $exception->getMessage());
        }
    }
}
