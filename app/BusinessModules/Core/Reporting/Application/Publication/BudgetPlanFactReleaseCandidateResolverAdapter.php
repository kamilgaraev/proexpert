<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Publication;

use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactCandidateContract;
use App\BusinessModules\Features\Budgeting\Reporting\BudgetPlanFactReleaseCandidateResolver;
use InvalidArgumentException;

final readonly class BudgetPlanFactReleaseCandidateResolverAdapter implements ReportPublicationReleaseCandidateResolver
{
    public function __construct(private BudgetPlanFactReleaseCandidateResolver $resolver) {}

    public function resolve(string $trustedDirectory, ReportPublicationReleaseRequest $request): array
    {
        if ($request->code !== BudgetPlanFactCandidateContract::CODE) {
            throw new InvalidArgumentException('report_publication_release_request_untrusted');
        }

        return $this->resolver->resolve($trustedDirectory, $request->commitSha);
    }
}
