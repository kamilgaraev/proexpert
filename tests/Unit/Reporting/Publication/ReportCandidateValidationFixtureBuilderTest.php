<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Application\Catalog\ImmutableReportConformanceFixtureHashRegistry;
use App\BusinessModules\Core\Reporting\Application\Catalog\ReportBindingCompatibilityChecker;
use App\BusinessModules\Core\Reporting\Application\Catalog\ReportCodeSetComparator;
use App\BusinessModules\Core\Reporting\Application\Catalog\ReportPermissionCatalog;
use App\BusinessModules\Core\Reporting\Application\Catalog\StrictReportDefinitionCandidateValidator;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportDefinitionCanonicalProjector;
use App\BusinessModules\Core\Reporting\Domain\Contracts\CandidateReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\CandidateReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportManifestSemanticValidator;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\YamlCandidateReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\YamlReportManifestLoader;
use App\BusinessModules\Core\Reporting\Infrastructure\Validation\Draft202012SchemaValidator;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use Opis\JsonSchema\CompliantValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\Reporting\CatalogBindingTestFactory;
use Tests\Support\Reporting\Publication\ReportCandidateValidationFixtureBuilder;
use Tests\Support\Reporting\RecordingReportConformanceEvidenceRepository;
use Tests\Support\Reporting\ReportConformanceFixtureBuilder;

final class ReportCandidateValidationFixtureBuilderTest extends TestCase
{
    public function test_candidate_checksum_and_validation_are_exact_validator_outputs(): void
    {
        $root = dirname(__DIR__, 4);
        $loader = new YamlReportManifestLoader(
            new Draft202012SchemaValidator(new CompliantValidator),
            new ReportManifestSemanticValidator,
            new ReportPermissionCatalog,
        );
        $manifest = $loader->loadManagement(
            $root.'/tests/Fixtures/Reporting/Publication/candidate.valid.yaml',
            $root.'/app/BusinessModules/Core/Reporting/resources/management-catalog.v1.schema.json',
        );
        $registry = new YamlCandidateReportDefinitionRegistry($manifest, new ReportDefinitionFactory);
        $candidate = $registry->candidate('project_portfolio_health');
        $binding = CatalogBindingTestFactory::binding($candidate->payload());
        $fixture = (new ReportConformanceFixtureBuilder)
            ->fixtureHash(new Sha256Hash(str_repeat('f', 64)))
            ->build();
        $validator = new StrictReportDefinitionCandidateValidator(
            new RecordingReportConformanceEvidenceRepository(
                CatalogBindingTestFactory::evidence(
                    $candidate->payload(),
                    $binding,
                    $fixture->fixtureHash,
                ),
            ),
            new ImmutableReportConformanceFixtureHashRegistry([$candidate->code => $fixture]),
            new ReportBindingCompatibilityChecker,
            new ReportCodeSetComparator,
        );

        $generated = (new ReportCandidateValidationFixtureBuilder(
            $validator,
            new ReportDefinitionFactory,
            new ReportDefinitionCanonicalProjector,
        ))->build(
            $manifest,
            $registry,
            [$candidate->code => $binding],
        );

        self::assertSame(
            file_get_contents($root.'/tests/Fixtures/Reporting/Publication/candidate.valid.sha256'),
            $generated->checksumBytes,
        );
        self::assertSame(
            file_get_contents($root.'/tests/Fixtures/Reporting/Publication/candidate-validation.valid.json'),
            $generated->validationBytes,
        );
    }

    public function test_script_rejects_caller_authored_conformance_with_recomputed_digest(): void
    {
        $root = dirname(__DIR__, 4);
        $relativePath = 'tests/Fixtures/Reporting/Publication/conformance.forged.json';
        $path = $root.'/'.$relativePath;
        $document = json_decode(
            (string) file_get_contents(
                $root.'/tests/Fixtures/Reporting/Conformance/report-conformance-evidence.valid.json',
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $document['fixture_hash'] = str_repeat('a', 64);
        unset($document['digest']);
        $document['digest'] = hash('sha256', CanonicalJson::encode($document));
        file_put_contents(
            $path,
            json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );
        $command = [
            PHP_BINARY,
            $root.'/scripts/reporting/promote-report-definition.php',
            '--current=tests/Fixtures/Reporting/Manifest/management.valid.yaml',
            '--candidate=tests/Fixtures/Reporting/Publication/candidate.valid.yaml',
            '--candidate-sha256=tests/Fixtures/Reporting/Publication/candidate.valid.sha256',
            '--validation=tests/Fixtures/Reporting/Publication/candidate-validation.valid.json',
            '--conformance='.$relativePath,
            '--release-sha='.str_repeat('1', 40),
            '--published-at=2026-07-26T00:00:00Z',
            '--output=tests/Fixtures/Reporting/Publication/published.expected.yaml',
            '--lock-output=tests/Fixtures/Reporting/Publication/report-publication-lock.valid.json',
            '--check',
        ];

        try {
            $process = proc_open(
                $command,
                [
                    0 => ['pipe', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes,
                $root,
                null,
                ['bypass_shell' => true],
            );
            self::assertIsResource($process);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            self::assertSame(1, proc_close($process), $stdout.$stderr);
            self::assertSame('', $stdout);
            self::assertSame("promotion-check: FAIL\n", $stderr);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_builder_rejects_foreign_registry_payload_with_manifest_code_and_hash(): void
    {
        $root = dirname(__DIR__, 4);
        $loader = new YamlReportManifestLoader(
            new Draft202012SchemaValidator(new CompliantValidator),
            new ReportManifestSemanticValidator,
            new ReportPermissionCatalog,
        );
        $manifest = $loader->loadManagement(
            $root.'/tests/Fixtures/Reporting/Publication/candidate.valid.yaml',
            $root.'/app/BusinessModules/Core/Reporting/resources/management-catalog.v1.schema.json',
        );
        $realRegistry = new YamlCandidateReportDefinitionRegistry($manifest, new ReportDefinitionFactory);
        $real = $realRegistry->candidate('project_portfolio_health');
        $payload = $real->payload();
        $foreign = new CandidateReportDefinition(new ReportDefinition(
            $payload->code,
            $payload->definitionHash,
            $payload->contractVersion,
            $payload->formulaVersion,
            $payload->sourceSchemaVersion,
            $payload->rendererVersion,
            $payload->filters,
            [['id' => 'foreign_column']],
            $payload->sorts,
            $payload->formats,
            $payload->permissionPolicy,
            $payload->snapshotClassification,
            $payload->outputClassification,
            $payload->publicationReadiness,
            $payload->supportsSubscriptions,
        ));
        $foreignRegistry = new class($foreign) implements CandidateReportDefinitionRegistry
        {
            public function __construct(private CandidateReportDefinition $candidate) {}

            public function candidate(string $code): CandidateReportDefinition
            {
                return $this->candidate;
            }

            public function candidateCodes(): array
            {
                return [$this->candidate->code];
            }
        };
        $binding = CatalogBindingTestFactory::binding($real->payload());
        $fixture = (new ReportConformanceFixtureBuilder)
            ->fixtureHash(new Sha256Hash(str_repeat('f', 64)))
            ->build();
        $validator = new StrictReportDefinitionCandidateValidator(
            new RecordingReportConformanceEvidenceRepository(
                CatalogBindingTestFactory::evidence(
                    $real->payload(),
                    $binding,
                    $fixture->fixtureHash,
                ),
            ),
            new ImmutableReportConformanceFixtureHashRegistry([$real->code => $fixture]),
            new ReportBindingCompatibilityChecker,
            new ReportCodeSetComparator,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('report_candidate_fixture_registry_mismatch');

        (new ReportCandidateValidationFixtureBuilder(
            $validator,
            new ReportDefinitionFactory,
            new ReportDefinitionCanonicalProjector,
        ))->build(
            $manifest,
            $foreignRegistry,
            [$real->code => $binding],
        );
    }

    public function test_offline_script_rejects_full_byte_and_provenance_negative_matrix(): void
    {
        $root = dirname(__DIR__, 4);
        $paths = [
            'candidate' => $root.'/tests/Fixtures/Reporting/Publication/candidate.valid.yaml',
            'checksum' => $root.'/tests/Fixtures/Reporting/Publication/candidate.valid.sha256',
            'validation' => $root.'/tests/Fixtures/Reporting/Publication/candidate-validation.valid.json',
            'conformance' => $root.'/tests/Fixtures/Reporting/Conformance/report-conformance-evidence.valid.json',
            'output' => $root.'/tests/Fixtures/Reporting/Publication/published.expected.yaml',
            'lock' => $root.'/tests/Fixtures/Reporting/Publication/report-publication-lock.valid.json',
        ];
        $original = array_map(
            static fn (string $path): string => (string) file_get_contents($path),
            $paths,
        );
        $validation = json_decode($original['validation'], true, 512, JSON_THROW_ON_ERROR);
        $realItem = $validation['items'][0];
        $fakeItem = [
            'code' => 'quality_report',
            'definition_hash' => str_repeat('a', 64),
            'failure_codes' => [],
            'passed' => true,
        ];
        $staleConformance = json_decode($original['conformance'], true, 512, JSON_THROW_ON_ERROR);
        $staleConformance['commit_sha'] = str_repeat('2', 40);
        unset($staleConformance['digest']);
        $staleConformance['digest'] = hash('sha256', CanonicalJson::encode($staleConformance));

        $cases = [
            'candidate BOM' => ['files' => ['candidate' => "\xEF\xBB\xBF".$original['candidate']]],
            'candidate CRLF' => ['files' => ['candidate' => str_replace("\n", "\r\n", $original['candidate'])]],
            'candidate terminal LF missing' => ['files' => ['candidate' => substr($original['candidate'], 0, -1)]],
            'candidate extra terminal LF' => ['files' => ['candidate' => $original['candidate']."\n"]],
            'stale candidate bytes' => ['files' => [
                'candidate' => str_replace('category: portfolio', 'category: portfolio_changed', $original['candidate']),
            ]],
            'uppercase checksum' => ['files' => ['checksum' => strtoupper(trim($original['checksum']))."\n"]],
            'checksum terminal LF missing' => ['files' => ['checksum' => trim($original['checksum'])]],
            'checksum extra terminal LF' => ['files' => ['checksum' => $original['checksum']."\n"]],
            'checksum malformed length' => ['files' => ['checksum' => str_repeat('a', 63)."\n"]],
            'checksum digest mismatch' => ['files' => ['checksum' => str_repeat('0', 64)."\n"]],
            'validation noncanonical' => ['files' => [
                'validation' => json_encode($validation, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n",
            ]],
            'validation unknown root field' => ['files' => [
                'validation' => $this->validationMutation($validation, static function (array &$document): void {
                    $document['unexpected'] = true;
                }),
            ]],
            'validation missing root field' => ['files' => [
                'validation' => $this->validationMutation($validation, static function (array &$document): void {
                    unset($document['status']);
                }),
            ]],
            'validation alternate candidate path' => ['files' => [
                'validation' => $this->validationMutation($validation, static function (array &$document): void {
                    $document['candidate_manifest']['path'] = 'tests/Fixtures/Reporting/Publication/alternate.yaml';
                }),
            ]],
            'validation failed item' => ['files' => [
                'validation' => $this->validationMutation($validation, static function (array &$document): void {
                    $document['items'][0]['passed'] = false;
                    $document['items'][0]['failure_codes'] = ['FORGED_FAILURE'];
                }),
            ]],
            'validation missing item' => ['files' => [
                'validation' => $this->validationMutation($validation, static function (array &$document): void {
                    $document['items'] = [];
                }),
            ]],
            'validation extra item' => ['files' => [
                'validation' => $this->validationMutation(
                    $validation,
                    static function (array &$document) use ($fakeItem): void {
                        $document['items'][] = $fakeItem;
                    },
                ),
            ]],
            'validation duplicate item' => ['files' => [
                'validation' => $this->validationMutation(
                    $validation,
                    static function (array &$document) use ($realItem): void {
                        $document['items'][] = $realItem;
                    },
                ),
            ]],
            'validation reordered items' => ['files' => [
                'validation' => $this->validationMutation(
                    $validation,
                    static function (array &$document) use ($fakeItem, $realItem): void {
                        $document['candidate_manifest']['codes'] = [$realItem['code'], $fakeItem['code']];
                        $document['items'] = [$fakeItem, $realItem];
                    },
                ),
            ]],
            'validation code mismatch' => ['files' => [
                'validation' => $this->validationMutation($validation, static function (array &$document): void {
                    $document['items'][0]['code'] = 'quality_report';
                }),
            ]],
            'validation definition hash mismatch' => ['files' => [
                'validation' => $this->validationMutation($validation, static function (array &$document): void {
                    $document['items'][0]['definition_hash'] = str_repeat('0', 64);
                }),
            ]],
            'validation candidate hash mismatch' => ['files' => [
                'validation' => $this->validationMutation($validation, static function (array &$document): void {
                    $document['candidate_manifest']['sha256'] = str_repeat('0', 64);
                }),
            ]],
            'stale conformance evidence' => ['files' => [
                'conformance' => json_encode(
                    $staleConformance,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                )."\n",
            ]],
            'published output second changed field' => ['files' => [
                'output' => preg_replace(
                    '/category: portfolio/',
                    'category: portfolio_changed',
                    $original['output'],
                    1,
                ),
            ]],
            'lock unknown field' => ['files' => [
                'lock' => $this->validationMutation(
                    json_decode($original['lock'], true, 512, JSON_THROW_ON_ERROR),
                    static function (array &$document): void {
                        $document['unexpected'] = true;
                    },
                ),
            ]],
        ];

        try {
            foreach ($cases as $name => $case) {
                $this->restoreFiles($paths, $original);
                foreach ($case['files'] as $key => $bytes) {
                    self::assertIsString($bytes, $name);
                    file_put_contents($paths[$key], $bytes);
                }
                [$exit, $stdout, $stderr] = $this->runPromotionScript($root);
                self::assertSame(1, $exit, $name.': '.$stdout.$stderr);
                self::assertSame('', $stdout, $name);
                self::assertSame("promotion-check: FAIL\n", $stderr, $name);
            }

            $alternate = $root.'/tests/Fixtures/Reporting/Publication/candidate.alternate.yaml';
            file_put_contents($alternate, $original['candidate']);
            [$exit, $stdout, $stderr] = $this->runPromotionScript(
                $root,
                ['candidate' => 'tests/Fixtures/Reporting/Publication/candidate.alternate.yaml'],
            );
            self::assertSame(1, $exit, 'alternate CLI candidate path: '.$stdout.$stderr);
            self::assertSame('', $stdout);
            self::assertSame("promotion-check: FAIL\n", $stderr);
            unlink($alternate);
        } finally {
            $this->restoreFiles($paths, $original);
            $alternate = $root.'/tests/Fixtures/Reporting/Publication/candidate.alternate.yaml';
            if (is_file($alternate)) {
                unlink($alternate);
            }
        }
    }

    public function test_check_mode_writes_nothing_and_normal_mode_publishes_verified_ledger(): void
    {
        $root = dirname(__DIR__, 4);
        $tracked = [
            $root.'/tests/Fixtures/Reporting/Publication/published.expected.yaml',
            $root.'/tests/Fixtures/Reporting/Publication/report-publication-lock.valid.json',
        ];
        $before = [];
        foreach ($tracked as $path) {
            $before[$path] = [
                'bytes' => file_get_contents($path),
                'mtime' => filemtime($path),
            ];
        }

        [$checkExit, $checkStdout, $checkStderr] = $this->runPromotionScript($root);
        self::assertSame(0, $checkExit, $checkStdout.$checkStderr);
        self::assertSame("promotion-check: PASS\n", $checkStdout);
        self::assertSame('', $checkStderr);
        foreach ($before as $path => $identity) {
            clearstatcache(true, $path);
            self::assertSame($identity['bytes'], file_get_contents($path), $path);
            self::assertSame($identity['mtime'], filemtime($path), $path);
        }

        $suffix = bin2hex(random_bytes(6));
        $outputRelative = 'tests/Fixtures/Reporting/Publication/published.'.$suffix.'.yaml';
        $lockRelative = 'tests/Fixtures/Reporting/Publication/lock.'.$suffix.'.json';
        $output = $root.'/'.$outputRelative;
        $lock = $root.'/'.$lockRelative;
        $ledger = $root.'/tests/Fixtures/Reporting/Publication/report-publication-ledger.json';
        $ledgerLock = $ledger.'.lock';
        try {
            [$normalExit, $normalStdout, $normalStderr] = $this->runPromotionScript(
                $root,
                ['output' => $outputRelative, 'lock-output' => $lockRelative],
                false,
            );
            self::assertSame(0, $normalExit, $normalStdout.$normalStderr);
            self::assertSame("promotion-check: PASS\n", $normalStdout);
            self::assertSame('', $normalStderr);
            self::assertSame(
                file_get_contents($tracked[0]),
                file_get_contents($output),
            );
            self::assertSame(
                file_get_contents($tracked[1]),
                file_get_contents($lock),
            );
            $ledgerDocument = json_decode(
                (string) file_get_contents($ledger),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            self::assertCount(1, $ledgerDocument['events']);

            $ledgerReader = new \App\BusinessModules\Core\Reporting\Infrastructure\Publication\FilesystemReportPublicationLedger(
                new Draft202012SchemaValidator(new CompliantValidator),
                $root.'/docs/reports/contracts/report-publication-ledger.schema.json',
            );
            $eventLock = $ledgerDocument['events'][0]['lock'];
            $ledgerReader->append(
                $ledger,
                new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationLock(
                    $eventLock['code'],
                    new Sha256Hash($eventLock['previous_manifest_hash']),
                    new Sha256Hash($eventLock['candidate_manifest_hash']),
                    new Sha256Hash($eventLock['published_manifest_hash']),
                    new Sha256Hash($eventLock['definition_hash']),
                    new Sha256Hash($eventLock['conformance_hash']),
                    $eventLock['release_sha'],
                    new \DateTimeImmutable($eventLock['published_at']),
                ),
            );
        } finally {
            foreach ([$output, $lock, $ledger, $ledgerLock] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    private function validationMutation(array $document, \Closure $mutate): string
    {
        $mutate($document);

        return CanonicalJson::encode($document)."\n";
    }

    private function restoreFiles(array $paths, array $bytes): void
    {
        foreach ($paths as $key => $path) {
            file_put_contents($path, $bytes[$key]);
        }
    }

    private function runPromotionScript(
        string $root,
        array $overrides = [],
        bool $check = true,
    ): array {
        $options = array_replace([
            'current' => 'tests/Fixtures/Reporting/Manifest/management.valid.yaml',
            'candidate' => 'tests/Fixtures/Reporting/Publication/candidate.valid.yaml',
            'candidate-sha256' => 'tests/Fixtures/Reporting/Publication/candidate.valid.sha256',
            'validation' => 'tests/Fixtures/Reporting/Publication/candidate-validation.valid.json',
            'conformance' => 'tests/Fixtures/Reporting/Conformance/report-conformance-evidence.valid.json',
            'release-sha' => str_repeat('1', 40),
            'published-at' => '2026-07-26T00:00:00Z',
            'output' => 'tests/Fixtures/Reporting/Publication/published.expected.yaml',
            'lock-output' => 'tests/Fixtures/Reporting/Publication/report-publication-lock.valid.json',
        ], $overrides);
        $command = [PHP_BINARY, $root.'/scripts/reporting/promote-report-definition.php'];
        foreach ($options as $name => $value) {
            $command[] = '--'.$name.'='.$value;
        }
        if ($check) {
            $command[] = '--check';
        }
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $root,
            null,
            ['bypass_shell' => true],
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
