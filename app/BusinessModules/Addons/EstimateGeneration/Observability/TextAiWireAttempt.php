<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use App\Models\SystemAdmin;
use App\Models\User;
use Closure;
use InvalidArgumentException;

final readonly class TextAiWireAttempt
{
    public function __construct(
        public string $attemptId,
        public string $requestFingerprint,
        public AiCost $reservation,
        public int $leaseSeconds,
        public ?string $generationAttemptId = null,
        public ?int $stateVersion = null,
        public User|SystemAdmin|null $trustedActor = null,
        public string $permission = 'estimate_generation.generate',
        public ?Closure $beforeSend = null,
        public ?string $creditOperationId = null,
        public ?string $operation = null,
    ) {
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $attemptId) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $requestFingerprint) !== 1
            || $reservation->pricingStatus !== 'available'
            || $reservation->currency !== 'RUB'
            || ! is_string($reservation->amount)
            || preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/D', $reservation->amount) !== 1
            || $leaseSeconds < 1 || $leaseSeconds > 3600 || ($stateVersion !== null && $stateVersion < 0)
            || ! in_array($permission, ['estimate_generation.generate', 'estimate_generation.review'], true)
            || ($permission === 'estimate_generation.review' && ($trustedActor === null || $stateVersion === null))) {
            throw new InvalidArgumentException('text_ai_wire_attempt_invalid');
        }
        if ($creditOperationId !== null && (! \Illuminate\Support\Str::isUuid($creditOperationId)
            || ! in_array($operation, ['estimate_composition', 'estimate_audit', 'estimate_composer_correction',
                'project_synthesis', 'rerank', 'estimate_dialogue_interpretation', 'completeness_review', 'ocr'], true))) {
            throw new InvalidArgumentException('text_ai_credit_operation_invalid');
        }
    }
}
