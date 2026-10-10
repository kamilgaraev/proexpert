<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use InvalidArgumentException;

final readonly class AiSessionWireScope
{
    public function __construct(public int $stateVersion, public ?string $generationAttemptId = null)
    {
        if ($stateVersion < 0 || ($generationAttemptId !== null
            && preg_match('/^[A-Za-z0-9:_-]{1,160}$/D', $generationAttemptId) !== 1)) {
            throw new InvalidArgumentException('ai_session_wire_scope_invalid');
        }
    }

    public function toArray(): array
    {
        return ['state_version' => $this->stateVersion, 'generation_attempt_id' => $this->generationAttemptId];
    }
}
