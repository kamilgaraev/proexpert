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
SELECT COALESCE(SUM(cost_amount) FILTER (
           WHERE pricing_status = 'available' AND currency = 'RUB'
       ), 0)::numeric(20,8) AS spent,
       COUNT(*) FILTER (
           WHERE pricing_status <> 'available'
              OR cost_amount IS NULL
              OR currency IS DISTINCT FROM 'RUB'
       )::int AS unknown_count,
       (COALESCE(SUM(cost_amount) FILTER (
           WHERE pricing_status = 'available' AND currency = 'RUB'
       ), 0) >= ?::numeric) AS limit_reached
FROM estimate_generation_ai_usage
WHERE organization_id = ?
  AND project_id = ?
  AND session_id = ?
SQL, [(string) $limit, $organizationId, $projectId, $sessionId]);

            if ((int) ($usage?->unknown_count ?? 0) > 0) {
                throw new SessionAiCostLimitReached('session_cost_accounting_unavailable');
            }
            if (filter_var($usage?->limit_reached ?? false, FILTER_VALIDATE_BOOL)) {
                throw new SessionAiCostLimitReached('session_cost_limit_reached');
            }
        }, 3);
    }
}
