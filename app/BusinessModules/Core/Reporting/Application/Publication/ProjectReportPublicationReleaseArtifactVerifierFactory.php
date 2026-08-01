<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Core\Reporting\Domain\Contracts\ReportPublicationReleaseArtifactVerifier;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use RuntimeException;

final class ProjectReportPublicationReleaseArtifactVerifierFactory
{
    public function create(): ReportPublicationReleaseArtifactVerifier
    {
        $path = dirname(__DIR__, 2).'/resources/report-publication-release-authorities.v1.json';
        $bytes = file_get_contents($path);
        if (! is_string($bytes)) {
            throw new RuntimeException('report_publication_release_authorities_unavailable');
        }
        $authorities = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($authorities)
            || array_is_list($authorities)
            || ! hash_equals(CanonicalJson::encode($authorities)."\n", $bytes)) {
            throw new RuntimeException('report_publication_release_authorities_invalid');
        }

        return new Ed25519ReportPublicationReleaseArtifactVerifier($authorities);
    }
}
