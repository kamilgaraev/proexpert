<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Publication;

use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseBundleFileLoader;
use App\BusinessModules\Core\Reporting\Application\Publication\ReportPublicationReleaseIngestionService;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationFeatureStore;
use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationRegistry;
use App\BusinessModules\Core\Reporting\Domain\DTO\EligibleReportPublication;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationIdentity;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationReadiness;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\Publication\ReportPublicationFixtureFactory;
use Tests\Support\Reporting\Publication\ReportPublicationReleaseArtifactTestFactory;

final class ReportPublicationReleaseIngestionServiceTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'report-publication-ingestion-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_rejects_a_signed_artifact_for_a_different_current_commit_before_promotion(): void
    {
        $fixture = ReportPublicationFixtureFactory::eligible();
        $eligible = $fixture['eligible'];
        [$proofPath, $artifactPath] = $this->writeBundle($eligible);
        $artifact = json_decode($eligible->releaseArtifactBytes, true, 512, JSON_THROW_ON_ERROR);
        $artifact['evidence']['commit_sha'] = str_repeat('b', 40);
        file_put_contents($artifactPath, \App\BusinessModules\Core\Reporting\Support\CanonicalJson::encode($artifact));
        $registry = $this->createMock(ReportPublicationRegistry::class);
        $registry->expects(self::never())->method('promote');

        $this->expectException(InvalidArgumentException::class);

        $this->service($fixture, $registry, $this->createMock(ReportPublicationFeatureStore::class))->ingest(
            $proofPath,
            $artifactPath,
            $this->directory,
            ReportPublicationReleaseArtifactTestFactory::releaseAdmission($fixture),
        );
    }

    private function service(array $fixture, ReportPublicationRegistry $registry, ReportPublicationFeatureStore $features): ReportPublicationReleaseIngestionService
    {
        return new ReportPublicationReleaseIngestionService(
            new ReportPublicationReleaseBundleFileLoader,
            ReportPublicationReleaseArtifactTestFactory::verifier(),
            $fixture['eligibility_service'],
            $registry,
            $features,
        );
    }

    private function writeBundle(EligibleReportPublication $publication): array
    {
        $name = 'report-publication-'.$publication->candidate->code.'-'.$publication->proof->digest()->value;
        $proofPath = $this->directory.DIRECTORY_SEPARATOR.$name.'.proof.json';
        $artifact = $this->directory.DIRECTORY_SEPARATOR.$name.'.json';
        file_put_contents($proofPath, $publication->proof->canonicalBytes());
        file_put_contents($artifact, $publication->releaseArtifactBytes);

        return [$proofPath, $artifact];
    }

    private function published(EligibleReportPublication $publication): PublishedReportDefinition
    {
        $definition = $publication->candidate->definition;

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
            ReportPublicationReadiness::PUBLISHED,
            $definition->supportsSubscriptions,
            $definition->sourceModule,
            $definition->coreAccessMode,
        ), new ReportPublicationIdentity(
            '01J00000000000000000000000',
            $publication->candidate->code,
            $publication->proofHash,
            $publication->release->gitSha,
        ));
    }
}
