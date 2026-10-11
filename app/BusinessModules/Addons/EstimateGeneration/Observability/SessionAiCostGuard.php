<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationExecutionActor;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Illuminate\Database\Connection;

final readonly class SessionAiCostGuard
{
    public function __construct(private Connection $database, private ?EstimateGenerationActionAuthorization $authorization = null) {}

    public function authorize(int $organizationId, int $projectId, int $sessionId, ?TextAiWireAttempt $attempt = null): void
    {
        if (min($organizationId, $projectId, $sessionId) < 1) {
            throw new SessionAiCostLimitReached('session_cost_scope_invalid');
        }

        $this->database->transaction(function () use ($organizationId, $projectId, $sessionId, $attempt): void {
            $session = $this->database->table('estimate_generation_sessions')
                ->where('id', $sessionId)
                ->where('organization_id', $organizationId)
                ->where('project_id', $projectId)
                ->lockForUpdate()
                ->first(['id', 'organization_id', 'project_id', 'user_id', 'status', 'state_version', 'input_payload', 'analysis_payload']);
            if ($session === null) {
                throw new SessionAiCostLimitReached('session_cost_scope_invalid');
            }
            if (in_array((string) $session->status, ['cancelled', 'archived', 'applied', 'applying', 'failed'], true)) {
                throw new SessionAiCostLimitReached('session_processing_stopped');
            }

            $analysis = is_string($session->analysis_payload)
                ? json_decode($session->analysis_payload, true)
                : $session->analysis_payload;
            $guard = is_array($analysis) && is_array($analysis['internal_cost_guard'] ?? null)
                ? $analysis['internal_cost_guard']
                : [];
            $input = is_string($session->input_payload) ? json_decode($session->input_payload, true) : $session->input_payload;
            if (($input['evaluation_mode'] ?? null) === 'universal' && ! config('estimate-generation.universal_enabled', false)) {
                throw new SessionAiCostLimitReached('universal_evaluation_not_enabled');
            }
            if (($attempt?->stateVersion !== null && (int) $session->state_version !== $attempt->stateVersion)
                || ($attempt?->generationAttemptId !== null
                    && ! hash_equals((string) ($input['generation_attempt_id'] ?? ''), $attempt->generationAttemptId))) {
                throw new SessionAiCostLimitReached('session_processing_stopped');
            }
            if ($attempt !== null) {
                $actor = EstimateGenerationExecutionActor::resolve(is_array($input) ? $input : [], (int) $session->user_id);
                if ($actor === null) {
                    throw new SessionAiCostLimitReached('session_actor_not_authorized');
                }
                $subject = new EstimateGenerationSession;
                $subject->setRawAttributes((array) $session, true);
                $subject->exists = true;
                try {
                    ($this->authorization ?? app(EstimateGenerationActionAuthorization::class))
                        ->authorize($actor, $subject, 'estimate_generation.generate');
                } catch (\Illuminate\Auth\Access\AuthorizationException) {
                    throw new SessionAiCostLimitReached('session_actor_not_authorized');
                }
            }
            $limit = BigDecimal::of((string) config(
                'estimate-generation.generation.session_cost_limit_rub',
                '900.00',
            ))->plus(BigDecimal::of((string) config(
                'estimate-generation.generation.session_cost_confirmation_increment_rub',
                '450.00',
            ))->multipliedBy(max(0, (int) ($guard['confirmation_version'] ?? 0))));
            $usage = $this->database->selectOne(<<<'SQL'
SELECT COALESCE(SUM(usage.cost_amount) FILTER (
           WHERE usage.pricing_status = 'available' AND usage.currency = 'RUB'
       ), 0)::numeric(20,8) AS spent,
       COUNT(*) FILTER (
           WHERE (usage.pricing_status <> 'available'
              OR usage.cost_amount IS NULL
              OR usage.currency IS DISTINCT FROM 'RUB')
             AND NOT EXISTS (
                 SELECT 1 FROM estimate_generation_vision_physical_attempts attempts
                 WHERE attempts.attempt_id = usage.attempt_id
             )
       )::int AS unknown_count,
       (COALESCE(SUM(usage.cost_amount) FILTER (
           WHERE usage.pricing_status = 'available' AND usage.currency = 'RUB'
       ), 0) + COALESCE((
           SELECT SUM(attempts.cost_reservation_amount)
           FROM estimate_generation_vision_physical_attempts attempts
           WHERE attempts.organization_id = ?
             AND attempts.project_id = ?
             AND attempts.session_id = ?
             AND attempts.state IN ('wire_started', 'response_received', 'completed', 'ambiguous')
             AND (attempts.state = 'ambiguous' OR NOT EXISTS (
                 SELECT 1 FROM estimate_generation_ai_usage settled
                 WHERE settled.attempt_id = attempts.attempt_id
                   AND settled.pricing_status = 'available'
                   AND settled.currency = 'RUB'
                   AND settled.cost_amount IS NOT NULL
             ))
       ), 0))::numeric(20,8) AS exposure,
       (SELECT COUNT(*)
        FROM estimate_generation_vision_physical_attempts attempts
        WHERE attempts.organization_id = ?
          AND attempts.project_id = ?
          AND attempts.session_id = ?
          AND attempts.state IN ('wire_started', 'response_received', 'completed', 'ambiguous')
          AND (attempts.cost_reservation_amount IS NULL
            OR attempts.cost_reservation_currency IS DISTINCT FROM 'RUB')
          AND (attempts.state = 'ambiguous' OR NOT EXISTS (
              SELECT 1 FROM estimate_generation_ai_usage settled
              WHERE settled.attempt_id = attempts.attempt_id
                AND settled.pricing_status = 'available'
                AND settled.currency = 'RUB'
                AND settled.cost_amount IS NOT NULL
          )))::int AS unknown_reservation_count
FROM estimate_generation_ai_usage usage
WHERE usage.organization_id = ?
  AND usage.project_id = ?
  AND usage.session_id = ?
SQL, [
                $organizationId, $projectId, $sessionId,
                $organizationId, $projectId, $sessionId,
                $organizationId, $projectId, $sessionId,
            ]);

            if ((int) ($usage?->unknown_count ?? 0) > 0
                || (int) ($usage?->unknown_reservation_count ?? 0) > 0) {
                throw new SessionAiCostLimitReached('session_cost_accounting_unavailable');
            }
            $projected = BigDecimal::of((string) ($usage?->exposure ?? '0'))
                ->plus($attempt?->reservation->amount ?? '0');
            if ($projected->isGreaterThan($limit)
                || ($attempt === null && $projected->isEqualTo($limit))) {
                throw new SessionAiCostLimitReached('session_cost_limit_reached');
            }
            if ($attempt === null) {
                return;
            }

            $now = new DateTimeImmutable;
            $query = $this->database->table('estimate_generation_vision_physical_attempts')
                ->where('attempt_id', $attempt->attemptId);
            $this->database->table('estimate_generation_vision_physical_attempts')->insertOrIgnore([
                'attempt_id' => $attempt->attemptId,
                'request_fingerprint' => $attempt->requestFingerprint,
                'logical_request_fingerprint' => $attempt->requestFingerprint,
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'session_id' => $sessionId,
                'state' => 'pre_wire',
                'owner_token' => $attempt->attemptId,
                'lease_expires_at' => $now->modify('+'.$attempt->leaseSeconds.' seconds'),
                'usage_recorded' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $row = $query->lockForUpdate()->first();
            if ($row === null || (int) $row->organization_id !== $organizationId
                || (int) $row->project_id !== $projectId || (int) $row->session_id !== $sessionId
                || $row->unit_id !== null
                || ! hash_equals((string) $row->request_fingerprint, $attempt->requestFingerprint)) {
                throw new UsageInvariantViolation('Text AI physical attempt scope collision.');
            }
            if ((string) $row->state !== 'pre_wire' || $row->lease_expires_at === null
                || new DateTimeImmutable((string) $row->lease_expires_at) <= $now) {
                throw new SessionAiCostLimitReached('physical_attempt_outcome_unknown');
            }
            $role = $this->database->table('estimate_generation_ai_role_runs')
                ->where('physical_attempt_id', $attempt->attemptId)->first();
            if ($role !== null && ((string) $role->status !== 'running'
                || (string) $role->owner_uuid !== (string) $row->owner_token
                || $role->lease_expires_at === null
                || new DateTimeImmutable((string) $role->lease_expires_at) <= $now)) {
                throw new SessionAiCostLimitReached('physical_attempt_ownership_lost');
            }
            $sentCount = $this->database->table('estimate_generation_vision_physical_attempts')
                ->where('organization_id', $organizationId)->where('project_id', $projectId)
                ->where('session_id', $sessionId)
                ->where('logical_request_fingerprint', $attempt->requestFingerprint)
                ->whereNotNull('wire_started_at')->count();
            if ($sentCount >= 3) {
                throw new SessionAiCostLimitReached('physical_attempt_limit_reached');
            }
            $updated = $query->where('state', 'pre_wire')->update([
                'state' => 'wire_started',
                'cost_reservation_amount' => $attempt->reservation->amount,
                'cost_reservation_currency' => $attempt->reservation->currency,
                'wire_started_at' => $now,
                'updated_at' => $now,
            ]);
            if ($updated !== 1) {
                throw new UsageInvariantViolation('Text AI physical attempt reservation lost.');
            }
        }, 3);
    }
}
