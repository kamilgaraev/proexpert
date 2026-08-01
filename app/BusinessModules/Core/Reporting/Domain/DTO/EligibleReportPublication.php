<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\DTO;

use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;

final readonly class EligibleReportPublication
{
    public function __construct(
        public CandidateReportDefinition $candidate,
        public array $candidateDocument,
        public ReportPublicationProof $proof,
        public Sha256Hash $proofHash,
        public Sha256Hash $candidateManifestHash,
        public Sha256Hash $officialManifestHash,
        public ReportPublicationReleaseIdentity $release,
    ) {
        if (! hash_equals($candidate->code, $proof->payload()['code'])
            || ! hash_equals($proofHash->value, $proof->digest()->value)
            || ! hash_equals($candidateManifestHash->value, $proof->payload()['candidate_manifest_sha256'])
            || ! hash_equals($release->gitSha, $proof->payload()['release']['git_sha'])) {
            throw new InvalidArgumentException('eligible_report_publication_invalid');
        }
    }
}
