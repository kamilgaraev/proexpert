<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;

/** A printed identifier never confirms a room name, size, or physical equivalence. */
final class RoomNumberObservation
{
    public function number(ObservationClaim $claim): ?string
    {
        return $claim->factType === 'room_number' ? self::label($claim->entityKey, $claim->value, $claim->unit) : null;
    }

    public static function label(string $entityKey, array $value, ?string $unit): ?string
    {
        $number = $value['data'] ?? null;
        if ($unit !== null || ($value['type'] ?? null) !== 'string'
            || FactVocabulary::entityType($entityKey) !== 'room'
            || ! is_string($number) || ! mb_check_encoding($number, 'UTF-8')) {
            return null;
        }
        $number = trim($number);
        if (mb_strlen($number) > 64 || preg_match('/^(?:№ ?)?[\p{L}\p{N}][\p{L}\p{N} ._\/-]*$/uD', $number) !== 1) {
            return null;
        }

        return preg_match('/\p{N}/u', $number) === 1 || preg_match('/^\p{L}$/uD', $number) === 1 ? $number : null;
    }

    public function fingerprint(ObservationClaim $claim): ?string
    {
        $number = $this->number($claim);

        return $number === null ? null : hash('sha256', $number);
    }
}
