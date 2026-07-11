<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use Closure;
use DomainException;

final readonly class PipelinePriorOutputs
{
    private ?Closure $loader;

    /** @param array<string, PipelineStageOutput> $outputs @param array<string, array<string, mixed>> $payloads */
    public function __construct(private array $outputs = [], private array $payloads = [], ?callable $loader = null)
    {
        $this->loader = $loader !== null ? Closure::fromCallable($loader) : null;
    }

    public function require(ProcessingStage $stage): PipelineStageOutput
    {
        $output = $this->outputs[$stage->value] ?? null;
        if (! $output instanceof PipelineStageOutput || $output->stage !== $stage) {
            throw new DomainException('Required pipeline stage output is unavailable.');
        }

        return $output;
    }

    public function get(ProcessingStage $stage): ?PipelineStageOutput
    {
        return $this->outputs[$stage->value] ?? null;
    }

    /** @return array<string, mixed> */
    public function payload(ProcessingStage $stage): array
    {
        $this->require($stage);
        $payload = $this->payloads[$stage->value] ?? null;

        if (is_array($payload)) {
            return $payload;
        }
        if ($this->loader !== null) {
            $loaded = ($this->loader)($this->require($stage));
            if (! is_array($loaded)) {
                throw new DomainException('Pipeline artifact loader returned invalid data.');
            }

            return PipelineStagePayload::from($stage, $loaded)->data;
        }

        throw new DomainException('Pipeline artifact payload requires an explicit loader.');
    }

    /** @return array<string, PipelineStageOutput> */
    public function all(): array
    {
        return $this->outputs;
    }
}
