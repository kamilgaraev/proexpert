<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pricing;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationChoiceProof;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\SystemAdmin;
use App\Models\User;
use DateTimeImmutable;

final readonly class ResolveCommercialWorkPrice
{
    public function __construct(private CataloguePriceSnapshotReader $catalog, private SupplierPriceSnapshotReader $suppliers,
        private ConvertEvaluationPriceUnit $units = new ConvertEvaluationPriceUnit,
        private ?ProjectModelRepository $models = null) {}

    /** The caller may reuse the current operation's scoped snapshot, never across operations. */
    public function resolve(User|SystemAdmin $actor, EstimateGenerationSession $session, array $workItem,
        ?ProjectModelSnapshot $snapshot = null, ?array $decisions = null): ?array
    {
        $selection = $session->input_payload['price_selections'][$workItem['key'] ?? ''] ?? null;
        if (! is_array($selection) || ! is_string($selection['source_reference'] ?? null)
            || ! is_string($selection['source_hash'] ?? null)) {
            return null;
        }
        if (($session->input_payload['evaluation_mode'] ?? null) === 'universal') {
            if ($this->models === null || ! is_string($selection['fact_id'] ?? null)) {
                return null;
            }
            $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
            $snapshot ??= $this->models->snapshot(...[...$scope, 10001]);
            if (count($snapshot->facts) > 10000) {
                throw new \DomainException('evaluation_fact_limit_exceeded');
            }
            $fact = array_column($snapshot->facts, null, 'id')[$selection['fact_id']] ?? null;
            $decisions ??= $this->models->decisionsForSelectedFacts(...[...$scope, [$selection['fact_id']]]);
            $decision = array_column($decisions, null, 'selectedFactId')[$selection['fact_id']] ?? null;
            if ($fact === null || $fact->type !== 'evaluation_price' || $decision === null
                || [$fact->organizationId, $fact->projectId, $fact->sessionId] !== $scope
                || ! (new EvaluationChoiceProof)->accepted($snapshot, $fact, $decision)
                || ($fact->value['work_item_key'] ?? null) !== ($workItem['key'] ?? null)
                || ($fact->value['source_reference'] ?? null) !== $selection['source_reference']
                || ($fact->value['source_hash'] ?? null) !== $selection['source_hash']
                || ($fact->value['stale_accepted'] ?? false) !== ($selection['stale_accepted'] ?? false)) {
                return null;
            }
        }
        $reference = $selection['source_reference'];
        $price = str_starts_with($reference, 'supplier:')
            ? $this->suppliers->read($actor, $session, $reference, new DateTimeImmutable('today'), [
                'material_id' => $workItem['material_id'] ?? null, 'quantity' => $workItem['quantity'] ?? null, 'unit' => $workItem['unit'] ?? null])
            : $this->catalog->read((int) $session->organization_id, $reference);
        if ($price === null || ! hash_equals($price['source_hash'], $selection['source_hash'])) {
            return null;
        }
        $price['stale_accepted'] = ($selection['stale_accepted'] ?? false) === true;

        $price = $this->units->convert($price, $workItem['unit'] ?? null);
        if ($price === null) {
            return null;
        }
        // Price verification may have loaded only its own decision; currency confirmation is a distinct fact.
        if (($price['currency'] ?? null) !== 'RUB' && isset($session->input_payload['currency_selections'][$price['currency'] ?? ''])) {
            [$snapshot, $decisions] = $this->proofContext($session);
        }

        return (new ConvertEvaluationCurrencySnapshot)->convert($price, $workItem, $session, $snapshot, $decisions);
    }

    public function proofContext(EstimateGenerationSession $session): array
    {
        if ($this->models === null) {
            throw new \DomainException('evaluation_price_proof_unavailable');
        }
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $snapshot = $this->models->snapshot(...[...$scope, 10001]);
        if (count($snapshot->facts) > 10000) {
            throw new \DomainException('evaluation_fact_limit_exceeded');
        }
        $ids = array_column($snapshot->facts, 'id');

        return [$snapshot, $ids === [] ? [] : $this->models->decisionsForSelectedFacts(...[...$scope, $ids])];
    }

    public function resolveAutomatic(EstimateGenerationSession $session, array $item, ?ProjectModelSnapshot $snapshot = null, ?array $decisions = null): ?array
    {
        if (($session->input_payload['evaluation_mode'] ?? null) !== 'universal'
            || ! in_array($session->input_payload['price_policy'] ?? null, ['catalog', 'mixed'], true)
            || isset($session->input_payload['price_selections'][$item['key'] ?? ''])) {
            return null;
        }
        $price = (new ResolveAutomaticCommercialPrice($this->catalog, $this->units))->resolve((int) $session->organization_id, $item,
            (new CommercialPriceTargetRegion)->fromSession($session));

        return $price === null ? null : (new ConvertEvaluationCurrencySnapshot)->convert($price, $item, $session, $snapshot, $decisions);
    }

    /** An automatic source must still match its immutable business snapshot; model amounts are ignored. */
    public function resolveFrozen(User|SystemAdmin $actor, EstimateGenerationSession $session, array $item): ?array
    {
        $frozen = $item['commercial_price_snapshot'] ?? null;
        if (is_array($frozen) && ($frozen['selection_mode'] ?? null) === 'automatic_exact_name'
            && ! (new AutomaticCommercialPriceMatcher)->frozenBasisMatches($frozen, $item, (new CommercialPriceTargetRegion)->fromSession($session))) {
            return null;
        }
        $reference = is_array($frozen) ? ($frozen['source_reference'] ?? null) : null;
        if (! is_string($reference) || ! is_string($frozen['source_hash'] ?? null)
            || preg_match('/\A(?:(?:catalog|work_type):[1-9][0-9]*|supplier:[1-9][0-9]*:[1-9][0-9]*)\z/', $reference) !== 1) {
            return null;
        }
        $current = str_starts_with($reference, 'supplier:')
            ? $this->suppliers->read($actor, $session, $reference, new DateTimeImmutable('today'),
                ['material_id' => $item['material_id'] ?? null, 'quantity' => $item['quantity'] ?? null, 'unit' => $item['unit'] ?? null])
            : $this->catalog->read((int) $session->organization_id, $reference);
        if ($current === null || ! hash_equals($current['source_hash'], $frozen['source_hash']) || ($current['stale'] ?? false) === true) {
            return null;
        }

        $price = $this->units->convert($current, $item['unit'] ?? null);
        if ($price === null) {
            return null;
        }
        if (($frozen['selection_mode'] ?? null) === 'automatic_exact_name') {
            if (! (new AutomaticCommercialPriceMatcher)->matches($price, $item, (new CommercialPriceTargetRegion)->fromSession($session))) {
                return null;
            }
            $price = [...$price, 'selection_mode' => 'automatic_exact_name', 'automatic_selection_basis' => $frozen['automatic_selection_basis']];
        }
        [$snapshot, $decisions] = isset($session->input_payload['currency_selections'][$price['currency'] ?? ''])
            ? $this->proofContext($session) : [null, null];

        return (new ConvertEvaluationCurrencySnapshot)->convert($price, $item, $session, $snapshot, $decisions);
    }
}
