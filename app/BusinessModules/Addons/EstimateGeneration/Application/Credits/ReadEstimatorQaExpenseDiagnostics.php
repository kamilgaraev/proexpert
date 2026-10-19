<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Credits;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\SystemAdmin;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ReadEstimatorQaExpenseDiagnostics
{
    private const MEASURED = "usages.usage_status='measured' AND usages.pricing_status='available' AND usages.currency='RUB' AND usages.cost_amount IS NOT NULL";

    public function __construct(private ConnectionInterface $database, private EstimateGenerationActionAuthorization $authorization) {}

    public function handle(User|SystemAdmin $actor, int $projectId, int $sessionId, int $page = 1, int $perPage = 100, bool $unknownOnly = false): array
    {
        if ($page < 1 || $page > 10000 || $perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException('estimator_qa_diagnostics_page_invalid');
        }
        $session = EstimateGenerationSession::query()->where('organization_id', (int) $actor->current_organization_id)
            ->where('project_id', $projectId)->whereKey($sessionId)->firstOrFail();
        $this->authorization->authorize($actor, $session, 'estimate_generation.view');
        $runIds = $this->database->table('estimate_generation_credit_operations')
            ->where('organization_id', $session->organization_id)->where('project_id', $projectId)->where('session_id', $sessionId)
            ->whereRaw("processing_plan->'qa_expense_budget'->>'run_id' ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'")
            ->selectRaw("DISTINCT processing_plan->'qa_expense_budget'->>'run_id' AS run_id")
            ->limit(33)->pluck('run_id')->filter(static fn (mixed $id): bool => is_string($id) && Str::isUuid($id))->values()->all();
        if (count($runIds) > 32) {
            return ['status' => 'too_many_runs', 'runs' => [], 'attempts' => [], 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => 0]];
        }
        $runs = $this->database->table('estimate_generation_qa_expense_runs')->whereIn('run_id', $runIds)
            ->orderBy('created_at')->get(['run_id', 'maximum_units_minor', 'unit_cost_micro_rub', 'unit_minor', 'maximum_cost_micro_rub', 'price_version', 'status']);
        $query = $this->database->table('estimate_generation_qa_expense_attempts as attempts')
            ->join('estimate_generation_vision_physical_attempts as physical', 'physical.attempt_id', '=', 'attempts.attempt_id')
            ->leftJoin('estimate_generation_ai_usage as usages', function ($join): void {
                $join->on('usages.attempt_id', '=', 'physical.attempt_id')->on('usages.organization_id', '=', 'physical.organization_id')
                    ->on('usages.project_id', '=', 'physical.project_id')->on('usages.session_id', '=', 'physical.session_id');
            })
            ->whereIn('attempts.run_id', $runIds)->where('physical.organization_id', $session->organization_id)
            ->where('physical.project_id', $projectId)->where('physical.session_id', $sessionId);
        $measured = self::MEASURED;
        $summary = (clone $query)->selectRaw("COUNT(*) AS attempt_count, COUNT(*) FILTER (WHERE $measured) AS measured_count,
            COUNT(*) FILTER (WHERE NOT COALESCE(($measured),false)) AS unknown_count,
            COALESCE(SUM(CASE WHEN $measured THEN CEIL(usages.cost_amount*1000000) ELSE attempts.maximum_cost_micro_rub END),0)::text AS scoped_supplier_exposure_micro_rub")->first();
        if ($unknownOnly) {
            $query->whereRaw("NOT COALESCE(($measured),false)");
        }
        $total = (clone $query)->count();
        $receipt = "COALESCE(physical.response_payload->'provider_response',physical.response_payload->'wire_response')";
        $tokens = [];
        foreach (['input_tokens', 'cached_input_tokens', 'output_tokens', 'reasoning_tokens'] as $field) {
            $tokens[] = "'$field',CASE WHEN ($receipt->>'$field') ~ '^[0-9]{1,18}$' THEN $receipt->>'$field' ELSE NULL END";
        }
        $tokensSql = implode(',', $tokens);
        $rows = $query->orderBy('attempts.created_at')->orderBy('attempts.attempt_id')->forPage($page, $perPage)
            ->select(['attempts.attempt_id', 'attempts.run_id', 'attempts.maximum_cost_micro_rub', 'attempts.created_at',
                'physical.state', 'physical.status', 'physical.http_code', 'physical.wire_started_at', 'physical.response_received_at',
                'physical.usage_recorded', 'physical.credit_operation_id', 'physical.credit_role', 'physical.request_fingerprint',
                'usages.provider', 'usages.requested_model', 'usages.reported_model', 'usages.usage_status', 'usages.pricing_status', 'usages.cost_amount', 'usages.currency'])
            ->selectRaw("CASE WHEN $measured THEN 'measured' ELSE 'conservative_maximum' END AS cost_basis,
                CASE WHEN $measured THEN CEIL(usages.cost_amount*1000000)::text ELSE attempts.maximum_cost_micro_rub::text END AS supplier_exposure_micro_rub,
                CASE WHEN physical.response_payload IS NOT NULL THEN 1 ELSE 0 END AS has_retained_response,
                CASE WHEN jsonb_typeof(physical.response_payload->'provider_response')='object' THEN 1 ELSE 0 END AS has_provider_response,
                CASE WHEN jsonb_typeof(physical.response_payload->'wire_response')='object' THEN 1 ELSE 0 END AS has_wire_response,
                CASE WHEN jsonb_typeof(physical.response_payload->'parsed_response')='object' THEN 1 ELSE 0 END AS has_parsed_response,
                CASE WHEN physical.price_snapshot IS NOT NULL AND physical.price_snapshot <> '{}'::jsonb THEN 1 ELSE 0 END AS has_price_snapshot,
                CASE WHEN $receipt->'usage_available'='true'::jsonb THEN 1 ELSE 0 END AS retained_usage_available,
                CASE WHEN ($receipt->>'model') ~ '^[A-Za-z0-9_./:-]{1,160}$' THEN $receipt->>'model' ELSE NULL END AS retained_model,
                jsonb_build_object($tokensSql) AS retained_tokens")
            ->get()->map(static function (object $row): array {
                $data = (array) $row;
                $data['retained_tokens'] = json_decode($data['retained_tokens'], true, 32, JSON_THROW_ON_ERROR);
                foreach (['has_retained_response', 'has_provider_response', 'has_wire_response', 'has_parsed_response', 'has_price_snapshot', 'retained_usage_available'] as $flag) {
                    $data[$flag] = (int) $data[$flag] === 1;
                }

                return $data;
            })->all();
        $attemptIds = array_column($rows, 'attempt_id');
        $roles = $this->database->table('estimate_generation_ai_role_runs')->where('organization_id', $session->organization_id)
            ->where('project_id', $projectId)->where('session_id', $sessionId)->whereIn('physical_attempt_id', $attemptIds)
            ->groupBy('physical_attempt_id')->selectRaw('physical_attempt_id, COUNT(*) AS role_run_count,
                CASE WHEN COUNT(*)=1 THEN MIN(role) END AS role,
                CASE WHEN COUNT(*)=1 THEN MIN(status) END AS status,
                CASE WHEN COUNT(*)=1 THEN MIN(model) END AS model,
                CASE WHEN COUNT(*)=1 THEN MIN(prompt_contract_version) END AS prompt_contract_version,
                CASE WHEN COUNT(*)=1 THEN MIN(input_fingerprint) END AS input_fingerprint,
                CASE WHEN COUNT(*)=1 THEN MIN(failure_code) END AS failure_code')->get()->keyBy('physical_attempt_id');
        $credits = $this->database->table('estimate_generation_credit_attempts')->where('organization_id', $session->organization_id)
            ->where('project_id', $projectId)->where('session_id', $sessionId)->whereIn('attempt_id', $attemptIds)
            ->get(['attempt_id', 'operation_id', 'role', 'status', 'usage_fingerprint', 'usage_transferred_at'])->keyBy('attempt_id');
        foreach ($rows as &$row) {
            $role = $roles->get($row['attempt_id']);
            $row['role_run'] = $role === null ? null : array_diff_key((array) $role, ['physical_attempt_id' => true]);
            $credit = $credits->get($row['attempt_id']);
            $row['credit_attempt'] = $credit !== null && $credit->operation_id === $row['credit_operation_id'] && $credit->role === $row['credit_role']
                ? array_diff_key((array) $credit, ['attempt_id' => true]) : null;
        }
        unset($row);

        return ['status' => 'available', 'runs' => $runs->map(static fn (object $row): array => (array) $row)->all(),
            'summary' => (array) $summary, 'attempts' => $rows,
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'has_more' => $page * $perPage < $total],
            'scope' => 'current_session_only', 'accounting' => 'diagnostic_only_no_budget_changes'];
    }
}
