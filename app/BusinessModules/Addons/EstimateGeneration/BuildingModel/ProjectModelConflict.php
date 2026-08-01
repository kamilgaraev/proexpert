<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use InvalidArgumentException;

final readonly class ProjectModelConflict
{
    public function __construct(
        public string $entityStableKey,
        public string $assertionType,
        public string $code,
        public array $candidateStableKeys,
        public array $values = [],
    ) {
        ProjectModelEntity::assertStableKey($entityStableKey, 'Conflict entity');
        ProjectModelResolvedValue::assertAssertionType($assertionType);
        if (! in_array($code, [
            'area_conflict',
            'area_unconfirmed',
            'dimension_conflict',
            'dimension_unconfirmed',
            'room_purpose_conflict',
            'room_purpose_unconfirmed',
            'opening_conflict',
            'opening_unconfirmed',
        ], true)) {
            throw new InvalidArgumentException('Project model conflict code is invalid.');
        }
        if (! array_is_list($candidateStableKeys) || $candidateStableKeys === []) {
            throw new InvalidArgumentException('Conflict candidates are invalid.');
        }
        foreach ($candidateStableKeys as $candidateStableKey) {
            if (! is_string($candidateStableKey)) {
                throw new InvalidArgumentException('Conflict candidate is invalid.');
            }
            ProjectModelEntity::assertStableKey($candidateStableKey, 'Conflict candidate');
        }
        $keys = array_values(array_unique($candidateStableKeys));
        sort($keys, SORT_STRING);
        if ($keys !== $candidateStableKeys) {
            throw new InvalidArgumentException('Conflict candidates must be unique and ordered.');
        }
        if (! array_is_list($values)) {
            throw new InvalidArgumentException('Conflict values are invalid.');
        }
        foreach ($values as $value) {
            if (! is_array($value)) {
                throw new InvalidArgumentException('Conflict value is invalid.');
            }
            ProjectModelEntity::assertObject($value, 'Conflict value');
        }
        if ((str_ends_with($code, '_conflict')) !== ($values !== [])) {
            throw new InvalidArgumentException('Conflict values do not match conflict state.');
        }
    }
}
