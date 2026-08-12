<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue;

use App\BusinessModules\Addons\EstimateGeneration\Infrastructure\Dialogue\EstimateChangeProposalRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class ApplyEstimateChangeProposal
{
    public function __construct(
        private EstimateChangeProposalRepository $proposals,
        private EstimateProposalVersionFence $versions,
        private EstimateProposalMutationExecutor $executor,
        private DeterministicEstimateChangePreview $preview = new DeterministicEstimateChangePreview,
    ) {}

    public function handle(User $actor, int $organizationId, int $projectId, int $sessionId, string $proposalId, int $expectedStateVersion): EstimateChangeProposal
    {
        try {
            return DB::transaction(function () use ($actor, $organizationId, $projectId, $sessionId, $proposalId, $expectedStateVersion): EstimateChangeProposal {
                $session = EstimateGenerationSession::query()->whereKey($sessionId)->where('organization_id', $organizationId)->where('project_id', $projectId)->lockForUpdate()->firstOrFail();
                $proposal = $this->proposals->find($proposalId, $organizationId, $projectId, $sessionId, true);
                if ($proposal->payload['status'] === 'applied') {
                    return $proposal;
                }
                if ($proposal->payload['status'] !== 'proposed') {
                    throw new RuntimeException('estimate_generation.proposal_terminal');
                }
                if (now()->greaterThanOrEqualTo($proposal->payload['expires_at'])) {
                    $this->proposals->transition($proposalId, 'proposed', 'expired', (int) $actor->id);

                    return $this->proposals->find($proposalId, $organizationId, $projectId, $sessionId);
                }
                if ((int) $session->state_version !== $expectedStateVersion || ! hash_equals($this->fenceHash($proposal->payload['version_fence']), $this->fenceHash($this->versions->capture($session)))) {
                    $this->proposals->transition($proposalId, 'proposed', 'stale', (int) $actor->id);

                    return $this->proposals->find($proposalId, $organizationId, $projectId, $sessionId);
                }
                $recalculated = $this->preview->calculate($session, new EstimateCommandInterpretation([
                    'kind' => (string) $proposal->payload['intent'],
                    'version' => (string) $proposal->payload['interpretation_version'],
                    'before' => $proposal->payload['before_payload'],
                    'after' => $proposal->payload['after_payload'],
                    'value' => $proposal->payload['after_payload']['value']['value'] ?? null,
                    'dependency_keys' => $proposal->payload['dependency_keys'],
                ]));
                if (($proposal->payload['cost_state'] ?? 'unknown') !== $recalculated['state']
                    || (($proposal->payload['cost_delta'] ?? null) !== $recalculated['delta'])) {
                    $this->proposals->transition($proposalId, 'proposed', 'stale', (int) $actor->id);

                    return $this->proposals->find($proposalId, $organizationId, $projectId, $sessionId);
                }
                if (! $this->proposals->transition($proposalId, 'proposed', 'applying', (int) $actor->id)) {
                    throw new RuntimeException('estimate_generation.proposal_concurrent');
                }
                $result = $this->executor->apply($actor, $session, $proposal);
                if (! $this->proposals->transition($proposalId, 'applying', 'applied', (int) $actor->id, $result)) {
                    throw new RuntimeException('estimate_generation.proposal_concurrent');
                }

                return $this->proposals->find($proposalId, $organizationId, $projectId, $sessionId);
            }, 3);
        } catch (\Throwable $exception) {
            try {
                DB::transaction(function () use ($actor, $organizationId, $projectId, $sessionId, $proposalId): void {
                    $proposal = $this->proposals->find($proposalId, $organizationId, $projectId, $sessionId, true);
                    if ($proposal->payload['status'] === 'proposed') {
                        $this->proposals->transition($proposalId, 'proposed', 'failed', (int) $actor->id, [], 'apply_failed');
                    }
                }, 3);
            } catch (\Throwable) {
            }
            throw $exception;
        }
    }

    private function fenceHash(mixed $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (! is_array($item)) {
                return $item;
            }
            foreach ($item as $key => $nested) {
                $item[$key] = $normalize($nested);
            }
            if (! array_is_list($item)) {
                ksort($item);
            }

            return $item;
        };

        return hash('sha256', json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
