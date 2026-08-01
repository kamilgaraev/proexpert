<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Application\Publication\ProductionReportPublicationReleaseIngestion;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseBindingFactory;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseBundleFileLoader;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseCandidateResolver;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseDispatch;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseDispatchProfile;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseIngestor;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseRequestFileLoader;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseRequestResolver;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseRequestResolverFactory;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseTrustedRoots;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationResolvedReleaseRequest;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationFeatureMode;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\Publication\ReportPublicationFixtureFactory;
use Tests\Support\Reporting\Publication\ReportPublicationReleaseArtifactTestFactory;

final class ProductionReportPublicationReleaseIngestionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'most-production-release-ingestion-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
        parent::tearDown();
    }

    public function test_ingests_a_signed_bundle_from_trusted_roots_through_the_release_service(): void
    {
        $fixture = ReportPublicationFixtureFactory::eligible(productionComponents: true);
        $admission = ReportPublicationReleaseArtifactTestFactory::releaseAdmission($fixture, productionSafe: true);
        [$bundleRoot, $candidateRoot, $artifactName] = $this->writeTrustedInputs($fixture['eligible']->proof->canonicalBytes(), $fixture['eligible']->releaseArtifactBytes, $fixture['eligible']->release->gitSha);
        $resolver = $this->createMock(ReportPublicationReleaseRequestResolver::class);
        $resolver->expects(self::once())->method('resolve')->willReturn(new ReportPublicationResolvedReleaseRequest($admission, $this->createMock(\App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseEligibilityGate::class)));
        $resolvers = $this->createMock(ReportPublicationReleaseRequestResolverFactory::class);
        $resolvers->expects(self::once())->method('dispatchForArtifactName')->with($artifactName)->willReturn($this->dispatch($fixture['eligible']->candidate->code));
        $resolvers->expects(self::once())->method('create')->with(self::callback(
            static fn (string $root): bool => realpath($root) === realpath($candidateRoot),
        ))->willReturn($resolver);
        $ingestor = $this->createMock(ReportPublicationReleaseIngestor::class);
        $ingestor->expects(self::once())->method('ingest')->with(
            self::callback(static fn (string $path): bool => strtolower((string) realpath($path)) === strtolower((string) realpath($bundleRoot.DIRECTORY_SEPARATOR.$artifactName.'.proof.json'))),
            self::callback(static fn (string $path): bool => strtolower((string) realpath($path)) === strtolower((string) realpath($bundleRoot.DIRECTORY_SEPARATOR.$artifactName.'.json'))),
            self::callback(static fn (string $path): bool => strtolower((string) realpath($path)) === strtolower((string) realpath($bundleRoot))),
            $admission,
            $fixture['eligible']->release->gitSha,
            ReportPublicationFeatureMode::ON,
            [42],
            [77],
        )->willReturn($this->published($fixture));

        $published = $this->service($bundleRoot, $candidateRoot, $resolvers, $ingestor)->ingest(
            $artifactName, ReportPublicationFeatureMode::ON, [42], [77],
        );

        self::assertSame($fixture['eligible']->candidate->code, $published->definition->code);
    }

    public function test_rejects_a_missing_serialized_bundle_file_before_release_resolution(): void
    {
        $fixture = ReportPublicationFixtureFactory::eligible();
        [$bundleRoot, $candidateRoot, $artifactName] = $this->writeTrustedInputs($fixture['eligible']->proof->canonicalBytes(), $fixture['eligible']->releaseArtifactBytes, $fixture['eligible']->release->gitSha);
        unlink($bundleRoot.DIRECTORY_SEPARATOR.$artifactName.'.json');
        $resolvers = $this->createMock(ReportPublicationReleaseRequestResolverFactory::class);
        $resolvers->expects(self::once())->method('dispatchForArtifactName')->with($artifactName)->willReturn($this->dispatch());
        $resolvers->expects(self::never())->method('create');
        $ingestor = $this->createMock(ReportPublicationReleaseIngestor::class);
        $ingestor->expects(self::never())->method('ingest');

        $this->expectException(\InvalidArgumentException::class);

        $this->service($bundleRoot, $candidateRoot, $resolvers, $ingestor)->ingest($artifactName);
    }

    public function test_rejects_missing_configured_trusted_roots(): void
    {
        $this->expectExceptionMessage('report_publication_release_trusted_root_invalid');

        new ReportPublicationReleaseTrustedRoots('', $this->root);
    }

    public function test_rejects_a_tampered_serialized_bundle_before_release_resolution(): void
    {
        $fixture = ReportPublicationFixtureFactory::eligible();
        [$bundleRoot, $candidateRoot, $artifactName] = $this->writeTrustedInputs($fixture['eligible']->proof->canonicalBytes(), $fixture['eligible']->releaseArtifactBytes, $fixture['eligible']->release->gitSha);
        file_put_contents($bundleRoot.DIRECTORY_SEPARATOR.$artifactName.'.proof.json', '{}');
        $resolvers = $this->createMock(ReportPublicationReleaseRequestResolverFactory::class);
        $resolvers->expects(self::once())->method('dispatchForArtifactName')->with($artifactName)->willReturn($this->dispatch());
        $resolvers->expects(self::never())->method('create');
        $ingestor = $this->createMock(ReportPublicationReleaseIngestor::class);
        $ingestor->expects(self::never())->method('ingest');

        $this->expectException(\InvalidArgumentException::class);

        $this->service($bundleRoot, $candidateRoot, $resolvers, $ingestor)->ingest($artifactName);
    }

    private function writeTrustedInputs(string $proof, string $artifact, string $commit): array
    {
        $bundleRoot = $this->root.DIRECTORY_SEPARATOR.'bundle';
        $candidateRoot = $this->root.DIRECTORY_SEPARATOR.'candidate';
        mkdir($bundleRoot, 0700, true);
        mkdir($candidateRoot, 0700, true);
        $proofData = json_decode($proof, true, 512, JSON_THROW_ON_ERROR);
        $artifactName = 'report-publication-'.$proofData['code'].'-'.hash('sha256', $proof);
        file_put_contents($bundleRoot.DIRECTORY_SEPARATOR.$artifactName.'.proof.json', $proof);
        file_put_contents($bundleRoot.DIRECTORY_SEPARATOR.$artifactName.'.json', $artifact);
        foreach (['r15-candidate-manifest.json', 'r15-conformance-evidence.json', 'r15-proof-template.json'] as $name) {
            file_put_contents($candidateRoot.DIRECTORY_SEPARATOR.$name, '{}');
        }
        file_put_contents($candidateRoot.DIRECTORY_SEPARATOR.'r15_release_request.json', json_encode([
            'request_id' => 'r15_release_request',
            'schema_version' => '1.0.0',
            'code' => $proofData['code'],
            'commit_sha' => $commit,
            'proof_sha256' => hash('sha256', $proof),
            'artifact_paths' => [
                'candidate_manifest' => 'r15-candidate-manifest.json',
                'conformance_evidence' => 'r15-conformance-evidence.json',
                'proof_template' => 'r15-proof-template.json',
            ],
        ], JSON_THROW_ON_ERROR));

        return [$bundleRoot, $candidateRoot, $artifactName];
    }

    private function published(array $fixture): PublishedReportDefinition
    {
        $definition = $fixture['eligible']->candidate->definition;

        return new PublishedReportDefinition(new \App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition(
            $definition->code,
            $definition->definitionHash,
            $definition->contractVersion,
            $definition->formulaVersion,
            $definition->sourceSchemaVersion,
            $definition->rendererVersion,
            $definition->filters,
            $definition->columns,
            $definition->sorts,
            $definition->formats,
            $definition->permissionPolicy,
            $definition->snapshotClassification,
            $definition->outputClassification,
            \App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationReadiness::PUBLISHED,
            $definition->supportsSubscriptions,
            $definition->sourceModule,
            $definition->coreAccessMode,
        ));
    }

    private function service(
        string $bundleRoot,
        string $candidateRoot,
        ReportPublicationReleaseRequestResolverFactory $resolvers,
        ReportPublicationReleaseIngestor $ingestor,
    ): ProductionReportPublicationReleaseIngestion {
        return new ProductionReportPublicationReleaseIngestion(
            new ReportPublicationReleaseTrustedRoots($bundleRoot, $candidateRoot),
            new ReportPublicationReleaseRequestFileLoader,
            $resolvers,
            $ingestor,
            new ReportPublicationReleaseBundleFileLoader,
            ReportPublicationReleaseArtifactTestFactory::verifier(),
        );
    }

    private function dispatch(string $code = 'procurement_cycle'): ReportPublicationReleaseDispatch
    {
        return new ReportPublicationReleaseDispatch(
            new ReportPublicationReleaseDispatchProfile(
                $code,
                'r15_release_request',
                [
                    'candidate_manifest' => 'r15-candidate-manifest.json',
                    'conformance_evidence' => 'r15-conformance-evidence.json',
                    'proof_template' => 'r15-proof-template.json',
                ],
            ),
            new class implements ReportPublicationReleaseCandidateResolver
            {
                public function resolve(string $trustedDirectory, \App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseRequest $request): array
                {
                    return [];
                }
            },
            new class implements ReportPublicationReleaseBindingFactory
            {
                public function create(\App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition $definition): \App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinitionBinding
                {
                    throw new \LogicException('not_called');
                }
            },
        );
    }

    private function remove(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path.DIRECTORY_SEPARATOR.$entry;
            is_dir($child) && ! is_link($child) ? $this->remove($child) : unlink($child);
        }
        rmdir($path);
    }
}
