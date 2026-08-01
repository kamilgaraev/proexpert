<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationProof;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationReleaseArtifact;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportPublicationReleaseBundle;
use InvalidArgumentException;

final class ReportPublicationReleaseBundleFileLoader
{
    public function load(string $proofPath, string $artifactPath, string $trustedDirectory): ReportPublicationReleaseBundle
    {
        $directory = realpath($trustedDirectory);
        if ($directory === false) {
            $this->invalid();
        }
        $proofBytes = $this->readTrusted($proofPath, $directory);
        $artifactBytes = $this->readTrusted($artifactPath, $directory);
        $proof = ReportPublicationProof::fromCanonicalBytes($proofBytes);
        $artifact = ReportPublicationReleaseArtifact::fromCanonicalBytes($artifactBytes);
        $artifactName = 'report-publication-'.$proof->payload()['code'].'-'.$proof->digest()->value;
        if (basename($proofPath) !== $artifactName.'.proof.json'
            || basename($artifactPath) !== $artifactName.'.json'
            || ! hash_equals($artifact->payload()['provenance']['artifact_name'], $artifactName)) {
            $this->invalid();
        }

        return new ReportPublicationReleaseBundle($proof, $artifactBytes, $artifactName);
    }

    private function readTrusted(string $path, string $directory): string
    {
        if (is_link($path)) {
            $this->invalid();
        }
        $resolved = realpath($path);
        if ($resolved === false
            || ! str_starts_with($resolved, $directory.DIRECTORY_SEPARATOR)
            || ! is_file($resolved)
            || is_link($resolved)) {
            $this->invalid();
        }
        $bytes = file_get_contents($resolved);
        if (! is_string($bytes)) {
            $this->invalid();
        }

        return $bytes;
    }

    private function invalid(): never
    {
        throw new InvalidArgumentException('report_publication_release_input_invalid');
    }
}
