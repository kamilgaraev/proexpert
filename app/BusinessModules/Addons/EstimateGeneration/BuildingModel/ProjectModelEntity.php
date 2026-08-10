<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use InvalidArgumentException;
use JsonException;

final readonly class ProjectModelEntity
{
    public const KINDS = [
        'room',
        'wall',
        'opening',
        'dimension',
        'material',
        'equipment',
        'quantity',
        'table',
        'structural_element',
    ];

    public function __construct(
        public int $buildingModelId,
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public string $sourceVersion,
        public string $stableKey,
        public string $kind,
        public array $payload,
        public ?float $confidence = null,
    ) {
        if ($buildingModelId < 1) {
            throw new InvalidArgumentException('Project model building model identifier must be positive.');
        }
        self::assertScope($organizationId, $projectId, $sessionId, $sourceVersion);
        self::assertStableKey($stableKey, 'Entity');
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('Project model entity kind is invalid.');
        }
        self::assertEntityPayload($kind, $stableKey, $payload);
        self::assertConfidence($confidence);
    }

    public static function assertScope(int $organizationId, int $projectId, int $sessionId, string $sourceVersion): void
    {
        if ($organizationId < 1 || $projectId < 1 || $sessionId < 1) {
            throw new InvalidArgumentException('Project model scope identifiers must be positive.');
        }
        if (preg_match('/^sha256:[a-f0-9]{64}$/', $sourceVersion) !== 1) {
            throw new InvalidArgumentException('Project model source version is invalid.');
        }
    }

    public static function assertStableKey(string $stableKey, string $subject): void
    {
        if (preg_match('/^[a-z][a-z0-9:_-]{0,191}$/', $stableKey) !== 1) {
            throw new InvalidArgumentException("{$subject} stable key is invalid.");
        }
    }

    public static function assertObject(array $value, string $subject): void
    {
        if (array_is_list($value)) {
            throw new InvalidArgumentException("{$subject} must be an object.");
        }

        try {
            json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException("{$subject} is not JSON serializable.");
        }
    }

    public static function assertEntityPayload(string $kind, string $stableKey, array $payload): void
    {
        self::assertObject($payload, 'Entity payload');
        if (($payload['kind'] ?? null) !== $kind || ($payload['key'] ?? null) !== $stableKey) {
            throw new InvalidArgumentException('Project model entity identity is invalid.');
        }

        $hasPositiveNumber = static fn (string $key): bool => (is_int($payload[$key] ?? null) || is_float($payload[$key] ?? null))
            && is_finite((float) $payload[$key])
            && $payload[$key] > 0;
        $hasUnitValue = static fn (): bool => $hasPositiveNumber('value')
            && is_string($payload['unit'] ?? null)
            && in_array($payload['unit'], ['m', 'm2', 'm3', 'pcs', 'kg', 't', 'h'], true);
        $isPoint = static fn (mixed $point): bool => is_array($point)
            && array_is_list($point)
            && count($point) === 2
            && array_reduce($point, static fn (bool $valid, mixed $coordinate): bool => $valid
                && (is_int($coordinate) || is_float($coordinate))
                && is_finite((float) $coordinate), true);
        $isPolygon = static fn (mixed $polygon): bool => is_array($polygon)
            && array_is_list($polygon)
            && count($polygon) >= 3
            && array_reduce($polygon, static fn (bool $valid, mixed $point): bool => $valid && $isPoint($point), true);

        $valid = match ($kind) {
            'room' => $isPolygon($payload['polygon'] ?? null)
                || $hasPositiveNumber('area_m2'),
            'wall' => $isPoint($payload['start'] ?? null) && $isPoint($payload['end'] ?? null),
            'opening' => is_string($payload['wall_key'] ?? null)
                && in_array($payload['type'] ?? null, ['door', 'window', 'gate'], true)
                && $hasPositiveNumber('width_m') && $hasPositiveNumber('height_m'),
            'dimension', 'quantity' => $hasUnitValue(),
            'material' => is_string($payload['name'] ?? null) && trim($payload['name']) !== '',
            'equipment' => is_string($payload['position'] ?? null) && trim($payload['position']) !== '',
            'table' => is_array($payload['columns'] ?? null)
                && array_is_list($payload['columns'])
                && $payload['columns'] !== []
                && array_reduce($payload['columns'], static fn (bool $valid, mixed $column): bool => $valid && is_string($column) && trim($column) !== '', true)
                && is_array($payload['rows'] ?? null)
                && array_is_list($payload['rows'])
                && array_reduce($payload['rows'], static fn (bool $valid, mixed $row): bool => $valid && is_array($row) && ! array_is_list($row), true),
            'structural_element' => is_string($payload['type'] ?? null) && trim($payload['type']) !== ''
                && ($isPoint($payload['location'] ?? null) || $hasPositiveNumber('length_m')),
            default => false,
        };
        if (! $valid) {
            throw new InvalidArgumentException('Project model entity payload is incomplete for its kind.');
        }
    }

    public static function assertConfidence(?float $confidence): void
    {
        if ($confidence !== null && (! is_finite($confidence) || $confidence < 0 || $confidence > 1)) {
            throw new InvalidArgumentException('Project model confidence is invalid.');
        }
    }
}
