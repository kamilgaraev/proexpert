<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use InvalidArgumentException;

final readonly class ReportPublicationReleaseDispatchProfile
{
    /** @param array{candidate_manifest: string, conformance_evidence: string, proof_template: string} $artifactPaths */
    public function __construct(
        public string $code,
        public string $requestId,
        public array $artifactPaths,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $code) !== 1
            || preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $requestId) !== 1
            || array_keys($artifactPaths) !== ['candidate_manifest', 'conformance_evidence', 'proof_template']) {
            throw new InvalidArgumentException('report_publication_release_profile_invalid');
        }
        foreach ($artifactPaths as $path) {
            if (! is_string($path) || preg_match('/^[a-z][a-z0-9_-]{1,127}\\.json$/D', $path) !== 1) {
                throw new InvalidArgumentException('report_publication_release_profile_invalid');
            }
        }
    }

    public function requestFileName(): string
    {
        return $this->requestId.'.json';
    }

    public function artifactName(string $proofSha256): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $proofSha256) !== 1) {
            throw new InvalidArgumentException('report_publication_release_profile_invalid');
        }

        return 'report-publication-'.$this->code.'-'.$proofSha256;
    }

    public function assertRequest(ReportPublicationReleaseRequest $request): void
    {
        if (! hash_equals($this->code, $request->code)
            || ! hash_equals($this->requestId, $request->requestId)
            || $request->artifactPaths !== $this->artifactPaths) {
            throw new InvalidArgumentException('report_publication_release_request_untrusted');
        }
    }
}
