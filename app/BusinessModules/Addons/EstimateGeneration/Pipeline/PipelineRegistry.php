<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

final class PipelineRegistry
{
    /** @var list<PipelineStage> */
    private array $ordered;

    /** @var array<string, PipelineStage> */
    private array $byStage;

    /** @param iterable<PipelineStage> $stages */
    public function __construct(iterable $stages)
    {
        $byStage = [];

        foreach ($stages as $stage) {
            $key = $stage->stage()->value;

            if (isset($byStage[$key])) {
                throw DuplicatePipelineStage::for($stage->stage());
            }

            $byStage[$key] = $stage;
        }

        uasort(
            $byStage,
            static fn (PipelineStage $left, PipelineStage $right): int => $left->stage()->order() <=> $right->stage()->order(),
        );

        $this->byStage = $byStage;
        $this->ordered = array_values($byStage);
    }

    /** @return list<PipelineStage> */
    public function ordered(): array
    {
        return $this->ordered;
    }

    public function get(ProcessingStage $stage): ?PipelineStage
    {
        return $this->byStage[$stage->value] ?? null;
    }
}
