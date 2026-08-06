<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

use InvalidArgumentException;

final readonly class ProjectPortfolioHealthSourceGap
{
    public function __construct(public string $code, public string $kind)
    {
        if (trim($code) === '' || ! in_array($kind, ProjectPortfolioHealthSourceTupleAssembler::REQUIRED_KINDS, true)) {
            throw new InvalidArgumentException('project_portfolio_health_source_gap_invalid');
        }
    }

    public function canonicalIdentity(): array
    {
        return ['code' => $this->code, 'kind' => $this->kind];
    }
}
