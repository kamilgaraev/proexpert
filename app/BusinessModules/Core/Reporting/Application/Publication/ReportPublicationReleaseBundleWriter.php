<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationReleaseBundle;
use RuntimeException;

final class ReportPublicationReleaseBundleWriter
{
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
        } catch (\Throwable $exception) {
            @unlink($proofPath);

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
}
