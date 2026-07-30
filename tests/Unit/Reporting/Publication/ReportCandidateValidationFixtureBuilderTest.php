<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Application\Catalog\ImmutableReportConformanceFixtureHashRegistry;
use App\BusinessModules\Core\Reporting\Application\Catalog\ReportBindingCompatibilityChecker;
use App\BusinessModules\Core\Reporting\Application\Catalog\ReportCodeSetComparator;
use App\BusinessModules\Core\Reporting\Application\Catalog\ReportPermissionCatalog;
use App\BusinessModules\Core\Reporting\Application\Catalog\StrictReportDefinitionCandidateValidator;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportDefinitionFactory;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\ReportManifestSemanticValidator;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\YamlCandidateReportDefinitionRegistry;
use App\BusinessModules\Core\Reporting\Infrastructure\Catalog\YamlReportManifestLoader;
use App\BusinessModules\Core\Reporting\Infrastructure\Validation\Draft202012SchemaValidator;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use Opis\JsonSchema\CompliantValidator;
use PHPUnit\Framework\TestCase;
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

        $generated = (new ReportCandidateValidationFixtureBuilder($validator))->build(
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
}
