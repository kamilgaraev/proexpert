<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\ConfirmedEvaluationSelection;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\SelectedEvaluationScenario;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\ResolveCommercialWorkPrice;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCatalogIdentity;
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
        if (! (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationScenarioSourceBinding)
            ->current($session->input_payload['scenario_selections'] ?? null, $snapshot, $decisions)) {
            return null;
        }
        $result = json_decode($row->result, true, 64, JSON_THROW_ON_ERROR);
        $confirmation = (new ConfirmedEvaluationSelection)->accepted($snapshot, $decisions,
            $session->input_payload['confirmed_evaluation'], ['revision_id' => $row->public_id, 'content_hash' => $row->content_hash,
                'input_hash' => $row->input_hash, 'result' => $result], $inputHash);
        if ($confirmation === null) {
            return null;
        }
        $selected = (new SelectedEvaluationScenario)->select($result, $confirmation['scenario_id']);
        $draft = $session->draft_payload ?? [];
        $selectedKeys = array_fill_keys(array_column($selected['scenario']['positions'], 'key'), true);
        $scoped = $draft;
        foreach ($scoped['local_estimates'] ?? [] as $localIndex => $local) {
            foreach ($local['sections'] ?? [] as $sectionIndex => $section) {
                $scoped['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'] = array_values(array_filter(
                    $section['work_items'] ?? [], static fn (array $item): bool => isset($selectedKeys[$item['key']])));
            }
        }
        try {
            app(\App\BusinessModules\Addons\EstimateGeneration\Application\Apply\CurrentDraftQuantityProofGuard::class)
                ->assertCurrent(...[...$scope, $scoped, false]);
        } catch (\Illuminate\Validation\ValidationException) {
            return null;
        }
        $nativeKeys = array_fill_keys(array_column(array_values(array_filter($selected['scenario']['positions'],
            static fn (array $position): bool => ($position['price_snapshot']['source_type'] ?? null) === 'normative')), 'key'), true);
        if ($nativeKeys !== []) {
            $regional = $draft['regional_context'] ?? [];
            $version = DB::table('estimate_regional_price_versions')->where('id', $regional['estimate_regional_price_version_id'] ?? 0)
                ->where('region_id', $regional['region_id'] ?? 0)->where('price_zone_id', $regional['price_zone_id'] ?? 0)
                ->where('period_id', $regional['period_id'] ?? 0)->whereIn('status', ['active', 'checked'])->exists();
            $nativeItems = array_intersect_key((new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationWorkScope)->items($draft), $nativeKeys);
            $normIds = array_unique(array_map(static fn (array $entry): int => (int) ($entry['item']['normative_match']['norm_id'] ?? 0), $nativeItems));
            $norms = DB::table('estimate_norms as norms')->join('estimate_norm_collections as collections', 'collections.id', '=', 'norms.collection_id')
                ->join('estimate_dataset_versions as datasets', 'datasets.id', '=', 'collections.dataset_version_id')
                ->whereIn('norms.id', $normIds)->where('datasets.version_key', $regional['normative_dataset_version'] ?? '')
                ->where('datasets.source_type', 'fsnb_2022')->where('datasets.status', 'parsed')
                ->get(['norms.id', 'norms.code', 'norms.name', 'norms.unit', 'norms.canonical_unit',
                    'norms.section_code', 'norms.section_name', 'norms.work_composition', 'norms.unit_dimension',
                    'norms.material', 'norms.technology', 'norms.structure', 'norms.object_type', 'norms.region_code',
                    'norms.valid_from', 'norms.valid_to', 'collections.id as collection_id', 'collections.code as collection_code',
                    'collections.name as collection_name', 'collections.norm_type'])->keyBy('id');
            if (! $version || count($nativeItems) !== count($nativeKeys) || $norms->count() !== count($normIds)
                || ! (new VerifyCurrentNormativeResourcePrices)->verify(array_column($nativeItems, 'item'), $regional)) {
                return null;
            }
            foreach ($nativeItems as $entry) {
                $match = $entry['item']['normative_match'];
                if (! (new NormativeCatalogIdentity)->matches($match, $norms[$match['norm_id']])) {
                    return null;
                }
            }
            try {
                $current = (new SelectedEvaluationScenario)->select(
                    (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder)->build($draft, $snapshot, $decisions), $confirmation['scenario_id']);
            } catch (\InvalidArgumentException) {
                return null;
            }
            if (! hash_equals($confirmation['scenario_hash'], $current['scenario_hash'])) {
                return null;
            }
        }
        foreach ($selected['scenario']['positions'] as $position) {
            if (isset($nativeKeys[$position['key']])) {
                continue;
            }
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
