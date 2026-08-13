<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO;

use InvalidArgumentException;

final readonly class AiRoleRunResult
{
    public const MAX_PAYLOAD_BYTES = 262_144;

    /** @param array<string, mixed> $payload */
    public function __construct(
        public array $payload,
        public ?string $physicalAttemptId,
    ) {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > self::MAX_PAYLOAD_BYTES
            || ($physicalAttemptId !== null
                && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $physicalAttemptId) !== 1)) {
            throw new InvalidArgumentException('ai_role_run_result_invalid');
        }
    }
}
