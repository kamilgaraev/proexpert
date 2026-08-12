<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Infrastructure\Dialogue;

use App\BusinessModules\Addons\EstimateGeneration\Application\Dialogue\EstimateChangeProposal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class EstimateChangeProposalRepository
{
    /** @param array<string, mixed> $proposal @param array<int, array<string, mixed>> $items */
    public function create(array $proposal, array $items): EstimateChangeProposal
    {
        DB::table('estimate_change_proposals')->insert($this->encode($proposal, [
            'before_payload', 'after_payload', 'affected_payload', 'dependency_keys', 'assumptions', 'questions', 'evidence', 'version_fence',
        ]));
        DB::table('estimate_change_proposal_states')->insert([
            'proposal_id' => $proposal['id'], 'status' => 'proposed', 'version' => 1, 'updated_at' => now(),
        ]);
        DB::table('estimate_change_proposal_transitions')->insert([
            'proposal_id' => $proposal['id'], 'actor_id' => $proposal['actor_id'], 'from_status' => null,
            'to_status' => 'proposed', 'metadata' => '{}', 'created_at' => now(),
        ]);
        foreach (array_chunk(array_slice($items, 0, 5000), 250) as $chunk) {
            DB::table('estimate_change_proposal_items')->insert(array_map(fn (array $item): array => $this->encode([
                'proposal_id' => $proposal['id'], 'stable_key' => mb_substr((string) $item['stable_key'], 0, 255),
                'kind' => mb_substr((string) ($item['kind'] ?? 'estimate_row'), 0, 64),
                'before_payload' => $item['before'] ?? null, 'after_payload' => $item['after'] ?? null,
                'locator' => $item['locator'] ?? null,
            ], ['before_payload', 'after_payload', 'locator']), $chunk));
        }

        return $this->find((string) $proposal['id'], (int) $proposal['organization_id'], (int) $proposal['project_id'], (int) $proposal['session_id']);
    }

    public function find(string $id, int $organizationId, int $projectId, int $sessionId, bool $lock = false): EstimateChangeProposal
    {
        $query = DB::table('estimate_change_proposals as p')->join('estimate_change_proposal_states as s', 's.proposal_id', '=', 'p.id')
            ->where('p.id', $id)->where('p.organization_id', $organizationId)->where('p.project_id', $projectId)->where('p.session_id', $sessionId)
            ->select('p.*', 's.status', 's.version as status_version', 's.result', 's.failure_code', 's.applied_at', 's.cancelled_at', 's.updated_at');
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        if ($row === null) {
            throw new RuntimeException('estimate_generation.proposal_not_found');
        }

        return new EstimateChangeProposal($this->decode((array) $row));
    }

    public function findByIdempotency(int $organizationId, int $sessionId, string $key): ?EstimateChangeProposal
    {
        $id = DB::table('estimate_change_proposals')->where('organization_id', $organizationId)->where('session_id', $sessionId)->where('idempotency_key', $key)->value('id');
        if (! is_string($id)) {
            return null;
        }
        $scope = DB::table('estimate_change_proposals')->where('id', $id)->first(['project_id']);

        return $this->find($id, $organizationId, (int) $scope->project_id, $sessionId);
    }

    /** @return array<string, mixed> */
    public function items(string $proposalId, int $limit, ?int $afterId): array
    {
        $query = DB::table('estimate_change_proposal_items')->where('proposal_id', $proposalId)->orderBy('id')->limit($limit + 1);
        if ($afterId !== null) {
            $query->where('id', '>', $afterId);
        }
        $rows = $query->get()->map(fn ($row): array => $this->decode((array) $row))->all();
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        return ['items' => $rows, 'next_cursor' => $hasMore ? (string) $rows[array_key_last($rows)]['id'] : null];
    }

    /** @param array<string, mixed> $result */
    public function transition(string $id, string $from, string $to, int $actorId, array $result = [], ?string $failureCode = null): bool
    {
        $values = ['status' => $to, 'version' => DB::raw('version + 1'), 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'failure_code' => $failureCode, 'terminal_actor_id' => $actorId, 'updated_at' => now()];
        if ($to === 'applied') {
            $values['applied_at'] = now();
        }
        if ($to === 'cancelled') {
            $values['cancelled_at'] = now();
        }
        $updated = DB::table('estimate_change_proposal_states')->where('proposal_id', $id)->where('status', $from)->update($values) === 1;
        if ($updated) {
            DB::table('estimate_change_proposal_transitions')->insert(['proposal_id' => $id, 'actor_id' => $actorId, 'from_status' => $from, 'to_status' => $to, 'metadata' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        }

        return $updated;
    }

    /** @param array<string, mixed> $values @param string[] $jsonFields @return array<string, mixed> */
    private function encode(array $values, array $jsonFields): array
    {
        foreach ($jsonFields as $field) {
            if (array_key_exists($field, $values)) {
                $values[$field] = $values[$field] === null ? null : json_encode($values[$field], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            }
        }

        return $values;
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private function decode(array $values): array
    {
        foreach (['before_payload', 'after_payload', 'affected_payload', 'dependency_keys', 'assumptions', 'questions', 'evidence', 'version_fence', 'result', 'locator'] as $field) {
            if (is_string($values[$field] ?? null)) {
                $values[$field] = json_decode($values[$field], true, 32, JSON_THROW_ON_ERROR);
            }
        }

        return $values;
    }
}
