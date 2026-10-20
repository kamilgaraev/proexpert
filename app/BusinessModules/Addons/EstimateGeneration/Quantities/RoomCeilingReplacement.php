<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Quantities;

final class RoomCeilingReplacement
{
    public const CONTRACT = 'room-ceiling-replacement:v1';

    public const WORKS = ['rough.ceiling', 'finish.ceiling', 'ceiling.brandering', 'ceiling.insulation', 'ceiling.cornice'];

    public static function work(array $item): ?string
    {
        $work = $item['metadata']['composition_work_key'] ?? $item['work_key'] ?? $item['metadata']['quantity_key'] ?? null;

        return is_string($work) && in_array($work, self::WORKS, true) ? $work : null;
    }

    public static function childKey(string $parentKey, string $roomId): string
    {
        return 'room-ceiling:'.hash('sha256', $parentKey."\0".$roomId);
    }

    public static function candidate(array $item): bool
    {
        return self::work($item) !== null && ($item['item_type'] ?? 'priced_work') === 'priced_work'
            && ! isset($item['metadata']['scope_entity_id']) && ! isset($item['entity_id'])
            && ! isset($item['metadata']['room_ceiling_replacement']);
    }

    public static function rows(array $draft): array
    {
        $rows = [];
        foreach ($draft['local_estimates'] ?? [] as $local) {
            foreach ($local['sections'] ?? [] as $section) {
                foreach ($section['work_items'] ?? [] as $item) {
                    $rows[] = $item;
                }
            }
        }

        return $rows;
    }

    public static function requiresProof(array $items, array $records): bool
    {
        if ($records !== []) {
            return true;
        }
        foreach ($items as $item) {
            if (isset($item['metadata']['room_ceiling_replacement']) || str_starts_with($item['key'] ?? '', 'room-ceiling:')) {
                return true;
            }
        }

        return false;
    }
}
