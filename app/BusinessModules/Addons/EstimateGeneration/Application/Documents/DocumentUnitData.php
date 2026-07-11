<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use InvalidArgumentException;

final readonly class DocumentUnitData
{
    public const MAX_INDEX = 100000;

    /** @param array<string, scalar|null> $locator */
    public function __construct(
        public DocumentUnitType $type,
        public int $index,
        public string $sourceVersion,
        public array $locator = [],
    ) {
        if ($index < 1 || $index > self::MAX_INDEX) {
            throw new InvalidArgumentException('Document unit index is outside the supported range.');
        }

        if ($sourceVersion === '' || strlen($sourceVersion) > 80) {
            throw new InvalidArgumentException('Document source version must contain at most 80 characters.');
        }
    }

    public function identity(): string
    {
        return sprintf('%s:%d:%s', $this->type->value, $this->index, $this->sourceVersion);
    }

    /**
     * @param  iterable<self>  $units
     * @return list<self>
     */
    public static function normalize(iterable $units): array
    {
        $normalized = [];

        foreach ($units as $unit) {
            $normalized[$unit->identity()] = $unit;
        }

        $units = array_values($normalized);
        usort($units, static fn (self $left, self $right): int => [
            $left->type->value,
            $left->index,
            $left->sourceVersion,
        ] <=> [
            $right->type->value,
            $right->index,
            $right->sourceVersion,
        ]);

        return $units;
    }
}
