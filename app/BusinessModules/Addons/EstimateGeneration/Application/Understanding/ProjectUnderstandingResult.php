<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Understanding;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Conflict;
use InvalidArgumentException;

final readonly class ProjectUnderstandingResult
{
    public array $links;

    public array $conflicts;

    public array $questions;

    public array $limitations;

    public function __construct(
        array $links,
        array $conflicts,
        array $questions,
        array $limitations,
        public int $providerCalls,
    ) {
        if ($providerCalls < 0) {
            throw new InvalidArgumentException('Project understanding provider count is invalid.');
        }
        foreach ($conflicts as $conflict) {
            if (! $conflict instanceof Conflict) {
                throw new InvalidArgumentException('Project understanding conflict is invalid.');
            }
        }
        usort($links, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);
        usort($conflicts, static fn (Conflict $left, Conflict $right): int => $left->id <=> $right->id);
        usort($questions, static fn (array $left, array $right): int => $left['conflict_id'] <=> $right['conflict_id']);
        $limitations = array_values(array_unique($limitations));
        sort($limitations, SORT_STRING);
        $this->links = $links;
        $this->conflicts = $conflicts;
        $this->questions = $questions;
        $this->limitations = $limitations;
    }
}
