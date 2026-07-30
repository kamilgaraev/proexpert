<?php

declare(strict_types=1);

namespace Tests\Contract\Reporting;

use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneACompletionRef;
use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneBEvidenceBuilder;
use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneBEvidenceValidator;
use App\BusinessModules\Core\Reporting\Application\Evidence\PlanOneBGateArtifactRecorder;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use DOMDocument;
use FilesystemIterator;
use Opis\JsonSchema\Validator as JsonSchemaValidator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;

final class PlanOneBEndToEndContractTest extends TestCase
{
    public function test_fixture_and_real_artifact_round_trip_pass_both_validators(): void
    {
        $fixture = $this->decode($this->root().'/tests/Fixtures/Reporting/plan-1b-completion.valid.json');
        $reference = $this->reference($fixture);
        $runtimeValidator = new PlanOneBEvidenceValidator($reference);

        $this->assertSchemaValid($fixture);
        $runtimeValidator->validate($fixture);

        $directory = sys_get_temp_dir().'/most-plan1b-contract-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        $artifact = $directory.'/plan-1b-completion.json';

        try {
            $gateDirectory = $directory.'/build/reports/gates';
            self::assertTrue(mkdir($gateDirectory, 0777, true));
            $gateArtifacts = [];
            $recorder = new PlanOneBGateArtifactRecorder($directory);
            foreach ($fixture['gates'] as $gate) {
                $gatePath = $gateDirectory.'/'.$gate['id'].'.json';
                $definition = PlanOneBGateArtifactRecorder::definition($gate['id']);
                foreach ($definition['producer']['test_paths'] as $path) {
                    $this->writeFile($directory.'/'.$path, "<?php\n");
                }
                $resultPath = $directory.'/'.$definition['producer']['result_artifact_path'];
                if ($gate['id'] === 'static_analysis') {
                    $this->writeStaticResult($resultPath, $definition);
                    $envelope = $recorder->recordStaticAnalysis(
                        $resultPath,
                        $fixture['repository_revision'],
                    );
                } else {
                    $this->writeJunit($resultPath, $directory, $definition['producer']['test_paths']);
                    $envelope = $recorder->recordPhpUnit(
                        $gate['id'],
                        [
                            'command' => $definition['command'],
                            'exit_code' => 0,
                            'started_at' => '2026-07-30T11:59:59Z',
                            'finished_at' => '2026-07-30T12:00:00Z',
                            'duration_ms' => $gate['duration_ms'],
                            'stdout' => "PHPUnit 11.5.0\nOK\n",
                            'stderr' => '',
                        ],
                        $resultPath,
                        $this->writeMeasurementResult(
                            $directory,
                            $definition,
                            $gate['measurements'],
                            $fixture['repository_revision'],
                        ),
                        $fixture['repository_revision'],
                    );
                }
                $bytes = CanonicalJson::encode($envelope)."\n";
                file_put_contents($gatePath, $bytes);
                $gateArtifacts[] = [
                    'path' => 'build/reports/gates/'.$gate['id'].'.json',
                    'sha256' => hash('sha256', $bytes),
                ];
            }

            $built = (new PlanOneBEvidenceBuilder($artifact, null, $directory))->build(
                $reference,
                [
                    'repository_revision' => $fixture['repository_revision'],
                    'gate_artifacts' => $gateArtifacts,
                    'ownership' => $fixture['ownership'],
                    'unresolved_risks' => $fixture['unresolved_risks'],
                ],
                new DateTimeImmutable($fixture['generated_at']),
            );

            self::assertSame('ci', $built['evidence_scope']);
            $runtimeValidator->validate($built);
            $this->assertSchemaValid($built);
            self::assertSame(CanonicalJson::encode($built)."\n", (string) file_get_contents($artifact));
            foreach ($built['gates'] as $index => $gate) {
                self::assertSame($gateArtifacts[$index]['sha256'], $gate['artifacts'][0]['sha256']);
            }
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function test_handoff_ownership_and_post_ci_artifact_boundary_are_exact(): void
    {
        $fixture = $this->decode($this->root().'/tests/Fixtures/Reporting/plan-1b-completion.valid.json');

        self::assertSame([
            'plans_2_and_3' => 'plan_1a_provider_ports_candidate_bindings_only',
            'plan_1c' => 'published_registry_map_and_all_publication_transitions',
            'plan_4' => 'evidence_verification_and_deployment_rollout_only',
            'artifact_path' => 'build/reports/plan-1b-completion.json',
            'digest_algorithm' => 'sha256',
        ], $fixture['handoff']);
        self::assertSame([
            'plan_1c_publication_registry',
            'plan_2_candidate_provider_bindings',
            'plan_3_candidate_provider_bindings',
            'plan_4_evidence_verification_rollout',
        ], $fixture['ownership']['external_plan_owners']);

        $ignored = new Process(
            ['git', 'check-ignore', '-q', 'build/reports/plan-1b-completion.json'],
            $this->root(),
        );
        $ignored->run();
        self::assertSame(0, $ignored->getExitCode());

        $tracked = new Process(
            ['git', 'ls-files', '--error-unmatch', 'build/reports/plan-1b-completion.json'],
            $this->root(),
        );
        $tracked->run();
        self::assertNotSame(0, $tracked->getExitCode());
        self::assertFileDoesNotExist($this->root().'/build/reports/plan-1b-completion.json');
    }

    private function assertSchemaValid(array $document): void
    {
        $schema = json_decode(
            (string) file_get_contents($this->root().'/docs/reports/contracts/plan-1b-evidence.schema.json'),
        );
        $result = (new JsonSchemaValidator)->validate(
            json_decode(json_encode($document, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR),
            $schema,
        );

        self::assertTrue($result->isValid(), $result->error()?->message() ?? 'JSON Schema validation failed');
    }

    private function writeJunit(string $path, string $root, array $suitePaths): void
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $suites = $document->createElement('testsuites');
        $document->appendChild($suites);
        foreach ($suitePaths as $index => $suitePath) {
            $suite = $document->createElement('testsuite');
            $suite->setAttribute('name', 'Suite'.($index + 1));
            $suite->setAttribute('file', str_replace('/', DIRECTORY_SEPARATOR, $root.'/'.$suitePath));
            $suite->setAttribute('tests', '1');
            $suite->setAttribute('assertions', '1');
            $suite->setAttribute('errors', '0');
            $suite->setAttribute('failures', '0');
            $suite->setAttribute('skipped', '0');
            $testCase = $document->createElement('testcase');
            $testCase->setAttribute('name', 'test_machine_result');
            $testCase->setAttribute('assertions', '1');
            $suite->appendChild($testCase);
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
            'duration_ms' => 1,
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
            'duration_ms' => 4,
            'syntax' => $syntax,
            'phpstan' => $phpstan,
        ], JSON_THROW_ON_ERROR));
    }

    private function writeMeasurementResult(
        string $root,
        array $definition,
        array $measurements,
        string $revision,
    ): ?string {
        $relativePath = $definition['producer']['measurement_artifact_path'];
        if ($relativePath === null) {
            self::assertSame([], $measurements);

            return null;
        }
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
                'duration_ms' => 1,
                'stdout' => "PHPUnit 11.5.0\nOK\n",
                'stderr' => '',
            ],
            'measurements' => $measurements,
        ])."\n");

        return $path;
    }

    private function writeFile(string $path, string $bytes): void
    {
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
        file_put_contents($path, $bytes);
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }

    private function reference(array $fixture): PlanOneACompletionRef
    {
        $reference = $fixture['plan_1a_reference'];

        return new PlanOneACompletionRef(
            $reference['lock_sha256'],
            $reference['evidence_sha256'],
            new DateTimeImmutable($reference['generated_at']),
            $reference['status'],
        );
    }

    private function decode(string $path): array
    {
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
