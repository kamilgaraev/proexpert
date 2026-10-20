<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\AssembleMatchedResources;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\EstimateNormativeMatcher;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\AcceptedQuantityEvidenceMaterializer;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\ResolveCommercialWorkPrice;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityData;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\WorkItemQuantityMapper;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatePricingService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\ResolveSelectedNormativeResources;
use App\BusinessModules\Addons\EstimateGeneration\Services\ResourceAssemblyService;
use App\Models\User;
use Brick\Math\BigDecimal;

/** Reuses canonical geometry and selected database norms. This path cannot request an LLM match. */
final readonly class EvaluationDraftArithmeticRecalculator
{
    public function __construct(private CurrentProjectDerivedQuantityService $quantities, private WorkItemQuantityMapper $mapper,
        private AcceptedQuantityEvidenceMaterializer $evidence, private ResolveSelectedNormativeResources $normatives,
        private EstimatePricingService $pricing, private ResolveCommercialWorkPrice $commercial,
        private EstimateNormativeMatcher $legacyNormatives, private ResourceAssemblyService $legacyResources,
        private AssembleMatchedResources $legacyMaterials = new AssembleMatchedResources) {}

    public function recalculate(array $draft, PipelineContext $context, ProjectModelSnapshot $snapshot, array $decisions = [],
        ?User $actor = null, ?EstimateGenerationSession $session = null): array
    {
        $preview = $this->quantities->previewInput($context->organizationId, $context->projectId, $context->sessionId);
        $quantities = $preview['quantities'];
        $universal = ($draft['generation_contract'] ?? null) === UniversalDraftProjector::CONTRACT;
        $documentVersions = \App\BusinessModules\Addons\EstimateGeneration\Quantities\GeometryInventory::currentDocumentVersions($snapshot);
        $wallScopes = \App\BusinessModules\Addons\EstimateGeneration\Quantities\WallEstimateScope::select($snapshot, $decisions, $documentVersions);
        if ($universal) {
            $draft = (new \App\BusinessModules\Addons\EstimateGeneration\Quantities\WallWorkItemExpander)->expand($draft,
                $snapshot, $wallScopes, $quantities, 1000, $documentVersions);
        }
        $quantitiesByEntity = [];
        foreach ($quantities as $quantity) {
            if ($quantity instanceof QuantityData && is_string($quantity->formulaInputs['entity_id'] ?? null)) {
                $quantitiesByEntity[$quantity->formulaInputs['entity_id']][] = $quantity;
            }
        }
        $regional = $draft['regional_context'] ?? [];
        foreach ($draft['local_estimates'] ?? [] as $estimateIndex => $estimate) {
            foreach ($estimate['sections'] ?? [] as $sectionIndex => $section) {
                foreach ($section['work_items'] ?? [] as $itemIndex => $item) {
                    if (in_array($item['item_type'] ?? 'priced_work', ['operation', 'review_note', 'resource_note', 'quantity_review'], true)) {
                        continue;
                    }
                    $quantityKey = $item['metadata']['quantity_key'] ?? $item['quantity_basis_details']['key'] ?? null;
                    $explicitQuantity = $session !== null && isset($session->input_payload['quantity_selections'][$item['key']]);
                    if ($explicitQuantity) {
                        $quantityKey = \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\DirectQuantityBinding::key($item['key']);
                        $item['metadata']['quantity_key'] = $quantityKey;
                    }
                    $quantity = is_string($quantityKey) ? $this->mapper->map($quantityKey, $quantities) : null;
                    if (! $explicitQuantity && ($item['metadata']['quantity_reset_required'] ?? false) === true) {
                        $quantity = null;
                    }
                    $expectedEntity = $item['metadata']['scope_entity_id'] ?? $item['quantity_basis_details']['formula_inputs']['entity_id'] ?? $item['metadata']['entity_id'] ?? null;
                    if (! $explicitQuantity && $quantity !== null && is_string($expectedEntity) && ($quantity->formulaInputs['entity_id'] ?? null) !== $expectedEntity) {
                        $quantity = null;
                    }
                    if (! $explicitQuantity && $quantity === null && is_string($expectedEntity)
                        && ($item['metadata']['quantity_reset_required'] ?? false) !== true) {
                        $workKey = $item['work_key'] ?? $item['metadata']['composition_work_key'] ?? $item['key'];
                        $quantity = $this->mapper->mapForEntity($workKey, $quantitiesByEntity[$expectedEntity] ?? [], $expectedEntity);
                    }
                    if (! \App\BusinessModules\Addons\EstimateGeneration\Quantities\WallWorkItemExpander::permits($item, $wallScopes, $universal)) {
                        $quantity = null;
                    }
                    $oldUnit = $item['unit'] ?? null;
                    $oldAmount = $item['quantity'] ?? null;
                    $oldBasis = $item['quantity_basis_details'] ?? $item['quantity_evidence'] ?? null;
                    unset($item['quantity_evidence_id'], $item['quantity_evidence_fingerprint'], $item['quantity_evidence_source_version'], $item['quantity_evidence']);
                    $item['quantity'] = null;
                    $item['quantity_status'] = 'unknown';
                    $item['quantity_basis_details'] = null;
                    if ($quantity !== null && $quantity->reviewBlockers === [] && is_string($oldUnit)) {
                        $factor = NormativeUnitNormalizer::safeQuantityFactorDecimal($quantity->unit, $oldUnit);
                        if ($factor !== null) {
                            $amount = (string) BigDecimal::of($quantity->amount)->multipliedBy($factor)->strippedOfTrailingZeros();
                            $quantity = new QuantityData($quantity->key, $oldUnit, $amount, $quantity->formulaKey, $quantity->formulaVersion,
                                $quantity->formulaInputs, $quantity->source, $quantity->evidenceIds, $quantity->modelVersion, $quantity->assumptions);
                            $item['quantity'] = $amount;
                            $item['quantity_status'] = $quantity->assumptions === [] ? 'supported' : 'assumed';
                            $item['quantity_evidence'] = $quantity->toArray();
                            $item['quantity_basis_details'] = $quantity->toArray();
                            $item['metadata']['quantity_key'] = $quantity->key;
                            $item['validation_flags'] = array_values(array_diff($item['validation_flags'] ?? [], ['quantity_review_required']));
                            $item['flags'] = array_values(array_diff($item['flags'] ?? [], ['quantity_review_required']));
                            unset($item['metadata']['requires_quantity_confirmation']);
                            $node = $this->evidence->materialize($context, $quantity, $item);
                            $item['quantity_evidence_id'] = $node->id;
                            $item['quantity_evidence_fingerprint'] = $node->fingerprint;
                            $item['quantity_evidence_source_version'] = $node->sourceVersion;
                        }
                    }
                    $changed = $oldAmount !== $item['quantity'] || $oldUnit !== ($item['unit'] ?? null)
                        || \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode(['basis' => $oldBasis])
                        !== \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson::encode(['basis' => $item['quantity_basis_details']]);
                    if ($actor !== null && $session !== null && isset($session->input_payload['price_selections'][$item['key']])) {
                        $price = $this->commercial->resolve($actor, $session, $item, $snapshot, $decisions);
                        if ($price === null) {
                            unset($item['commercial_price_snapshot'], $item['price_snapshot'], $item['evaluation_price_basis_quantity']);
                            $item['pricing_blocker'] = 'selected_price_source_changed';
                        } else {
                            $item['commercial_price_snapshot'] = $price;
                        }
                    } elseif ($actor !== null && $session !== null && isset($item['commercial_price_snapshot'])) {
                        $price = $this->commercial->resolveFrozen($actor, $session, $item);
                        if ($price === null) {
                            unset($item['commercial_price_snapshot']);
                        } else {
                            $item['commercial_price_snapshot'] = $price;
                        }
                    }
                    if ($item['quantity'] === null || $changed) {
                        // Resource totals and accepted money bases belong to a particular volume.
                        $item = $this->withoutPrice($item);
                        $item['pricing_status'] = 'not_calculated';
                        $item['pricing_blocker'] = $item['quantity'] === null ? 'quantity_unknown' : 'price_unknown';
                    }
                    $explicitPrice = $session !== null && isset($session->input_payload['price_selections'][$item['key']]);
                    if (! $explicitPrice && $item['quantity'] !== null && isset($item['normative_match']['norm_id']) && is_string($regional['normative_dataset_version'] ?? null)) {
                        $decisionContext = [
                            'organization_id' => $context->organizationId, 'project_id' => $context->projectId,
                            'session_id' => $context->sessionId, 'regional_context' => $regional,
                            'scope_type' => $estimate['scope_type'] ?? null, 'section_title' => $section['title'] ?? null,
                            'local_estimate_title' => $estimate['title'] ?? null,
                            'object_type' => $draft['object_profile']['object_type'] ?? null,
                            'source_refs' => $item['source_refs'] ?? [],
                        ];
                        $resolved = $universal
                            ? $this->normatives->resolve($item, (int) $item['normative_match']['norm_id'], $decisionContext)
                            : $this->resolveLegacyResources($item, $draft, $regional);
                        if ($resolved !== null) {
                            $resolved['normative_match']['selected_by_user'] = $item['normative_match']['selected_by_user'] ?? false;
                            $item = $resolved;
                            $item = $this->pricing->price([$item], $regional, $context)[0];
                            $item['evaluation_price_basis_quantity'] = $item['quantity'];
                        } elseif ($universal || $changed) {
                            $item = $this->withoutPrice($item);
                            $item['pricing_status'] = 'not_calculated';
                            $item['pricing_blocker'] = 'selected_normative_source_changed';
                        }
                    }
                    $draft['local_estimates'][$estimateIndex]['sections'][$sectionIndex]['work_items'][$itemIndex] = $item;
                }
            }
        }
        $draft['quantity_coverage_warnings'] = $preview['warnings'];

        return $draft;
    }

    private function withoutPrice(array $item): array
    {
        foreach (['materials', 'labor', 'machinery', 'other_resources', 'equipment'] as $group) {
            $item[$group] = [];
        }
        unset($item['price_snapshot'], $item['evaluation_price_basis_quantity']);
        foreach (['unit_price', 'total_cost', 'work_cost', 'materials_cost', 'labor_cost', 'machinery_cost', 'other_cost'] as $money) {
            $item[$money] = null;
        }

        return $item;
    }

    private function resolveLegacyResources(array $item, array $draft, array $regional): ?array
    {
        $match = $this->legacyNormatives->matchSelectedNorm((int) $item['normative_match']['norm_id'], $item, $regional);
        if ($match === null || ($match['version']['version_key'] ?? null) !== $regional['normative_dataset_version']) {
            return null;
        }
        $item['pricing_blocker'] = 'normative_required';
        $item = $this->legacyResources->applySelectedNormativeMatch($item, $match, $regional);
        $assembled = $this->legacyMaterials->handle([
            'local_estimates' => [['sections' => [['work_items' => [$item]]]]],
            'supplementary_materials' => $draft['supplementary_materials'] ?? [],
        ]);

        return $assembled['data']['local_estimates'][0]['sections'][0]['work_items'][0];
    }
}
