<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use InvalidArgumentException;

final readonly class AiSessionWireScope
{
    public function __construct(public int $stateVersion, public ?string $generationAttemptId = null, public ?string $creditOperationId = null)
    {
        if ($stateVersion < 0 || ($generationAttemptId !== null
            && preg_match('/^[A-Za-z0-9:_-]{1,160}$/D', $generationAttemptId) !== 1)) {
            throw new InvalidArgumentException('ai_session_wire_scope_invalid');
        }
        if ($creditOperationId !== null && ! \Illuminate\Support\Str::isUuid($creditOperationId)) {
            throw new InvalidArgumentException('ai_credit_wire_scope_invalid');
        }
    }

    public function toArray(): array
    {
        return ['state_version' => $this->stateVersion, 'generation_attempt_id' => $this->generationAttemptId,
            ...($this->creditOperationId === null ? [] : ['credit_operation_id' => $this->creditOperationId])];
    }
}
