<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureCategory;
use HashContext;

final class BoundedSourceVersionHasher
{
    public const MAX_ROWS = 10_000;

    public const MAX_BYTES = 6_291_456;

    private const MAX_INLINE_STRING_BYTES = 256;

    private int $bytes = 0;

    /** @var array<int, HashContext> */
    private array $hashes = [];

    public function assertCounts(int $total, int $maximum): void
    {
        if ($total > self::MAX_ROWS || $maximum > self::MAX_ROWS) {
            $this->tooLarge();
        }
    }

    public function start(int $documentId, array $projection): void
    {
        $this->hashes[$documentId] = hash_init('sha256');
        $this->update($documentId, $projection);
    }

    public function update(int $documentId, array $projection): void
    {
        if (! isset($this->hashes[$documentId])) {
            return;
        }
        $encoded = CanonicalPipelineJson::encode($this->boundedValue($projection));
        $this->bytes += strlen($encoded);
        if ($this->bytes > self::MAX_BYTES) {
            $this->tooLarge();
        }
        hash_update($this->hashes[$documentId], $encoded."\n");
    }

    /** @return array<int, string> */
    public function finish(): array
    {
        return array_map(static fn (HashContext $context): string => 'sha256:'.hash_final($context), $this->hashes);
    }

    private function boundedValue(mixed $value): mixed
    {
        if (is_string($value) && strlen($value) > self::MAX_INLINE_STRING_BYTES) {
            return [
                '__pipeline_bounded_string' => [
                    'bytes' => strlen($value),
                    'sha256' => hash('sha256', $value),
                ],
            ];
        }
        if (! is_array($value)) {
            return $value;
        }

        $bounded = [];
        foreach ($value as $key => $item) {
            $bounded[$key] = $this->boundedValue($item);
        }

        return $bounded;
    }

    private function tooLarge(): never
    {
        throw new PipelineStageException(FailureCategory::UserActionRequired, 'pipeline_source_too_large');
    }
}
