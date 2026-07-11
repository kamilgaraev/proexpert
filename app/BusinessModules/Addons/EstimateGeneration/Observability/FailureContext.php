<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use InvalidArgumentException;

final readonly class FailureContext
{
    public function __construct(
        public int $organizationId,
        public int $projectId,
        public int $sessionId,
        public ProcessingStage $stage,
        public string $operation,
        public int $attempt,
        public string $correlationId,
        public string $eventId,
        public ?int $expectedSessionStateVersion = null,
        public ?string $expectedSessionStatus = null,
        public ?int $documentId = null,
        public ?int $pageId = null,
        public ?int $unitId = null,
        public ?int $checkpointId = null,
        public ?string $usageAttemptId = null,
        public ?string $provider = null,
        public ?string $model = null,
    ) {
        foreach ([$organizationId, $projectId, $sessionId, $attempt] as $value) {
            if ($value < 1) {
                throw new InvalidArgumentException('Failure scope identifiers and attempt must be positive.');
            }
        }
        foreach ([$documentId, $pageId, $unitId, $checkpointId] as $value) {
            if ($value !== null && $value < 1) {
                throw new InvalidArgumentException('Optional failure scope identifiers must be positive.');
            }
        }
        if (preg_match('/\A[a-z][a-z0-9_]{0,39}\z/', $operation) !== 1) {
            throw new InvalidArgumentException('Invalid failure operation.');
        }
        if (! self::isUuid($correlationId) || ! self::isUuid($eventId) || ($usageAttemptId !== null && ! self::isUuid($usageAttemptId))) {
            throw new InvalidArgumentException('Invalid failure correlation identifier.');
        }
        if ($provider !== null && preg_match('/\A[a-z0-9._-]{1,80}\z/', $provider) !== 1) {
            throw new InvalidArgumentException('Invalid failure provider.');
        }
        if ($model !== null && preg_match('/\A[A-Za-z0-9._\/-]{1,160}\z/', $model) !== 1) {
            throw new InvalidArgumentException('Invalid failure model.');
        }
        if (($pageId !== null || $unitId !== null) && $documentId === null) {
            throw new InvalidArgumentException('Failure page and unit scopes require a document.');
        }
        if (($expectedSessionStateVersion === null) !== ($expectedSessionStatus === null)
            || ($expectedSessionStateVersion !== null && $expectedSessionStateVersion < 0)
            || ($expectedSessionStatus !== null && preg_match('/\A[a-z][a-z0-9_]{0,39}\z/', $expectedSessionStatus) !== 1)) {
            throw new InvalidArgumentException('Failure workflow fence is incomplete or invalid.');
        }
    }

    private static function isUuid(string $value): bool
    {
        return preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $value) === 1
            && strtolower($value) !== '00000000-0000-0000-0000-000000000000';
    }
}
