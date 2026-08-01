<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationReleaseArtifactVerifier;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationReleaseBundle;
use RuntimeException;

final class ReportPublicationReleaseBundleWriter
{
    private ReportPublicationReleaseBundleFileLoader $bundles;

    private ReportPublicationReleaseArtifactVerifier $artifacts;

    public function __construct(
        ?ReportPublicationReleaseBundleFileLoader $bundles = null,
        ?ReportPublicationReleaseArtifactVerifier $artifacts = null,
    ) {
        $this->bundles = $bundles ?? new ReportPublicationReleaseBundleFileLoader;
        $this->artifacts = $artifacts ?? (new ProjectReportPublicationReleaseArtifactVerifierFactory)->create();
    }

    public function write(ReportPublicationReleaseBundle $bundle, string $outputDirectory): void
    {
        if (preg_match('/^report-publication-[a-z][a-z0-9_]*-[a-f0-9]{64}$/D', $bundle->artifactName) !== 1) {
            throw new RuntimeException('report_publication_release_output_unavailable');
        }
        if (! is_dir($outputDirectory)
            && ! mkdir($outputDirectory, 0700, true)
            && ! is_dir($outputDirectory)) {
            throw new RuntimeException('report_publication_release_output_unavailable');
        }
        $proofPath = $outputDirectory.DIRECTORY_SEPARATOR.$bundle->artifactName.'.proof.json';
        $artifactPath = $outputDirectory.DIRECTORY_SEPARATOR.$bundle->artifactName.'.json';
        $this->writeExclusive($proofPath, $bundle->proof->canonicalBytes());
        try {
            $this->writeExclusive($artifactPath, $bundle->artifactBytes);
            $this->reverify($bundle, $proofPath, $artifactPath, $outputDirectory);
        } catch (\Throwable $exception) {
            @unlink($proofPath);
            @unlink($artifactPath);

            throw $exception;
        }
    }

    private function writeExclusive(string $path, string $bytes): void
    {
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new RuntimeException(
                is_file($path)
                    ? 'report_publication_release_output_exists'
                    : 'report_publication_release_output_unavailable',
            );
        }
        $written = false;
        try {
            if (fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle)) {
                throw new RuntimeException('report_publication_release_output_unavailable');
            }
            $written = true;
        } finally {
            fclose($handle);
            if (! $written) {
                @unlink($path);
            }
        }
    }

    private function reverify(
        ReportPublicationReleaseBundle $expected,
        string $proofPath,
        string $artifactPath,
        string $outputDirectory,
    ): void {
        $serialized = $this->bundles->load($proofPath, $artifactPath, $outputDirectory);
        if (! hash_equals($expected->proof->canonicalBytes(), $serialized->proof->canonicalBytes())
            || ! hash_equals($expected->artifactBytes, $serialized->artifactBytes)
            || ! hash_equals($expected->artifactName, $serialized->artifactName)) {
            throw new RuntimeException('report_publication_release_output_unavailable');
        }
        $this->artifacts->verify($serialized->artifactBytes);
    }
}
