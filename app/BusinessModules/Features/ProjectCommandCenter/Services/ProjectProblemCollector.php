<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProjectCommandCenter\Services;

use App\BusinessModules\Features\ProjectCommandCenter\DTO\ProjectProblemItem;
use App\Domain\Project\ValueObjects\ProjectContext;
use App\Models\Project;
use DateTimeInterface;

final class ProjectProblemCollector
{
    public function __construct(
        private readonly iterable $sources = [],
    ) {
    }

    public function collect(Project $project, ProjectContext $projectContext, DateTimeInterface $now): array
    {
        if ($project->getKey() !== $projectContext->projectId) {
            return $this->empty();
        }

        $items = [];

        foreach ($this->sources as $source) {
            if (! $source->isAvailable($projectContext)) {
                continue;
            }

            foreach ($source->collect($project, $projectContext) as $item) {
                $items[] = $item;
            }
        }

        usort($items, fn (ProjectProblemItem $left, ProjectProblemItem $right): int => $this->compare($left, $right, $now));

        return [
            'summary' => [
                'total' => count($items),
                'critical' => count(array_filter($items, static fn (ProjectProblemItem $item): bool => $item->severity === 'critical')),
                'risk' => count(array_filter($items, static fn (ProjectProblemItem $item): bool => $item->severity === 'risk')),
                'attention' => count(array_filter($items, static fn (ProjectProblemItem $item): bool => $item->severity === 'attention')),
            ],
            'items' => array_map(static fn (ProjectProblemItem $item): array => $item->toArray($projectContext->projectId), $items),
        ];
    }

    private function compare(ProjectProblemItem $left, ProjectProblemItem $right, DateTimeInterface $now): int
    {
        $bySeverity = $this->severityRank($left->severity) <=> $this->severityRank($right->severity);
        if ($bySeverity !== 0) {
            return $bySeverity;
        }

        $byOverdue = (int) $right->isOverdue($now) <=> (int) $left->isOverdue($now);
        if ($byOverdue !== 0) {
            return $byOverdue;
        }

        $byAmount = ($right->amount ?? 0.0) <=> ($left->amount ?? 0.0);
        if ($byAmount !== 0) {
            return $byAmount;
        }

        return $left->detectedAt <=> $right->detectedAt;
    }

    private function severityRank(string $severity): int
    {
        return match ($severity) {
            'critical' => 0,
            'risk' => 1,
            default => 2,
        };
    }

    private function empty(): array
    {
        return [
            'summary' => ['total' => 0, 'critical' => 0, 'risk' => 0, 'attention' => 0],
            'items' => [],
        ];
    }
}
