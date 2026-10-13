<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Analysis;

use Illuminate\Database\ConnectionInterface;

/** Raw responses are recovery data, not financial records. Never prune a recoverable session. */
final readonly class PruneArchivedAiPhysicalResponses
{
    public const MARKER = 'response_retention';

    public function __construct(private ConnectionInterface $database) {}

    public function handle(int $limit = 100): int
    {
        $days = max(7, (int) config('estimate-generation.retention.archived_response_days', 7));
        $cutoff = now()->subDays($days);
        $ids = $this->database->table('estimate_generation_vision_physical_attempts as a')
            ->join('estimate_generation_sessions as s', function ($join): void {
                $join->on('s.id', '=', 'a.session_id')->on('s.organization_id', '=', 'a.organization_id')
                    ->on('s.project_id', '=', 'a.project_id');
            })
            ->where('s.status', 'archived')->where('s.updated_at', '<=', $cutoff)
            ->where('a.state', 'completed')->where('a.usage_recorded', true)
            ->whereNull('a.owner_token')->whereNull('a.lease_expires_at')
            ->where('a.response_received_at', '<=', $cutoff)->whereNotNull('a.response_payload')
            ->whereRaw("NOT jsonb_exists(a.response_payload, 'response_retention')")
            ->whereRaw(<<<'SQL'
                NOT EXISTS (SELECT 1 FROM estimate_generation_ai_role_runs r WHERE r.physical_attempt_id=a.attempt_id
                    AND (r.status<>'completed' OR r.result_payload IS NULL OR r.owner_uuid IS NOT NULL OR r.lease_expires_at IS NOT NULL
                        OR r.organization_id<>a.organization_id OR r.project_id<>a.project_id OR r.session_id<>a.session_id))
                AND ((a.unit_id IS NULL AND EXISTS (SELECT 1 FROM estimate_generation_ai_role_runs r
                    WHERE r.physical_attempt_id=a.attempt_id AND r.status='completed' AND r.result_payload IS NOT NULL))
                    OR EXISTS (SELECT 1 FROM estimate_generation_processing_units u
                        JOIN estimate_generation_document_pages p ON p.processing_unit_id=u.id
                        WHERE u.id=a.unit_id AND u.session_id=a.session_id AND u.organization_id=a.organization_id
                            AND u.project_id=a.project_id AND u.status='completed' AND u.output_version IS NOT NULL
                            AND p.session_id=a.session_id AND p.organization_id=a.organization_id AND p.project_id=a.project_id
                            AND p.normalized_payload IS NOT NULL AND p.output_version=u.output_version))
                SQL)
            ->orderBy('a.attempt_id')->limit(min(500, max(1, $limit)))->get(['a.attempt_id', 'a.session_id']);
        $count = 0;
        foreach ($ids as $candidate) {
            $count += $this->database->transaction(function () use ($candidate, $cutoff): int {
                // Workflow writers lock the session first. Use the same order and recheck after acquiring it.
                $session = $this->database->table('estimate_generation_sessions')->where('id', $candidate->session_id)
                    ->where('status', 'archived')->where('updated_at', '<=', $cutoff)->lockForUpdate()->first();
                if ($session === null) {
                    return 0;
                }
                $attempt = $this->database->table('estimate_generation_vision_physical_attempts')
                    ->where('attempt_id', $candidate->attempt_id)->where('session_id', $session->id)
                    ->where('organization_id', $session->organization_id)->where('project_id', $session->project_id)
                    ->where('state', 'completed')->where('usage_recorded', true)
                    ->whereNull('owner_token')->whereNull('lease_expires_at')->where('response_received_at', '<=', $cutoff)
                    ->whereNotNull('response_payload')->whereRaw("NOT jsonb_exists(response_payload, 'response_retention')")
                    ->lockForUpdate()->first();
                if ($attempt === null || ! $this->publishedConsumer($attempt)) {
                    return 0;
                }
                $this->database->table('estimate_generation_vision_physical_attempts')->where('attempt_id', $attempt->attempt_id)
                    ->update(['response_payload' => json_encode([self::MARKER => [
                        'version' => 1, 'purged_at' => now()->toIso8601String(), 'reason' => 'archived_published_response',
                    ]], JSON_THROW_ON_ERROR)]);

                return 1;
            }, 1);
        }

        return $count;
    }

    private function publishedConsumer(object $attempt): bool
    {
        $roles = $this->database->table('estimate_generation_ai_role_runs')->where('physical_attempt_id', $attempt->attempt_id)
            ->lockForUpdate()->get(['organization_id', 'project_id', 'session_id', 'status', 'result_payload', 'owner_uuid', 'lease_expires_at']);
        foreach ($roles as $role) {
            if ((int) $role->organization_id !== (int) $attempt->organization_id
                || (int) $role->project_id !== (int) $attempt->project_id || (int) $role->session_id !== (int) $attempt->session_id
                || $role->status !== 'completed' || $role->result_payload === null
                || $role->owner_uuid !== null || $role->lease_expires_at !== null) {
                return false;
            }
        }
        if ($attempt->unit_id !== null) {
            // The prepared output and canonical page are durable; the original document stays private and intact.
            $unit = $this->database->table('estimate_generation_processing_units')->where('id', $attempt->unit_id)
                ->where('session_id', $attempt->session_id)->where('organization_id', $attempt->organization_id)
                ->where('project_id', $attempt->project_id)->lockForUpdate()->first();
            if ($unit === null || $unit->status !== 'completed' || $unit->output_version === null) {
                return false;
            }

            return $this->database->table('estimate_generation_document_pages')->where('processing_unit_id', $unit->id)
                ->where('session_id', $attempt->session_id)->where('organization_id', $attempt->organization_id)
                ->where('project_id', $attempt->project_id)->where('output_version', $unit->output_version)
                ->whereNotNull('normalized_payload')->exists();
        }

        return $roles->isNotEmpty();
    }
}
