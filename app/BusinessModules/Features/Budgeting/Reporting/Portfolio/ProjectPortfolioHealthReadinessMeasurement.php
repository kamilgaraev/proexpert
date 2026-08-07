<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

final readonly class ProjectPortfolioHealthReadinessMeasurement
{
    /** @param list<ProjectPortfolioHealthSourceGap> $gaps */
    public function __construct(public array $gaps) {}

    public function eligible(): array
    {
        return array_map(static fn (string $kind): array => ['kind' => $kind], ProjectPortfolioHealthSourceTupleAssembler::REQUIRED_KINDS);
    }

    public function projected(): array
    {
        $gapped = array_fill_keys(array_map(static fn (ProjectPortfolioHealthSourceGap $gap): string => $gap->kind, $this->gaps), true);

        return array_values(array_filter($this->eligible(), static fn (array $item): bool => ! isset($gapped[$item['kind']])));
    }

    public function gapCount(): int
    {
        return count($this->eligible()) - count($this->projected());
    }
}
