<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;

final class RoomIdentityObservation
{
    public function name(ObservationClaim $claim): ?string
    {
        $name = $claim->value['data'] ?? null;
        if ($claim->factType !== 'room_name' || $claim->unit !== null
            || ($claim->value['type'] ?? null) !== 'string'
            || FactVocabulary::entityType($claim->entityKey) !== 'room'
            || ! is_string($name) || ! mb_check_encoding($name, 'UTF-8')) {
            return null;
        }
        $name = trim($name);

        return $name !== '' && mb_strlen($name) <= 240 && preg_match('/[\x00-\x1f\x7f]/u', $name) !== 1
            ? $name : null;
    }

    public function fingerprint(ObservationClaim $claim): ?string
    {
        $name = $this->name($claim);

        return $name === null ? null : hash('sha256', $name);
    }
}
