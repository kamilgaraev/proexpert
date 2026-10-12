<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\ConfirmedEvaluationSelection;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\SelectedEvaluationScenario;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\ResolveCommercialWorkPrice;
use App\Models\SystemAdmin;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/** Read-only proof/source check. It never publishes evidence, reserves credits or requests a model. */
final readonly class ReadConfirmedUniversalEvaluation
{
    public function __construct(private EvaluationInputFingerprint $inputs, private ProjectModelRepository $models,
        private ResolveCommercialWorkPrice $prices) {}

    public function read(EstimateGenerationSession $session, User|SystemAdmin|null $actor, ?object $row = null): ?array
    {
        if (($session->input_payload['evaluation_mode'] ?? null) !== 'universal' || $actor === null
            || ($actor instanceof User && (int) $actor->current_organization_id !== (int) $session->organization_id)
            || in_array($session->status->value, ['generating', 'processing_documents', 'cancelled', 'archived', 'failed'], true)) {
            return null;
        }
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $row ??= DB::table('estimate_generation_evaluation_revisions')->where('organization_id', $scope[0])
            ->where('project_id', $scope[1])->where('session_id', $scope[2])->orderByDesc('revision')->first();
        if ($row === null || ! isset($session->input_payload['confirmed_evaluation'])) {
            return null;
        }
        $inputHash = $this->inputs->fromSession($session);
        $snapshot = $this->models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        if (count($snapshot->facts) > 10000) {
            return null;
        }
        $decisions = $this->models->decisionsForSelectedFacts(...[...$scope, array_column($snapshot->facts, 'id')]);
        $result = json_decode($row->result, true, 64, JSON_THROW_ON_ERROR);
        $confirmation = (new ConfirmedEvaluationSelection)->accepted($snapshot, $decisions,
            $session->input_payload['confirmed_evaluation'], ['revision_id' => $row->public_id, 'content_hash' => $row->content_hash,
                'input_hash' => $row->input_hash, 'result' => $result], $inputHash);
        if ($confirmation === null) {
            return null;
        }
        $selected = (new SelectedEvaluationScenario)->select($result, $confirmation['scenario_id']);
        try {
            app(\App\BusinessModules\Addons\EstimateGeneration\Application\Apply\CurrentDraftQuantityProofGuard::class)
                ->assertCurrent(...[...$scope, $session->draft_payload ?? [], false]);
        } catch (\Illuminate\Validation\ValidationException) {
            return null;
        }
        foreach ($selected['scenario']['positions'] as $position) {
            $item = ['key' => $position['key'], 'unit' => $position['unit'], 'quantity' => $position['quantity'],
                'commercial_price_snapshot' => $position['price_snapshot'], 'material_id' => $position['price_snapshot']['material_id'] ?? null];
            $current = isset($session->input_payload['price_selections'][$position['key']])
                ? $this->prices->resolve($actor, $session, $item, $snapshot, $decisions)
                : $this->prices->resolveFrozen($actor, $session, $item);
            if ($current === null || ($current['applicable'] ?? true) !== true || ($current['conditions_missing'] ?? []) !== []
                || (($current['stale'] ?? false) === true && ($current['stale_accepted'] ?? false) !== true)
                || ! hash_equals($position['price_snapshot']['source_hash'] ?? '', $current['source_hash'] ?? '')
                || ! BigDecimal::of($current['unit_price'])->isEqualTo($position['unit_price'])) {
                return null;
            }
        }

        return ['confirmation' => $confirmation, 'selected' => $selected, 'result' => $result];
    }
}
