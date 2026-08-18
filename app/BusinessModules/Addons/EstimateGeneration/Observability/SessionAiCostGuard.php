<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use Brick\Math\BigDecimal;
use Illuminate\Database\Connection;

final readonly class SessionAiCostGuard
{
    public function __construct(private Connection $database) {}

    public function authorize(int $organizationId, int $projectId, int $sessionId): void
    {
        if (min($organizationId, $projectId, $sessionId) < 1) {
            throw new SessionAiCostLimitReached('session_cost_scope_invalid');
        }

        $this->database->transaction(function () use ($organizationId, $projectId, $sessionId): void {
            $session = $this->database->table('estimate_generation_sessions')
                ->where('id', $sessionId)
                ->where('organization_id', $organizationId)
                ->where('project_id', $projectId)
                ->lockForUpdate()
                ->first(['analysis_payload']);
            if ($session === null) {
                throw new SessionAiCostLimitReached('session_cost_scope_invalid');
            }

            $analysis = is_string($session->analysis_payload)
                ? json_decode($session->analysis_payload, true)
                : $session->analysis_payload;
            $guard = is_array($analysis) && is_array($analysis['internal_cost_guard'] ?? null)
                ? $analysis['internal_cost_guard']
                : [];
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
       ), 0) >= ?::numeric) AS limit_reached,
       (SELECT COUNT(*)
        FROM estimate_generation_vision_physical_attempts attempts
        WHERE attempts.organization_id = ?
          AND attempts.project_id = ?
          AND attempts.session_id = ?
          AND attempts.state IN ('wire_started', 'response_received', 'completed', 'ambiguous')
          AND attempts.cost_reservation_amount IS NULL
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
                $organizationId, $projectId, $sessionId, (string) $limit,
                $organizationId, $projectId, $sessionId,
                $organizationId, $projectId, $sessionId,
            ]);

            if ((int) ($usage?->unknown_count ?? 0) > 0
                || (int) ($usage?->unknown_reservation_count ?? 0) > 0) {
                throw new SessionAiCostLimitReached('session_cost_accounting_unavailable');
            }
            if (filter_var($usage?->limit_reached ?? false, FILTER_VALIDATE_BOOL)) {
                throw new SessionAiCostLimitReached('session_cost_limit_reached');
            }
        }, 3);
    }
}
