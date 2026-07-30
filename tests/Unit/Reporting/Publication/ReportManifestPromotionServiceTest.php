<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Application\Catalog\ReportPermissionCatalog;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportDefinitionCanonicalProjector;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportDefinitionVersionPolicy;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportManifestPromotionService;
use App\BusinessModules\Core\Reporting\Domain\DTO\CandidateReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCandidateValidationItem;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCandidateValidationResult;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFormulaConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationLock;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSourceConformanceEvidence;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportManifestSemanticValidator;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\YamlCandidateReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\YamlReportManifestLoader;
use App\BusinessModules\Core\Reporting\Infrastructure\Publication\FilesystemReportPublicationLedger;
use App\BusinessModules\Core\Reporting\Infrastructure\Validation\Draft202012SchemaValidator;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use InvalidArgumentException;
use Opis\JsonSchema\CompliantValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReportManifestPromotionServiceTest extends TestCase
{
    public function test_promotion_returns_published_wrapper_only_after_reloading_output_bytes(): void
    {
        [$service, $current, $candidateManifest, $candidate, $validation, $conformance] = $this->validArguments();

        $release = $service->promote(
            $current,
            $candidateManifest,
            $candidate,
            $validation,
            $conformance,
            $candidateManifest->bytesHash,
            str_repeat('1', 40),
            new DateTimeImmutable('2026-07-26T00:00:00Z'),
        );

        self::assertInstanceOf(PublishedReportDefinition::class, $release->published);
        self::assertSame('published', $release->published->payload()->publicationReadiness->value);
        self::assertSame(hash('sha256', $release->publishedBytes), $release->publishedBytesHash->value);
        self::assertSame($release->published->definitionHash->value, $release->lock->definitionHash->value);
        self::assertSame($conformance->digest()->value, $release->lock->conformanceHash->value);
    }

    public function test_changed_unrelated_definition_is_rejected(): void
    {
        [$service, $current, $candidateManifest, $candidate, $validation, $conformance] = $this->validArguments();
        $definitions = $candidateManifest->definitions;
        $definitions[1]['category'] = 'changed';
        $changedManifest = new \App\BusinessModules\Core\Reporting\Domain\DTO\LoadedReportManifest(
            $candidateManifest->catalog,
            $candidateManifest->contractVersion,
            $candidateManifest->bytesHash,
            $definitions,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_promotion_unrelated_definition_changed');

        $service->promote(
            $current,
            $changedManifest,
            $candidate,
            $validation,
            $conformance,
            $candidateManifest->bytesHash,
            str_repeat('1', 40),
            new DateTimeImmutable('2026-07-26T00:00:00Z'),
        );
    }

    public function test_forged_candidate_wrapper_with_real_code_and_hash_is_rejected(): void
    {
        [$service, $current, $candidateManifest, $candidate, $validation, $conformance] = $this->validArguments();
        $payload = $candidate->payload();
        $forged = new CandidateReportDefinition(new ReportDefinition(
            $payload->code,
            $payload->definitionHash,
            $payload->contractVersion,
            $payload->formulaVersion,
            $payload->sourceSchemaVersion,
            $payload->rendererVersion,
            [['id' => 'forged_filter']],
            $payload->columns,
            $payload->sorts,
            $payload->formats,
            $payload->permissionPolicy,
            $payload->snapshotClassification,
            $payload->outputClassification,
            $payload->publicationReadiness,
            $payload->supportsSubscriptions,
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('report_promotion_candidate_wrapper_mismatch');

        $service->promote(
            $current,
            $candidateManifest,
            $forged,
            $validation,
            $conformance,
            $candidateManifest->bytesHash,
            str_repeat('1', 40),
            new DateTimeImmutable('2026-07-26T00:00:00Z'),
        );
    }

    public function test_filesystem_ledger_is_idempotent_and_rejects_conflicting_event_bytes(): void
    {
        $directory = sys_get_temp_dir().'/most-report-ledger-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        $path = $directory.'/ledger.json';
        $validator = new Draft202012SchemaValidator(new CompliantValidator);
        $ledger = new FilesystemReportPublicationLedger(
            $validator,
            $this->root().'/docs/reports/contracts/report-publication-ledger.schema.json',
        );
        $lock = $this->lock(str_repeat('1', 40), '4');

        try {
            $ledger->append($path, $lock);
            $firstBytes = file_get_contents($path);
            $ledger->append($path, $lock);

            self::assertSame($firstBytes, file_get_contents($path));
            $ledger->append($path, $this->lock(str_repeat('1', 40), '6'));
            self::assertCount(
                2,
                json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['events'],
            );

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('report_publication_ledger_event_conflict');
            $ledger->append($path, $this->lock(str_repeat('2', 40), '4'));
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            if (is_file($path.'.lock')) {
                unlink($path.'.lock');
            }
            rmdir($directory);
        }
    }

    public function test_publication_transaction_rolls_back_all_artifacts_when_second_rename_fails(): void
    {
        $directory = sys_get_temp_dir().'/most-report-transaction-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        $ledgerPath = $directory.'/ledger.json';
        $outputPath = $directory.'/published.yaml';
        $lockPath = $directory.'/lock.json';
        $renameCalls = 0;
        $ledger = new FilesystemReportPublicationLedger(
            new Draft202012SchemaValidator(new CompliantValidator),
            $this->root().'/docs/reports/contracts/report-publication-ledger.schema.json',
            static function (string $temporary, string $final) use (&$renameCalls): bool {
                $renameCalls++;

                return $renameCalls === 1 && rename($temporary, $final);
            },
        );

        try {
            $ledger->publish(
                $ledgerPath,
                $this->lock(str_repeat('1', 40), '4'),
                [$outputPath => "published\n", $lockPath => "{}\n"],
                static function (): void {},
            );
            self::fail('The injected second rename failure must abort publication.');
        } catch (RuntimeException $exception) {
            self::assertSame('report_publication_artifact_rename_failed', $exception->getMessage());
            self::assertFileDoesNotExist($outputPath);
            self::assertFileDoesNotExist($lockPath);
            self::assertFileDoesNotExist($ledgerPath);
        } finally {
            foreach ([$outputPath, $lockPath, $ledgerPath, $ledgerPath.'.lock'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
        }
    }

    public function test_new_ledger_failure_rolls_back_previously_published_artifacts(): void
    {
        $directory = sys_get_temp_dir().'/most-report-new-ledger-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        $ledgerPath = $directory.'/ledger.json';
        $outputPath = $directory.'/published.yaml';
        $lockPath = $directory.'/lock.json';
        $renameCalls = 0;
        $ledger = new FilesystemReportPublicationLedger(
            new Draft202012SchemaValidator(new CompliantValidator),
            $this->root().'/docs/reports/contracts/report-publication-ledger.schema.json',
            static function (string $temporary, string $final) use (&$renameCalls): bool {
                $renameCalls++;

                return $renameCalls < 3 && rename($temporary, $final);
            },
        );

        try {
            $ledger->publish(
                $ledgerPath,
                $this->lock(str_repeat('1', 40), '4'),
                [$outputPath => "published\n", $lockPath => "{}\n"],
                static function (): void {},
            );
            self::fail('The injected new-ledger rename failure must abort publication.');
        } catch (RuntimeException $exception) {
            self::assertSame('report_publication_ledger_replace_failed', $exception->getMessage());
            self::assertFileDoesNotExist($outputPath);
            self::assertFileDoesNotExist($lockPath);
            self::assertFileDoesNotExist($ledgerPath);
        } finally {
            foreach ([$outputPath, $lockPath, $ledgerPath, $ledgerPath.'.lock'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
        }
    }

    public function test_existing_ledger_is_restored_when_staged_replace_fails(): void
    {
        $directory = sys_get_temp_dir().'/most-report-existing-ledger-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        $path = $directory.'/ledger.json';
        $schemaPath = $this->root().'/docs/reports/contracts/report-publication-ledger.schema.json';
        $validator = new Draft202012SchemaValidator(new CompliantValidator);
        (new FilesystemReportPublicationLedger($validator, $schemaPath))
            ->append($path, $this->lock(str_repeat('1', 40), '4'));
        $original = (string) file_get_contents($path);
        $renameCalls = 0;
        $ledger = new FilesystemReportPublicationLedger(
            $validator,
            $schemaPath,
            static function (string $temporary, string $final) use (&$renameCalls): bool {
                $renameCalls++;
                if ($renameCalls === 2) {
                    return false;
                }

                return rename($temporary, $final);
            },
        );

        try {
            $ledger->append($path, $this->lock(str_repeat('1', 40), '6'));
            self::fail('The injected existing-ledger replace failure must abort append.');
        } catch (RuntimeException $exception) {
            self::assertSame('report_publication_ledger_replace_failed', $exception->getMessage());
            self::assertSame($original, file_get_contents($path));
        } finally {
            foreach ([$path, $path.'.lock'] as $candidate) {
                if (is_file($candidate)) {
                    unlink($candidate);
                }
            }
            foreach (glob($directory.'/.report-*') ?: [] as $candidate) {
                if (is_file($candidate)) {
                    unlink($candidate);
                }
            }
            rmdir($directory);
        }
    }

    public function test_ledger_rejects_semantic_event_tampering_and_duplicate_event_ids(): void
    {
        $directory = sys_get_temp_dir().'/most-report-ledger-semantic-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        $path = $directory.'/ledger.json';
        $ledger = new FilesystemReportPublicationLedger(
            new Draft202012SchemaValidator(new CompliantValidator),
            $this->root().'/docs/reports/contracts/report-publication-ledger.schema.json',
        );
        $firstLock = $this->lock(str_repeat('1', 40), '4');
        $ledger->append($path, $firstLock);
        $original = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $secondLock = $this->lock(str_repeat('2', 40), '4');

        $cases = [
            'wrong digest' => static function (array $document): array {
                $document['events'][0]['lock_digest'] = str_repeat('a', 64);

                return $document;
            },
            'event identity mismatch' => static function (array $document): array {
                $document['events'][0]['event_id'] = 'reports:definition:quality_report:published:'
                    .$document['events'][0]['lock']['definition_hash'];

                return $document;
            },
            'duplicate event id with conflicting bytes' => static function (array $document) use ($secondLock): array {
                $document['events'][] = [
                    'event_id' => $document['events'][0]['event_id'],
                    'event_type' => 'definition_published',
                    'lock' => $secondLock->canonicalPayload(),
                    'lock_digest' => $secondLock->digest()->value,
                ];

                return $document;
            },
        ];

        try {
            foreach ($cases as $name => $mutate) {
                file_put_contents($path, CanonicalJson::encode($mutate($original))."\n");
                try {
                    $ledger->append($path, $this->lock(str_repeat('1', 40), '6'));
                    self::fail($name.' must be rejected.');
                } catch (RuntimeException $exception) {
                    self::assertSame('report_publication_ledger_event_invalid', $exception->getMessage(), $name);
                }
            }
        } finally {
            foreach ([$path, $path.'.lock'] as $candidate) {
                if (is_file($candidate)) {
                    unlink($candidate);
                }
            }
            rmdir($directory);
        }
    }

    public function test_concurrent_distinct_ledger_appends_preserve_both_events(): void
    {
        $directory = sys_get_temp_dir().'/most-report-concurrency-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        $ledgerPath = $directory.'/ledger.json';
        $child = <<<'PHP'
require $argv[1];
$validator = new App\BusinessModules\Core\Reporting\Infrastructure\Validation\Draft202012SchemaValidator(
    new Opis\JsonSchema\CompliantValidator,
);
$ledger = new App\BusinessModules\Core\Reporting\Infrastructure\Publication\FilesystemReportPublicationLedger(
    $validator,
    $argv[2],
);
$hash = static fn (string $value) => new App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash(
    str_repeat($value, 64),
);
$ledger->append(
    $argv[3],
    new App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationLock(
        'project_portfolio_health',
        $hash('1'),
        $hash('2'),
        $hash('3'),
        $hash($argv[4]),
        $hash('5'),
        str_repeat('1', 40),
        new DateTimeImmutable('2026-07-26T00:00:00Z'),
    ),
);
PHP;
        $commands = [];
        foreach (['4', '6'] as $definitionDigit) {
            $commands[] = [
                PHP_BINARY,
                '-r',
                $child,
                $this->root().'/vendor/autoload.php',
                $this->root().'/docs/reports/contracts/report-publication-ledger.schema.json',
                $ledgerPath,
                $definitionDigit,
            ];
        }

        $processes = [];
        try {
            foreach ($commands as $command) {
                $process = proc_open(
                    $command,
                    [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $pipes,
                    null,
                    null,
                    ['bypass_shell' => true],
                );
                self::assertIsResource($process);
                fclose($pipes[0]);
                $processes[] = [$process, $pipes];
            }
            foreach ($processes as [$process, $pipes]) {
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $stdout.$stderr);
            }
            $document = json_decode(
                (string) file_get_contents($ledgerPath),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
            self::assertCount(2, $document['events']);
            self::assertCount(2, array_unique(array_column($document['events'], 'event_id')));
        } finally {
            foreach ([$ledgerPath, $ledgerPath.'.lock'] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
        }
    }

    private function validArguments(): array
    {
        $loader = $this->loader();
        $manifestSchema = $this->root().'/app/BusinessModules/Core/Reporting/resources/management-catalog.v1.schema.json';
        $current = $loader->loadManagement(
            $this->root().'/tests/Fixtures/Reporting/Manifest/management.valid.yaml',
            $manifestSchema,
        );
        $candidateManifest = $loader->loadManagement(
            $this->root().'/tests/Fixtures/Reporting/Publication/candidate.valid.yaml',
            $manifestSchema,
        );
        $candidate = (new YamlCandidateReportDefinitionRegistry(
            $candidateManifest,
            new ReportDefinitionFactory,
        ))->candidate('project_portfolio_health');
        $validation = new ReportCandidateValidationResult([
            new ReportCandidateValidationItem(
                $candidate->code,
                $candidate->definitionHash,
                true,
                [],
            ),
        ]);
        $conformance = new ReportDefinitionConformanceEvidence(
            $candidate->code,
            $candidate->definitionHash,
            $candidate->payload()->contractVersion,
            $candidate->payload()->sourceSchemaVersion,
            new Sha256Hash(str_repeat('f', 64)),
            new ReportSourceConformanceEvidence(
                new Sha256Hash(str_repeat('e', 64)),
                'fixture',
                'fixture-1',
                2,
                new Sha256Hash(str_repeat('d', 64)),
                true,
                ['source.identity.passed'],
            ),
            new ReportFormulaConformanceEvidence(
                $candidate->payload()->formulaVersion,
                new Sha256Hash(str_repeat('c', 64)),
                true,
                ['formula.identity.passed'],
            ),
            ['Tests\\Support\\Reporting\\CatalogTestDataProvider' => new Sha256Hash(str_repeat('a', 64))],
            2,
            'passed',
            str_repeat('1', 40),
            new DateTimeImmutable('2026-07-26T00:00:00Z'),
        );

        return [
            new ReportManifestPromotionService(
                new ReportDefinitionVersionPolicy,
                new ReportDefinitionCanonicalProjector,
                new ReportDefinitionFactory,
                $loader,
                new Draft202012SchemaValidator(new CompliantValidator),
                $manifestSchema,
                $this->root().'/docs/reports/contracts/report-publication-lock.schema.json',
            ),
            $current,
            $candidateManifest,
            $candidate,
            $validation,
            $conformance,
        ];
    }

    private function loader(): YamlReportManifestLoader
    {
        return new YamlReportManifestLoader(
            new Draft202012SchemaValidator(new CompliantValidator),
            new ReportManifestSemanticValidator,
            new ReportPermissionCatalog,
        );
    }

    private function lock(string $releaseSha, string $definitionDigit): ReportPublicationLock
    {
        return new ReportPublicationLock(
            'project_portfolio_health',
            new Sha256Hash(str_repeat('1', 64)),
            new Sha256Hash(str_repeat('2', 64)),
            new Sha256Hash(str_repeat('3', 64)),
            new Sha256Hash(str_repeat($definitionDigit, 64)),
            new Sha256Hash(str_repeat('5', 64)),
            $releaseSha,
            new DateTimeImmutable('2026-07-26T00:00:00Z'),
        );
    }

    private function root(): string
    {
        return dirname(__DIR__, 4);
    }
}
