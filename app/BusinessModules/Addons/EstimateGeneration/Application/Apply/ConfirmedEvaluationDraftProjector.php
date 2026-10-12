<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Apply;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationDraftArithmeticRecalculator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EvaluationInputFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\ConfirmedEvaluationSelection;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\SelectedEvaluationScenario;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalEvaluationBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Projects the one server-verified, explicitly confirmed scenario without inventing a normative base. */
final readonly class ConfirmedEvaluationDraftProjector
{
    public const CONTRACT = 'most_confirmed_evaluation:v1';

    public function __construct(private EvaluationInputFingerprint $inputs, private ProjectModelRepository $models,
        private EvaluationDraftArithmeticRecalculator $arithmetic, private CurrentDraftQuantityProofGuard $quantities) {}

    public function project(EstimateGenerationSession $session, ApplyGeneratedEstimateCommand $command): array
    {
        $draft = $session->draft_payload ?? [];
        if (! config('estimate-generation.universal_enabled', false) || $command->actor === null
            || ($session->input_payload['evaluation_mode'] ?? null) !== 'universal'
            || ($draft['generation_contract'] ?? null) !== UniversalDraftProjector::CONTRACT
            || ! (new BuildMostEstimateDraft)->verifyArtifact($draft)) {
            $this->reject();
        }
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $row = DB::table('estimate_generation_evaluation_revisions')->where('organization_id', $scope[0])
            ->where('project_id', $scope[1])->where('session_id', $scope[2])->orderByDesc('revision')->first();
        if ($row === null) {
            $this->reject();
        }
        $inputHash = $this->inputs->fromSession($session);
        $snapshot = $this->models->snapshotForPlanning(...[...$scope, 10001])['snapshot'];
        if (count($snapshot->facts) > 10000) {
            $this->reject();
        }
        $decisions = $this->models->decisionsForSelectedFacts(...[...$scope, array_column($snapshot->facts, 'id')]);
        $result = json_decode($row->result, true, 64, JSON_THROW_ON_ERROR);
        $selection = (new ConfirmedEvaluationSelection)->accepted($snapshot, $decisions,
            $session->input_payload['confirmed_evaluation'] ?? null,
            ['revision_id' => $row->public_id, 'content_hash' => $row->content_hash, 'input_hash' => $row->input_hash, 'result' => $result], $inputHash);
        if ($selection === null) {
            $this->reject();
        }
        $version = 'sha256:'.$inputHash;
        $context = new PipelineContext($scope[2], $scope[0], $scope[1], (int) $session->state_version, $version, $session->status->value,
            generationAttemptId: $command->idempotencyKey ?? $row->operation_id, baseInputVersion: $version);
        $fresh = $this->arithmetic->recalculate($draft, $context, $snapshot, $decisions, $command->actor, $session);
        try {
            $selected = (new SelectedEvaluationScenario)->select((new UniversalEvaluationBuilder)->build($fresh, $snapshot, $decisions), $selection['scenario_id']);
        } catch (\InvalidArgumentException) {
            $this->reject();
        }
        if (! hash_equals($selection['scenario_hash'], $selected['scenario_hash'])) {
            $this->reject();
        }
        $positions = array_column($selected['scenario']['positions'], null, 'key');
        $consumed = [];
        $localEstimates = [];
        foreach ($fresh['local_estimates'] ?? [] as $estimate) {
            $sections = [];
            foreach ($estimate['sections'] ?? [] as $section) {
                $items = [];
                foreach ($section['work_items'] ?? [] as $item) {
                    $position = $positions[$item['key'] ?? ''] ?? null;
                    if ($position === null) {
                        continue;
                    }
                    if (isset($consumed[$item['key']]) || ! is_array($position['money_breakdown'] ?? null)
                        || ! is_string($item['quantity'] ?? null)
                        || ! BigDecimal::of($item['quantity'])->isEqualTo($position['quantity'])
                        || ! in_array($position['price_snapshot']['source_type'] ?? null, ['catalog', 'supplier', 'verified_analog'], true)) {
                        $this->reject();
                    }
                    $consumed[$item['key']] = true;
                    $item['description'] ??= null;
                    $item['quantity_basis'] ??= 'Объём подтверждён в выбранном сценарии.';
                    $item['total_cost'] = $position['money_breakdown']['net_amount'];
                    $item['item_type'] = 'confirmed_commercial_work';
                    $item['pricing_status'] = 'calculated';
                    $item['pricing_blocker'] = null;
                    $item['price_source'] = $position['price_snapshot']['source_type'];
                    $item['commercial_price_snapshot'] = $position['price_snapshot'];
                    $item['accepted_source_money'] = $position['money_breakdown'];
                    $item['metadata']['confirmed_commercial_position'] = true;
                    $item['metadata']['ordinary_item_type'] = $position['price_snapshot']['item_type'] ?? 'work';
                    $item['metadata']['evaluation_confirmation'] = $selection;
                    foreach (['materials', 'labor', 'machinery', 'other_resources', 'equipment'] as $group) {
                        $item[$group] = [];
                    }
                    foreach (['materials_cost', 'labor_cost', 'machinery_cost', 'other_cost', 'labor_hours', 'machinery_hours'] as $field) {
                        $item[$field] = '0';
                    }
                    $items[] = $item;
                }
                if ($items !== []) {
                    $sections[] = [...$section, 'title' => $section['title'] ?? $section['key'] ?? 'Работы', 'work_items' => $items];
                }
            }
            if ($sections !== []) {
                $localEstimates[] = [...$estimate, 'title' => $estimate['title'] ?? $estimate['key'] ?? 'Выбранный состав', 'sections' => $sections];
            }
        }
        if (count($consumed) !== count($positions) || $positions === []
            || ! BigDecimal::of((string) ($fresh['contingency_amount'] ?? '0'))->isZero()
            || ! BigDecimal::of((string) ($fresh['contingency_percent'] ?? '0'))->isZero()) {
            $this->reject();
        }
        $projection = [...$fresh, 'local_estimates' => $localEstimates, 'generation_contract' => self::CONTRACT,
            'stage6_status' => 'ready', 'is_complete' => true, 'confirmed_evaluation' => $selection,
            'accepted_payable_total' => $selected['total_by_currency']['RUB'], 'contingency_amount' => '0.00'];
        $this->quantities->assertCurrent(...[...$scope, $projection]);

        return (new BuildMostEstimateDraft)->seal($projection);
    }

    public static function isPersistableCommercialItem(array $item): bool
    {
        $price = $item['commercial_price_snapshot'] ?? null;
        $money = $item['accepted_source_money'] ?? null;
        if (($item['item_type'] ?? null) !== 'confirmed_commercial_work'
            || ($item['metadata']['confirmed_commercial_position'] ?? null) !== true
            || ! is_array($price) || ! is_array($money) || ($price['verified'] ?? null) !== true
            || ! in_array($price['source_type'] ?? null, ['catalog', 'supplier', 'verified_analog'], true)
            || ! is_string($price['source_reference'] ?? null) || ! is_string($price['source_hash'] ?? null)
            || ($price['currency'] ?? null) !== 'RUB' || ($price['conditions_missing'] ?? []) !== []
            || ! is_string($item['quantity'] ?? null) || ! is_string($item['total_cost'] ?? null)
            || ! is_array($item['metadata']['evaluation_confirmation'] ?? null)) {
            return false;
        }
        try {
            $expected = (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationVatProjection)->amount($money['quoted_amount'] ?? '', $price);

            return BigDecimal::of($item['quantity'])->isGreaterThan(0)
                && BigDecimal::of($item['total_cost'])->isGreaterThan(0)
                && \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($expected)
                    === \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode($money)
                && $item['total_cost'] === $money['net_amount'];
        } catch (\Throwable) {
            return false;
        }
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['draft' => 'Подтверждение оценки или её объёмы, цены и условия изменились. Пересчитайте и подтвердите текущую ревизию.']);
    }
}
