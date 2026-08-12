<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue;

use App\BusinessModules\Addons\EstimateGeneration\Infrastructure\Dialogue\EstimateChangeProposalRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use RuntimeException;

final readonly class InterpretEstimateCommand
{
    public function __construct(private EstimateCommandInterpreter $interpreter, private PreviewEstimateChange $preview, private EstimateChangeProposalRepository $proposals) {}

    /** @return array<string, mixed> */
    public function handle(EstimateGenerationSession $session, int $actorId, string $command, string $idempotencyKey): array
    {
        $fingerprint = hash('sha256', $command);
        $existing = $this->proposals->findByIdempotency((int) $session->organization_id, (int) $session->id, $idempotencyKey);
        if ($existing !== null) {
            if (! hash_equals((string) $existing->payload['payload_fingerprint'], $fingerprint)) {
                throw new RuntimeException('estimate_generation.proposal_idempotency_collision');
            }

            return ['kind' => 'proposal', 'proposal' => $existing->payload];
        }
        $interpretation = $this->interpreter->interpret($session, $actorId, $command);
        if ($interpretation->kind() === 'explain') {
            return ['kind' => 'explanation', 'explanation' => mb_substr((string) ($interpretation->payload['explanation'] ?? ''), 0, 8000), 'evidence' => array_slice(is_array($interpretation->payload['evidence'] ?? null) ? $interpretation->payload['evidence'] : [], 0, 100), 'read_only' => true];
        }

        return ['kind' => 'proposal', 'proposal' => $this->preview->handle($session, $actorId, $command, $idempotencyKey, $interpretation)->payload];
    }
}
