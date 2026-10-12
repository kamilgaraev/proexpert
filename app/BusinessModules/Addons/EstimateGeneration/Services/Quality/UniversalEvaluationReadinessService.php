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
            $blockers[] = ['code' => 'universal_rollout_disabled', 'message' => 'Новые операции универсального процесса временно отключены.'];
        }
        if ($accepted === null) {
            $blockers[] = ['code' => 'current_scenario_confirmation_required', 'message' => 'Пересчитайте оценку и подтвердите актуальный сценарий, состав, объёмы и цены.'];
        }
        if ((int) ($documents['pending'] ?? $documents['pending_count'] ?? 0) > 0) {
            $blockers[] = ['code' => 'documents_pending', 'message' => 'Дождитесь окончания обработки выбранных документов.'];
        }
        if ((int) ($documents['action_required'] ?? $documents['action_required_count'] ?? 0) > 0) {
            $blockers[] = ['code' => 'documents_require_review', 'message' => 'Разберите документы, требующие проверки, или явно исключите их.'];
        }
        if ((string) ($session->draft_payload['contingency_amount'] ?? '0') !== '0'
            && ! \Brick\Math\BigDecimal::of((string) ($session->draft_payload['contingency_amount'] ?? '0'))->isZero()) {
            $blockers[] = ['code' => 'contingency_tax_confirmation_required', 'message' => 'Подтвердите резерв и его налоговые условия.'];
        }
        $canApply = $session->status->value === 'ready_to_apply' && $blockers === [];
        $metrics = array_fill_keys(['review_items_total', 'review_items_blocking', 'review_items_warning', 'review_items_optional', 'normative_requires_review'], 0);

        return new ReadinessResult(status: $canApply ? 'ready_to_apply' : ($session->status->value === 'applied' ? 'applied' : 'draft_needs_review'),
            canGenerate: ! $session->status->isTerminal() && ($session->input_payload['description'] ?? '') !== '',
            canApply: $canApply, blockingIssues: $blockers, warnings: [], metrics: $metrics,
            nextAction: ['code' => $canApply ? 'apply' : 'review_draft', 'message' => $canApply ? 'Создайте смету подтверждённого состава.' : 'Проверьте текущую оценку.'],
            confirmedEvaluation: $accepted === null ? null : [...$accepted['confirmation'],
                'net_total' => $accepted['selected']['scenario']['net_subtotals']['RUB'],
                'vat_total' => $accepted['selected']['scenario']['vat_subtotals']['RUB'],
                'package_count' => count(array_unique(array_column($accepted['selected']['scenario']['positions'], 'package_key'))),
                'position_count' => count($accepted['selected']['scenario']['positions'])]);
    }
}
