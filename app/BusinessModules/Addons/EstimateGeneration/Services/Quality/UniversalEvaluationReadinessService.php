<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Quality;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\ReadConfirmedUniversalEvaluation;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\SystemAdmin;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/** Universal readiness concerns the chosen scope; a real manual input does not require a fictitious document. */
final readonly class UniversalEvaluationReadinessService
{
    public function __construct(private ReadConfirmedUniversalEvaluation $evaluations) {}

    public function evaluate(EstimateGenerationSession $session, array $documents = []): ReadinessResult
    {
        $actor = Auth::user();
        $accepted = $this->evaluations->read($session, $actor instanceof User || $actor instanceof SystemAdmin ? $actor : null);
        $blockers = [];
        if (! config('estimate-generation.universal_enabled', false)) {
            $blockers[] = $this->issue('universal_rollout_disabled');
        }
        if ($accepted === null) {
            $blockers[] = $this->issue('current_scenario_confirmation_required');
        }
        if ((int) ($documents['pending'] ?? $documents['pending_count'] ?? 0) > 0) {
            $blockers[] = $this->issue('documents_pending');
        }
        if ((int) ($documents['action_required'] ?? $documents['action_required_count'] ?? 0) > 0) {
            $blockers[] = $this->issue('documents_require_review');
        }
        if ((string) ($session->draft_payload['contingency_amount'] ?? '0') !== '0'
            && ! \Brick\Math\BigDecimal::of((string) ($session->draft_payload['contingency_amount'] ?? '0'))->isZero()) {
            $blockers[] = $this->issue('contingency_tax_confirmation_required');
        }
        $canApply = $session->status->value === 'ready_to_apply' && $blockers === [];
        $manualInputAvailable = \App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService::manualInputAvailable(
            $session->input_payload ?? [], (bool) config('estimate-generation.universal_enabled', false));
        $hasReadyDocuments = (int) ($documents['ready'] ?? $documents['ready_count'] ?? 0) > 0;
        $canGenerate = config('estimate-generation.universal_enabled', false)
            && (! $session->status->isTerminal() || in_array($session->status->value, ['applied', 'cancelled'], true))
            && ($manualInputAvailable || $hasReadyDocuments)
            && (int) ($documents['pending'] ?? $documents['pending_count'] ?? 0) === 0
            && (int) ($documents['action_required'] ?? $documents['action_required_count'] ?? 0) === 0
            && (int) ($documents['system_failure_count'] ?? 0) === 0;
        $metrics = array_fill_keys(['review_items_total', 'review_items_blocking', 'review_items_warning', 'review_items_optional', 'normative_requires_review'], 0);

        return new ReadinessResult(status: $canApply ? 'ready_to_apply' : ($session->status->value === 'applied' ? 'applied' : 'draft_needs_review'),
            canGenerate: $canGenerate,
            canApply: $canApply, blockingIssues: $blockers, warnings: [], metrics: $metrics,
            nextAction: ['code' => $canApply ? 'apply' : 'review_draft', 'message' => $canApply ? 'Создайте смету подтверждённого состава.' : 'Проверьте текущую оценку.'],
            confirmedEvaluation: $accepted === null ? null : [...$accepted['confirmation'],
                'net_total' => $accepted['selected']['scenario']['net_subtotals']['RUB'],
                'vat_total' => $accepted['selected']['scenario']['vat_subtotals']['RUB'],
                'package_count' => count(array_unique(array_column($accepted['selected']['scenario']['positions'], 'package_key'))),
                'position_count' => count($accepted['selected']['scenario']['positions'])]);
    }

    private function issue(string $code): array
    {
        $key = 'estimate_evaluation.readiness.'.$code;

        return ['code' => $code, 'message_key' => $key, 'message' => trans_message($key)];
    }
}
