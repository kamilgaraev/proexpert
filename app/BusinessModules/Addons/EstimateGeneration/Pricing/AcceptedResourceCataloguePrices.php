<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pricing;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationChoiceProof;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\NormativeResourcePriceTarget;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use InvalidArgumentException;

final readonly class AcceptedResourceCataloguePrices
{
    public function __construct(private ProjectModelRepository $models, private CataloguePriceSnapshotReader $catalog,
        private ConvertEvaluationPriceUnit $units = new ConvertEvaluationPriceUnit) {}

    public function context(EstimateGenerationSession $session, ?ProjectModelSnapshot $snapshot = null): array
    {
        $selections = $session->input_payload['resource_price_selections'] ?? [];
        if (! is_array($selections) || count($selections) > 10000) {
            throw new InvalidArgumentException('resource_price_selection_limit');
        }
        if ($selections === []) {
            return [];
        }
        $scope = [(int) $session->organization_id, (int) $session->project_id, (int) $session->id];
        $snapshot ??= $this->models->snapshot(...[...$scope, 10001]);
        if (count($snapshot->facts) > 10000) {
            throw new InvalidArgumentException('evaluation_fact_limit_exceeded');
        }
        $facts = array_column($snapshot->facts, null, 'id');
        $factIds = $references = [];
        foreach ($selections as $selection) {
            if (is_array($selection) && is_string($selection['fact_id'] ?? null)) {
                $factIds[] = $selection['fact_id'];
            }
            if (is_array($selection) && is_string($selection['source_reference'] ?? null)
                && preg_match('/\Acatalog:[1-9][0-9]*\z/', $selection['source_reference']) === 1) {
                $references[] = $selection['source_reference'];
            }
        }
        $decisions = array_column($factIds === [] ? [] : $this->models->decisionsForSelectedFacts(...[...$scope, array_values(array_unique($factIds))]), null, 'id');
        $prices = $this->catalog->readMaterialPrices($scope[0], array_values(array_unique($references)));
        $version = 'sha256:'.hash('sha256', 'evaluation-choices:v1|'.implode('|', $scope));
        $result = [];
        foreach ($selections as $key => $selection) {
            $result[$key] = ['selection' => $selection, 'price' => null];
            if (! is_array($selection) || ($selection['contract_version'] ?? null) !== NormativeResourcePriceTarget::CONTRACT
                || ! is_string($selection['work_item_key'] ?? null) || ! is_int($selection['norm_id'] ?? null)
                || ! is_int($selection['norm_resource_id'] ?? null) || ! is_string($selection['fact_id'] ?? null)
                || $key !== NormativeResourcePriceTarget::key($selection['work_item_key'], $selection['norm_id'], $selection['norm_resource_id'])
                || ($selection['same_product_confirmed'] ?? null) !== true) {
                continue;
            }
            $fact = $facts[$selection['fact_id']] ?? null;
            $decision = $decisions[$selection['decision_id'] ?? ''] ?? null;
            $entityId = 'entity:'.hash('sha256', $version.'|resource-price:'.$key);
            $value = array_diff_key($selection, array_flip(['fact_id', 'entity_id']));
            if ($fact === null || $decision === null || $fact->type !== 'evaluation_price' || $fact->unit !== null
                || [$fact->organizationId, $fact->projectId, $fact->sessionId] !== $scope || $fact->sourceVersion !== $version
                || $fact->entityId !== $entityId || ($selection['entity_id'] ?? null) !== $entityId
                || ! (new EvaluationChoiceProof)->accepted($snapshot, $fact, $decision)
                || CanonicalPipelineJson::encode($fact->value) !== CanonicalPipelineJson::encode($value)) {
                continue;
            }
            $price = $prices[$selection['source_reference'] ?? ''] ?? null;
            if ($price === null || ! is_string($selection['source_hash'] ?? null)
                || ! hash_equals($price['source_hash'], $selection['source_hash'])) {
                continue;
            }
            $result[$key]['price'] = $price;
        }

        $workKeys = [];
        foreach ($selections as $selection) {
            if (is_array($selection) && is_string($selection['work_item_key'] ?? null) && is_int($selection['norm_id'] ?? null)
                && is_string($selection['generation_id'] ?? null)) {
                $workKeys[$selection['work_item_key']][$selection['norm_id']][$selection['generation_id']] = true;
            }
        }

        return ['choices' => $result, 'work_keys' => $workKeys];
    }

    public function uses(EstimateGenerationSession $session, array $item, array $context): bool
    {
        return isset($context['work_keys'][$item['key'] ?? ''][$item['normative_match']['norm_id'] ?? 0][(string) ($session->input_payload['composition_generation_id'] ?? '')]);
    }

    public function apply(EstimateGenerationSession $session, array $item, array $context): ?array
    {
        if ($context === []) {
            foreach ($item['materials'] ?? [] as $index => $resource) {
                unset($resource['normative_ref']['catalog_price_selection']);
                $item['materials'][$index] = $resource;
            }

            return $item;
        }
        $generation = (string) ($session->input_payload['composition_generation_id'] ?? '');
        $targets = array_column((new NormativeResourcePriceTarget)->targets($item, $generation), null, 'norm_resource_id');
        foreach ($item['materials'] ?? [] as $index => $resource) {
            unset($resource['normative_ref']['catalog_price_selection']);
            $target = $targets[$resource['normative_ref']['norm_resource_id'] ?? 0] ?? null;
            if ($target === null) {
                $item['materials'][$index] = $resource;

                continue;
            }
            $key = NormativeResourcePriceTarget::key($item['key'], $target['norm_id'], $target['norm_resource_id']);
            $entry = $context['choices'][$key] ?? null;
            if ($entry === null || ! is_array($entry['selection'] ?? null) || ($entry['selection']['generation_id'] ?? null) !== $generation) {
                $item['materials'][$index] = $resource;

                continue;
            }
            $selection = $entry['selection'];
            $price = $entry['price'];
            if (($selection['target_hash'] ?? null) !== $target['target_hash'] || ! is_array($price)) {
                return null;
            }
            $price = $this->usable($session, $target, $price, $selection);
            if ($price === null) {
                return null;
            }
            $resource['normative_ref']['catalog_price_selection'] = ['contract_version' => NormativeResourcePriceTarget::CONTRACT,
                'fact_id' => $selection['fact_id'], 'decision_id' => $selection['decision_id'], 'target_hash' => $target['target_hash'],
                'same_product_confirmed' => true, 'product_confirmation_basis' => $selection['reason'], 'source_snapshot' => $price];
            $resource['price_unit'] = $resource['unit'];
            foreach (['unit_conversion_id', 'unit_conversion_factor', 'unit_conversion_version', 'unit_conversion_fingerprint'] as $conversionKey) {
                unset($resource['normative_ref'][$conversionKey]);
            }
            $item['materials'][$index] = $resource;
        }

        return $item;
    }

    public function usable(EstimateGenerationSession $session, array $target, array $price, array $selection): ?array
    {
        if (($price['item_type'] ?? null) !== 'material' || ($price['currency'] ?? null) !== 'RUB'
            || ($price['applicable'] ?? null) !== true || (($price['stale'] ?? false) === true && ($selection['stale_accepted'] ?? false) !== true)
            || ($price['delivery_included'] ?? null) !== true) {
            return null;
        }
        $price['stale_accepted'] = ($selection['stale_accepted'] ?? false) === true;
        $price = (new ConfirmedCommercialPriceConditions)->apply($price, $selection['conditions_confirmation'] ?? [],
            (new CommercialPriceTargetRegion)->fromSession($session));
        if ($price === null || ($price['conditions_missing'] ?? []) !== []) {
            return null;
        }

        return $this->units->convert($price, $target['unit']);
    }

    public function frozenMatches(EstimateGenerationSession $session, array $items, ?ProjectModelSnapshot $snapshot = null): bool
    {
        $context = $this->context($session, $snapshot);
        foreach ($items as $item) {
            $current = $this->apply($session, $item, $context);
            if ($current === null) {
                return false;
            }
            foreach ($item['materials'] ?? [] as $index => $resource) {
                if (CanonicalPipelineJson::encode(['selection' => $resource['normative_ref']['catalog_price_selection'] ?? null])
                    !== CanonicalPipelineJson::encode(['selection' => $current['materials'][$index]['normative_ref']['catalog_price_selection'] ?? null])) {
                    return false;
                }
            }
        }

        return true;
    }
}
