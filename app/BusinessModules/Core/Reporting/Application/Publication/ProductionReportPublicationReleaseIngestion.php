<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationReleaseArtifactVerifier;
use App\BusinessModules\Core\Reporting\Domain\DTO\PublishedReportDefinition;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationFeatureMode;
use InvalidArgumentException;

final readonly class ProductionReportPublicationReleaseIngestion
{
    public function __construct(
        private ReportPublicationReleaseTrustedRoots $roots,
        private ReportPublicationReleaseRequestFileLoader $requests,
        private ReportPublicationReleaseRequestResolverFactory $resolvers,
        private ReportPublicationReleaseIngestor $ingestor,
        private ReportPublicationReleaseBundleFileLoader $bundles,
        private ReportPublicationReleaseArtifactVerifier $artifacts,
    ) {}

    /** @param int[] $organizationAllowlist @param int[] $userAllowlist */
    public function ingest(
        string $artifactName,
        ReportPublicationFeatureMode $mode = ReportPublicationFeatureMode::OFF,
        array $organizationAllowlist = [],
        array $userAllowlist = [],
    ): PublishedReportDefinition {
        $bundleRoot = $this->roots->bundleRoot;
        $candidateRoot = $this->roots->candidateRoot;
        if (preg_match('/^report-publication-[a-z][a-z0-9_]*-[a-f0-9]{64}$/D', $artifactName) !== 1) {
            $this->invalid();
        }
        $proofPath = $bundleRoot.DIRECTORY_SEPARATOR.$artifactName.'.proof.json';
        $artifactPath = $bundleRoot.DIRECTORY_SEPARATOR.$artifactName.'.json';
        $this->assertTrustedFile($proofPath, $bundleRoot);
        $this->assertTrustedFile($artifactPath, $bundleRoot);
        $bundle = $this->bundles->load($proofPath, $artifactPath, $bundleRoot);
        $this->artifacts->verify($bundle->artifactBytes);
        foreach ([
            'r15-candidate-manifest.json',
            'r15-conformance-evidence.json',
            'r15-proof-template.json',
            'r15_release_request.json',
        ] as $requiredFile) {
            $this->assertTrustedFile($candidateRoot.DIRECTORY_SEPARATOR.$requiredFile, $candidateRoot);
        }
        $request = $this->requests->load($candidateRoot.DIRECTORY_SEPARATOR.'r15_release_request.json', $candidateRoot);
        $resolved = $this->resolvers->create($candidateRoot)->resolve($request);
        $resolved->admission->assertProductionSafe();

        return $this->ingestor->ingest(
            $proofPath,
            $artifactPath,
            $bundleRoot,
            $resolved->admission,
            $request->commitSha,
            $mode,
            $organizationAllowlist,
            $userAllowlist,
        );
    }

    private function assertTrustedFile(string $path, string $directory): void
    {
        $resolved = realpath($path);
        if (! is_string($resolved)
            || is_link($path)
            || ! is_file($resolved)
            || ! str_starts_with($resolved, $directory.DIRECTORY_SEPARATOR)) {
            $this->invalid();
        }
    }

    private function invalid(): never
    {
        throw new InvalidArgumentException('report_publication_release_input_invalid');
    }
}
