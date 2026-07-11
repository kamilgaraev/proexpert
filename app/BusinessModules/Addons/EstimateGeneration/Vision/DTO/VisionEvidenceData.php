<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\DTO;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Exceptions\VisionContractException;

final readonly class VisionEvidenceData
{
    /** @param array{page: int} $locator */
    public function __construct(public string $key, public array $locator)
    {
        if (preg_match('/^[a-z0-9][a-z0-9._:-]{0,79}$/', $key) !== 1
            || array_keys($locator) !== ['page'] || $locator['page'] < 1 || $locator['page'] > 10_000) {
            throw new VisionContractException('invalid_evidence');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (! self::hasExactKeys($data, ['key', 'locator']) || ! is_string($data['key']) || ! is_array($data['locator'])
            || ! isset($data['locator']['page']) || ! is_int($data['locator']['page'])) {
            throw new VisionContractException('invalid_evidence');
        }

        return new self($data['key'], ['page' => $data['locator']['page']]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['key' => $this->key, 'locator' => $this->locator];
    }

    /** @param array<string, mixed> $data @param list<string> $keys */
    private static function hasExactKeys(array $data, array $keys): bool
    {
        return count($data) === count($keys) && array_diff(array_keys($data), $keys) === [];
    }
}
