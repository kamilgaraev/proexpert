<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel;

use InvalidArgumentException;

final class ProjectModelInvariant
{
    public static function scope(int $organizationId, int $projectId, int $sessionId, string $sourceVersion): void
    {
        if ($organizationId <= 0 || $projectId <= 0 || $sessionId <= 0
            || preg_match('/^sha256:[a-f0-9]{64}$/D', $sourceVersion) !== 1) {
            throw new InvalidArgumentException('Project model scope is invalid.');
        }
    }

    public static function id(string $id, string $label): void
    {
        if (preg_match('/^[a-z][a-z0-9._:-]{1,191}$/D', $id) !== 1) {
            throw new InvalidArgumentException($label.' identifier is invalid.');
        }
    }

    public static function sameScope(object $left, object $right): void
    {
        foreach (['organizationId', 'projectId', 'sessionId', 'sourceVersion'] as $property) {
            if ($left->{$property} !== $right->{$property}) {
                throw new InvalidArgumentException('Project model records do not share an exact scope.');
            }
        }
    }

    public static function confidence(float $confidence): void
    {
        if (! is_finite($confidence) || $confidence < 0 || $confidence > 1) {
            throw new InvalidArgumentException('Project model confidence is invalid.');
        }
    }

    public static function uniqueIds(array $ids, string $label, bool $allowEmpty = false): array
    {
        if (! array_is_list($ids) || (! $allowEmpty && $ids === [])) {
            throw new InvalidArgumentException($label.' identifiers are invalid.');
        }
        foreach ($ids as $id) {
            if (! is_string($id)) {
                throw new InvalidArgumentException($label.' identifier is invalid.');
            }
            self::id($id, $label);
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_STRING);

        return $ids;
    }
}
