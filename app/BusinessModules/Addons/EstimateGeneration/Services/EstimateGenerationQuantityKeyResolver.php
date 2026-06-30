<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

final class EstimateGenerationQuantityKeyResolver
{
    /**
     * @param array<string, mixed> $takeoff
     */
    public static function fromTakeoff(array $takeoff): string
    {
        $payload = is_array($takeoff['normalized_payload'] ?? null) ? $takeoff['normalized_payload'] : [];

        return (string) (
            $payload['quantity_key']
            ?? $takeoff['quantity_key']
            ?? self::fromTakeoffScope((string) ($takeoff['scope_key'] ?? ''))
        );
    }

    public static function fromTakeoffScope(string $scopeKey): string
    {
        return match ($scopeKey) {
            'room_area' => 'finish.floor',
            'floor_finish_area' => 'finish.floor',
            'rough_floor_area' => 'rough.floor',
            'ceiling_finish_area' => 'office.ceiling',
            'wall_finish_area' => 'rough.walls',
            'paint_area' => 'finish.paint',
            'wet_zone_tile_area' => 'sanitary.tile',
            'skirting_length' => 'finish.baseboard',
            'door_count' => 'openings.doors',
            'window_count' => 'openings.windows',
            'opening_count' => 'openings.doors',
            'plumbing_route_length' => 'plumbing.pipe',
            'water_supply_route_length' => 'plumbing.pipe',
            'sewerage_route_length' => 'sewerage.pipe',
            'heating_route_length' => 'heating.pipe',
            'radiator_count' => 'heating.radiators',
            'heating_unit_count' => 'heating.unit',
            'engineering_route_length' => 'plumbing.pipe',
            default => '',
        };
    }
}
