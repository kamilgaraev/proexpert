<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel;

use InvalidArgumentException;
use JsonException;

final class ProjectUnderstandingFingerprint
{
    public const CONTRACT = 'project-understanding:jsonb:v2';

    public static function encode(array $payload): string
    {
        $payload['result_contract'] = self::CONTRACT;

        return self::canonicalJson($payload);
    }

    public static function hash(string $inputFingerprint, array $payload): string
    {
        $contract = $payload['result_contract'] ?? null;
        if ($contract !== null && $contract !== self::CONTRACT) {
            throw new InvalidArgumentException('Project understanding result contract is invalid.');
        }
        // Historical rows retain their original contract and checksum. New runs use
        // a different input identity, so an unverifiable legacy row is never reused.
        $encoded = $contract === self::CONTRACT ? self::canonicalJson($payload)
            : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return hash('sha256', $inputFingerprint."\0".$encoded);
    }

    public static function canonicalJson(array $payload): string
    {
        try {
            // Normalize public DTO properties before sorting keys. JSONB preserves
            // arrays, but reorders object keys and normalizes JSON number notation.
            $normalized = json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

            return DerivedQuantityIdentity::canonicalJson($normalized);
        } catch (JsonException) {
            throw new InvalidArgumentException('Project understanding result cannot be serialized.');
        }
    }
}
