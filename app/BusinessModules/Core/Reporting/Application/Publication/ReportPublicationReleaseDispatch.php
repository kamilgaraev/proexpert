<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

final readonly class ReportPublicationReleaseDispatch
{
    public function __construct(
        public ReportPublicationReleaseDispatchProfile $profile,
        public ReportPublicationReleaseCandidateResolver $candidateResolver,
        public ReportPublicationReleaseBindingFactory $bindings,
    ) {}
}
