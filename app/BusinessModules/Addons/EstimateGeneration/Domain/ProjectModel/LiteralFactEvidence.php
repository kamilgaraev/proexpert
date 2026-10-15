<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel;

use InvalidArgumentException;

final readonly class LiteralFactEvidence
{
    public function __construct(public string $factKey, public string $valueHash)
    {
        if (! in_array($factKey, ['room_name', 'room_number'], true)
            || preg_match('/\A[a-f0-9]{64}\z/', $valueHash) !== 1) {
            throw new InvalidArgumentException('literal_fact_evidence_invalid');
        }
    }

    /** @param array<string,mixed> $value */
    public static function fromSourceFactValue(array $value): ?self
    {
        $key = $value['fact_key'] ?? null;
        $hash = $value['fact_value'] ?? null;
        if (! in_array($key, ['room_name', 'room_number'], true) || ! is_string($hash)
            || preg_match('/\A[a-f0-9]{64}\z/', $hash) !== 1) {
            return null;
        }

        return new self($key, $hash);
    }
}
