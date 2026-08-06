<?php

declare(strict_types=1);

namespace App\Services\CompletedWork\Reporting\AcceptedProduction\Options;

use InvalidArgumentException;

final readonly class AcceptedProductionOptionsSourceSnapshot
{
    /**
     * @param  list<int>  $workIds
     * @param  list<int>  $actIds
     * @param  list<int>  $contractorIds
     * @param  list<string>  $unitCodes
     * @param  list<string>  $zones
     * @param  list<string>  $statuses
     */
    private function __construct(
        public bool $available,
        public ?string $reason,
        public array $workIds,
        public array $actIds,
        public array $contractorIds,
        public array $unitCodes,
        public array $zones,
        public array $statuses,
    ) {}

    /**
     * @param  list<int>  $workIds
     * @param  list<int>  $actIds
     * @param  list<int>  $contractorIds
     * @param  list<string>  $unitCodes
     * @param  list<string>  $zones
     * @param  list<string>  $statuses
     */
    public static function available(
        array $workIds,
        array $actIds,
        array $contractorIds,
        array $unitCodes,
        array $zones,
        array $statuses,
    ): self {
        return new self(
            true,
            null,
            self::positiveIds($workIds),
            self::positiveIds($actIds),
            self::positiveIds($contractorIds),
            self::strings($unitCodes),
            self::strings($zones),
            self::strings($statuses),
        );
    }

    public static function unavailable(string $reason): self
    {
        if (! in_array($reason, ['source_incomplete', 'source_unavailable'], true)) {
            throw new InvalidArgumentException('accepted_production_options_reason_invalid');
        }

        return new self(false, $reason, [], [], [], [], [], []);
    }

    /**
     * @param  list<int>  $values
     * @return list<int>
     */
    private static function positiveIds(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            if (! is_int($value) || $value < 1) {
                throw new InvalidArgumentException('accepted_production_options_source_invalid');
            }
            $ids[$value] = $value;
        }
        $ids = array_values($ids);
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        $strings = [];
        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException('accepted_production_options_source_invalid');
            }
            $normalized = trim($value);
            $strings[$normalized] = $normalized;
        }
        $strings = array_values($strings);
        natcasesort($strings);

        return array_values($strings);
    }
}
