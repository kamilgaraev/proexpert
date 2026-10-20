<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\FrozenWorkItemLimit;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\DerivedQuantityFactory;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\NativeQuantityDecisionInputs;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\RoomCeilingReplacement;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\RoomCeilingScope;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

final readonly class RoomCeilingReplacementPreview
{
    public function __construct(private ProjectModelRepository $models, private BuildMostEstimateDraft $artifacts,
        private EvaluationInputFingerprint $inputs, private CurrentRoomIdentitySources $identities, private DerivedQuantityFactory $factory) {}

    public function current(EstimateGenerationSession $session, string $parentKey): array
    {
        $draft = $session->draft_payload ?? [];
        if (! config('estimate-generation.universal_enabled', false) || ($session->input_payload['evaluation_mode'] ?? null) !== 'universal'
            || ($draft['generation_contract'] ?? null) !== UniversalDraftProjector::CONTRACT || ! $this->artifacts->verifyArtifact($draft)
            || ! in_array($session->status->value, ['estimate_review_required', 'ready_to_apply'], true)) {
            $this->reject();
        }
        $rows = RoomCeilingReplacement::rows($draft);
        $parents = array_values(array_filter($rows, static fn (array $row): bool => ($row['key'] ?? null) === $parentKey));
        if (count($parents) !== 1 || ! RoomCeilingReplacement::candidate($parents[0])) {
            $this->reject();
        }
        $parent = $parents[0];
        $work = RoomCeilingReplacement::work($parent);
        foreach ($rows as $row) {
            if (($row['key'] ?? null) !== $parentKey && RoomCeilingReplacement::work($row) === $work) {
                $this->reject();
            }
        }
        $capture = $this->models->snapshotForPlanning((int) $session->organization_id, (int) $session->project_id, (int) $session->id, 10001);
        $snapshot = $capture['snapshot'];
        $sources = RoomCeilingScope::sources($snapshot);
        $identities = [];
        foreach ($this->identities->rows($session, true) as $identity) {
            if ($identity['status'] === 'confirmed') {
                $identities[$identity['entity_id']][] = $identity;
            }
        }
        if ($sources === [] || array_keys($sources) !== RoomCeilingScope::roomIds($snapshot) || count($sources) > 128 || count($snapshot->facts) > 10000) {
            $this->reject();
        }
        $decisions = $this->models->decisionsForSelectedFacts((int) $session->organization_id, (int) $session->project_id,
            (int) $session->id, NativeQuantityDecisionInputs::factIds($snapshot));
        if (count($decisions) + 2 * count($sources) + 3 > 256) {
            $this->reject();
        }
        $rooms = [];
        $facts = array_column($snapshot->facts, null, 'id');
        foreach ($sources as $id => $source) {
            if (count($identities[$id] ?? []) !== 1) {
                $this->reject();
            }
            $request = ['quantity_id' => 'quantity:room-ceiling-preview:'.$id, 'formula_identity' => 'floor_area', 'formula_version' => '1',
                'entity_id' => $id, 'operands' => ['length' => $source['dimensions']['length']['fact_id'], 'width' => $source['dimensions']['width']['fact_id']],
                'output_unit' => 'm2', 'rounding_mode' => 'half_up', 'rounding_scale' => 8,
                'snapshot' => ['input_fingerprint' => $capture['token'], 'artifact_hash' => $draft['artifact_hash'],
                    'catalog_version' => RoomCeilingReplacement::CONTRACT, 'catalog_hash' => hash('sha256', RoomCeilingReplacement::CONTRACT),
                    'rule_version' => RoomCeilingScope::CONTRACT, 'rule_hash' => hash('sha256', RoomCeilingScope::CONTRACT)]];
            $result = $this->factory->derive($snapshot, $decisions, $request);
            if (! $result->isReady() || $result->quantity === null || BigDecimal::of($result->quantity->value)->isLessThanOrEqualTo(0)) {
                $this->reject();
            }
            $values = array_column($result->quantity->operands, 'value', 'role');
            $dimensions = [];
            foreach ($source['dimensions'] as $role => $signature) {
                $fact = $facts[$signature['fact_id']];
                $dimensions[$role] = [...$signature, 'value' => (string) $fact->value, 'normalized_metres' => $values[$role]];
            }
            $identity = $identities[$id][0];
            $rooms[] = ['entity_id' => $id, 'name' => $identity['name'], 'identity' => $identity, 'scope' => $source,
                'dimensions' => $dimensions, 'area_m2' => $result->quantity->value,
                'perimeter_m' => (string) BigDecimal::of($values['length'])->plus($values['width'])->multipliedBy(2)->stripTrailingZeros(),
                'work_item_key' => RoomCeilingReplacement::childKey($parentKey, $id)];
        }
        $additional = count($rooms) - 1;
        try {
            FrozenWorkItemLimit::assertCount(count($rows) + $additional, 1000);
        } catch (\InvalidArgumentException) {
            $this->reject();
        }
        $reserve = 4 + 2 * (count($rows) + $additional) + 3 * (count($rows) + $additional) + 4 * count($rooms);
        if (count($snapshot->facts) + $reserve > 10000) {
            $this->reject();
        }
        $preview = ['contract_version' => RoomCeilingReplacement::CONTRACT, 'state_version' => (int) $session->state_version,
            'parent_work_item_key' => $parentKey, 'work_key' => $work, 'parent_name' => $parent['name'] ?? $work,
            'previous_quantity' => $parent['quantity'] ?? null, 'previous_unit' => $parent['unit'] ?? null,
            'method' => RoomCeilingScope::METHOD, 'rooms' => $rooms, 'additional_rows' => $additional,
            'replacement_unit' => $work === 'ceiling.cornice' ? 'm' : 'm2',
            'replacement_total' => (string) array_reduce($rooms, static fn (BigDecimal $sum, array $room): BigDecimal => $sum->plus(
                $room[$work === 'ceiling.cornice' ? 'perimeter_m' : 'area_m2']), BigDecimal::zero())->stripTrailingZeros(),
            'assumption' => trans_message('estimate_ceiling.assumption'), 'cost_units' => '0', 'estimate_complete' => false];
        $binding = ['organization_id' => (int) $session->organization_id, 'project_id' => (int) $session->project_id,
            'session_id' => (int) $session->id, 'model_token' => $capture['token'], 'artifact_hash' => $draft['artifact_hash'],
            'input_fingerprint' => $this->inputs->fromSession($session), 'preview' => $preview];

        return [...$preview, 'preview_fingerprint' => hash('sha256', CanonicalPipelineJson::encode($binding))];
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['ceiling' => trans_message('estimate_ceiling.unavailable')]);
    }
}
