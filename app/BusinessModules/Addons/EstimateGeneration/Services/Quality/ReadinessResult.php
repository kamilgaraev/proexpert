<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality;

use ArrayAccess;
use JsonSerializable;
use LogicException;

/** @implements ArrayAccess<string, mixed> */
final readonly class ReadinessResult implements ArrayAccess, JsonSerializable
{
    public function __construct(
        public string $status,
        public bool $canGenerate,
        public bool $canApply,
        public array $blockingIssues,
        public array $warnings,
        public array $metrics,
        public array $nextAction,
    ) {}

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'can_generate' => $this->canGenerate,
            'can_apply' => $this->canApply,
            'blocking_issues' => $this->blockingIssues,
            'blockers' => $this->blockingIssues,
            'warnings' => $this->warnings,
            'metrics' => $this->metrics,
            'next_action' => $this->nextAction,
        ];
    }

    public function jsonSerialize(): array { return $this->toArray(); }
    public function offsetExists(mixed $offset): bool { return array_key_exists((string) $offset, $this->toArray()); }
    public function offsetGet(mixed $offset): mixed { return $this->toArray()[(string) $offset] ?? null; }
    public function offsetSet(mixed $offset, mixed $value): void { throw new LogicException('estimate_generation.readiness_result_immutable'); }
    public function offsetUnset(mixed $offset): void { throw new LogicException('estimate_generation.readiness_result_immutable'); }
}
