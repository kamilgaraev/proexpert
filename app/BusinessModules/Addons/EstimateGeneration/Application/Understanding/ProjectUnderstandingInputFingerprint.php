<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Understanding;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use JsonException;

final class ProjectUnderstandingInputFingerprint
{
    /** @throws JsonException */
    public static function fromExactState(array $state): string
    {
        return hash('sha256', json_encode(
            self::normalize($state, true),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    /** @throws JsonException */
    public static function fromSnapshot(ProjectModelSnapshot $snapshot): string
    {
        $records = static function (array $items): array {
            usort($items, static fn (object $left, object $right): int => $left->id <=> $right->id);

            return array_map(
                static fn (object $item): array => self::normalize(get_object_vars($item)),
                $items,
            );
        };

        return hash('sha256', json_encode([
            'entities' => $records($snapshot->entities),
            'facts' => $records($snapshot->facts),
            'evidence' => $records($snapshot->evidence),
            'conflicts' => $records($snapshot->conflicts),
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function normalize(array $value, bool $sortLists = false): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::normalize($item, $sortLists);
            }
        }
        if ($sortLists && array_is_list($value)) {
            usort($value, static fn (mixed $left, mixed $right): int => json_encode($left, JSON_THROW_ON_ERROR)
                <=> json_encode($right, JSON_THROW_ON_ERROR));
        }

        return $value;
    }
}
