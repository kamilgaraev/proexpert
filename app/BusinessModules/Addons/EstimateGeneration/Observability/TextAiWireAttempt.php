<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

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
    ) {
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $attemptId) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $requestFingerprint) !== 1
            || $reservation->pricingStatus !== 'available'
            || $reservation->currency !== 'RUB'
            || ! is_string($reservation->amount)
            || preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/D', $reservation->amount) !== 1
            || $leaseSeconds < 1 || $leaseSeconds > 3600 || ($stateVersion !== null && $stateVersion < 0)) {
            throw new InvalidArgumentException('text_ai_wire_attempt_invalid');
        }
    }
}
