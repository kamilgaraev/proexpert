<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel;

/** A source classification is metadata, never proof of physical identity or size. */
final class SourceDocumentRole
{
    public static function normalize(mixed $role): ?string
    {
        if (! is_string($role)) {
            return null;
        }

        return match ($role) {
            'floor_plan', 'plan' => 'plan',
            'elevation', 'facade' => 'facade',
            'room_schedule', 'explication', 'section', 'equipment_plan', 'equipment_scheme',
            'equipment_specification', 'finish_schedule' => $role,
            default => null,
        };
    }
}
