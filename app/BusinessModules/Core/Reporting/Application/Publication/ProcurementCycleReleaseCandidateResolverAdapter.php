<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Features\Procurement\Reporting\Cycle\CiEvidence\ProcurementCycleReleaseCandidateResolver;
use InvalidArgumentException;

final readonly class ProcurementCycleReleaseCandidateResolverAdapter implements ReportPublicationReleaseCandidateResolver
{
    public function __construct(private ProcurementCycleReleaseCandidateResolver $resolver) {}

    public function resolve(string $trustedDirectory, ReportPublicationReleaseRequest $request): array
    {
        if ($request->code !== 'procurement_cycle') {
            throw new InvalidArgumentException('report_publication_release_request_untrusted');
        }

        return $this->resolver->resolve($trustedDirectory, $request->commitSha);
    }
}
