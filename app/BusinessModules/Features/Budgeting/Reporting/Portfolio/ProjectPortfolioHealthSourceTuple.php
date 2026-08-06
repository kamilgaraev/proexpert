<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

final readonly class ProjectPortfolioHealthSourceTuple
{
    /** @param list<ProjectPortfolioHealthSourceComponent> $components @param list<ProjectPortfolioHealthSourceGap> $gaps */
    public function __construct(public array $components, public array $gaps, public string $watermark) {}

    public function isReady(): bool
    {
        return $this->gaps === [] && count($this->components) === count(ProjectPortfolioHealthSourceTupleAssembler::REQUIRED_KINDS);
    }

    public function canonicalIdentity(): array
    {
        return [
            'components' => array_map(static fn (ProjectPortfolioHealthSourceComponent $component): array => $component->canonicalIdentity(), $this->components),
            'gaps' => array_map(static fn (ProjectPortfolioHealthSourceGap $gap): array => $gap->canonicalIdentity(), $this->gaps),
        ];
    }
}
