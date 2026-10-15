<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;

/** A literal location identifier is separate from dimensions and physical room identity. */
final class RoomLocationObservation
{
    public function label(ObservationClaim $claim): ?string
    {
        return self::value($claim->factType, $claim->entityKey, $claim->value, $claim->unit);
    }

    public static function value(string $type, string $entity, array $value, ?string $unit): ?string
    {
        $text = $value['data'] ?? null;
        if (! in_array($type, ['room_floor', 'room_zone'], true) || $unit !== null
            || FactVocabulary::entityType($entity) !== 'room' || ($value['type'] ?? null) !== 'string'
            || ! is_string($text) || ! mb_check_encoding($text, 'UTF-8')) {
            return null;
        }
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 120
            || preg_match('/^-?[\p{L}\p{N}][\p{L}\p{N} ._\/\-]*$/uD', $text) !== 1
            || in_array(mb_strtolower($text), ['unknown', 'неизвестно', 'не указан', 'не указана'], true)) {
            return null;
        }

        return $text;
    }
}
