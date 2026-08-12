<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue;

use App\BusinessModules\Addons\EstimateGeneration\Infrastructure\Dialogue\EstimateChangeProposalRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class PreviewEstimateChange
{
    public function __construct(
        private EstimateChangeProposalRepository $proposals,
        private EstimateChangeSimulation $calculator,
    ) {}

    public function handle(EstimateGenerationSession $session, int $actorId, string $command, string $idempotencyKey, EstimateCommandInterpretation $interpretation): EstimateChangeProposal
    {
        $fingerprint = hash('sha256', json_encode([
            'command' => $command,
            'context_fingerprint' => $interpretation->payload['context_fingerprint'] ?? null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $existing = $this->proposals->findByIdempotency((int) $session->organization_id, (int) $session->id, $idempotencyKey);
        if ($existing !== null) {
            if (! hash_equals((string) $existing->payload['payload_fingerprint'], $fingerprint)) {
                throw new RuntimeException('estimate_generation.proposal_idempotency_collision');
            }

            return $existing;
        }
        $payload = $interpretation->payload;
        $calculation = $this->calculator->calculate($session, $interpretation);
        $affected = $calculation['affected'];
        if (count($affected) > 5000) {
            throw new RuntimeException('estimate_generation.proposal_too_large');
        }
        $known = $calculation['state'] === 'known';
        $cost = $known ? $calculation['delta'] : null;
        $proposal = [
            'id' => (string) Str::uuid(), 'organization_id' => (int) $session->organization_id,
            'project_id' => (int) $session->project_id, 'session_id' => (int) $session->id, 'actor_id' => $actorId,
            'idempotency_key' => $idempotencyKey, 'payload_fingerprint' => $fingerprint,
            'intent' => $interpretation->kind(), 'interpretation_version' => (string) $payload['version'],
            'command_excerpt' => $this->redact($command), 'before_payload' => $this->boundedMap($payload['before'] ?? []),
            'after_payload' => $this->boundedMap($payload['after'] ?? []), 'affected_payload' => ['count' => count($affected), 'preview_count' => min(count($affected), 100)],
            'dependency_keys' => $this->boundedList($payload['dependency_keys'] ?? [], 1000),
            'assumptions' => $this->boundedList([
                ...(is_array($payload['assumptions'] ?? null) ? $payload['assumptions'] : []),
                ...(is_array($calculation['assumptions'] ?? null) ? $calculation['assumptions'] : []),
            ], 100),
            'questions' => $this->boundedList([
                ...(is_array($payload['questions'] ?? null) ? $payload['questions'] : []),
                ...(is_array($calculation['risks'] ?? null) ? $calculation['risks'] : []),
            ], 100),
            'evidence' => array_slice(is_array($payload['evidence'] ?? null) ? $payload['evidence'] : [], 0, 100),
            'version_fence' => $calculation['version_fence'],
            'cost_state' => $calculation['state'], 'cost_blockers' => $calculation['blockers'],
            'cost_delta_known' => $known, 'cost_delta' => $cost,
            'simulation_fingerprint' => $calculation['fingerprint'],
            'simulation_input' => $payload,
            'expires_at' => now()->addMinutes(30), 'created_at' => now(),
        ];

        try {
            return DB::transaction(fn (): EstimateChangeProposal => $this->proposals->create($proposal, $affected), 3);
        } catch (QueryException $exception) {
            $winner = $this->proposals->findByIdempotency((int) $session->organization_id, (int) $session->id, $idempotencyKey);
            if ($winner !== null && hash_equals((string) $winner->payload['payload_fingerprint'], $fingerprint)) {
                return $winner;
            }
            throw $exception;
        }
    }

    private function redact(string $command): string
    {
        $command = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $command) ?? '';
        $command = preg_replace('/\b(?:sk|pk|api)[-_][A-Za-z0-9_-]{12,}\b/u', '[скрыто]', $command) ?? $command;

        return mb_substr(trim($command), 0, 1000);
    }

    /** @return array<string, mixed> */
    private function boundedMap(mixed $value): array
    {
        return is_array($value) ? array_slice($value, 0, 100, true) : [];
    }

    /** @return array<int, string> */
    private function boundedList(mixed $value, int $limit): array
    {
        return array_slice(array_values(array_filter(array_map('strval', is_array($value) ? $value : []), fn (string $item): bool => $item !== '')), 0, $limit);
    }
}
