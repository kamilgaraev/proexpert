<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use InvalidArgumentException;
use JsonException;

final readonly class ProjectModelEntity
{
    public const KINDS = ['room', 'wall', 'opening', 'dimension', 'table', 'structural_element', 'quantity'];

    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public string $sourceVersion,
        public string $stableKey,
        public string $kind,
        public array $payload,
        public array $evidence,
        public ?float $confidence = null,
    ) {
        self::assertScope($organizationId, $projectId, $sessionId, $sourceVersion);
        self::assertStableKey($stableKey, 'Entity');
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('Project model entity kind is invalid.');
        }
        self::assertObject($payload, 'Entity payload');
        if (($payload['kind'] ?? null) !== $kind) {
            throw new InvalidArgumentException('Project model entity payload kind is invalid.');
        }
        self::assertReferenceList($evidence, 'Entity evidence', true);
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

    public static function assertReferenceList(array $references, string $subject, bool $required = false): void
    {
        if (! array_is_list($references) || ($required && $references === [])) {
            throw new InvalidArgumentException("{$subject} must be a non-empty list.");
        }
        foreach ($references as $reference) {
            if (! is_string($reference) || preg_match('/^[a-z][a-z0-9:_-]{0,191}$/', $reference) !== 1) {
                throw new InvalidArgumentException("{$subject} contains an invalid reference.");
            }
        }
    }

    public static function assertConfidence(?float $confidence): void
    {
        if ($confidence !== null && (! is_finite($confidence) || $confidence < 0 || $confidence > 1)) {
            throw new InvalidArgumentException('Project model confidence is invalid.');
        }
    }
}
