<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationNotificationService;
use DateTimeImmutable;

final readonly class DeliverFinalization
{
    public function __construct(
        private FinalizationOutbox $outbox,
        private EstimateGenerationNotificationService $notifications,
    ) {}

    public function one(DateTimeImmutable $now): bool
    {
        $claim = $this->outbox->claim($now, $now->modify('+5 minutes'));
        if ($claim === null) {
            return false;
        }
        $session = EstimateGenerationSession::query()
            ->whereKey($claim->event->sessionId)
            ->where('organization_id', $claim->event->organizationId)
            ->where('project_id', $claim->event->projectId)
            ->first();
        if (! $session instanceof EstimateGenerationSession
            || ! hash_equals($claim->event->generationAttemptId, (string) ($session->input_payload['generation_attempt_id'] ?? ''))) {
            return $this->outbox->complete($claim, $now);
        }
        if (! $this->notifications->notifyFinished($session, $claim->event->idempotencyKey)) {
            $delay = min(3600, 60 * (2 ** min(5, $claim->attempt - 1)));
            $this->outbox->release($claim, $now->modify('+'.$delay.' seconds'));

            return false;
        }

        return $this->outbox->complete($claim, $now);
    }
}
