<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

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
use App\BusinessModules\Addons\EstimateGeneration\Services\ResourceAssemblyService;
use App\Models\User;
use Brick\Math\BigDecimal;

/** Reuses canonical geometry and selected database norms. This path cannot request an LLM match. */
final readonly class EvaluationDraftArithmeticRecalculator
{
    public function __construct(private CurrentProjectDerivedQuantityService $quantities, private WorkItemQuantityMapper $mapper,
        private AcceptedQuantityEvidenceMaterializer $evidence, private EstimateNormativeMatcher $normatives,
        private ResourceAssemblyService $resources, private EstimatePricingService $pricing,
        private ResolveCommercialWorkPrice $commercial) {}

    public function recalculate(array $draft, PipelineContext $context, ProjectModelSnapshot $snapshot, array $decisions = [],
        ?User $actor = null, ?EstimateGenerationSession $session = null): array
    {
        $preview = $this->quantities->previewInput($context->organizationId, $context->projectId, $context->sessionId);
        $quantities = $preview['quantities'];
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
                    $expectedEntity = $item['quantity_basis_details']['formula_inputs']['entity_id'] ?? $item['metadata']['entity_id'] ?? null;
                    if (! $explicitQuantity && $quantity !== null && is_string($expectedEntity) && ($quantity->formulaInputs['entity_id'] ?? null) !== $expectedEntity) {
                        $quantity = null;
                    }
                    $oldUnit = $item['unit'] ?? null;
                    $oldAmount = $item['quantity'] ?? null;
                    unset($item['quantity_evidence_id'], $item['quantity_evidence_fingerprint'], $item['quantity_evidence_source_version'], $item['quantity_evidence']);
                    $item['quantity'] = null;
                    $item['quantity_status'] = 'unknown';
                    $item['quantity_basis_details'] = null;
                    if ($quantity !== null && $quantity->reviewBlockers === [] && is_string($oldUnit)) {
                        $factor = NormativeUnitNormalizer::safeQuantityFactorDecimal($quantity->unit, $oldUnit);
                        if ($factor !== null) {
                            $amount = (string) BigDecimal::of($quantity->amount)->multipliedBy($factor);
                            $quantity = new QuantityData($quantity->key, $oldUnit, $amount, $quantity->formulaKey, $quantity->formulaVersion,
                                $quantity->formulaInputs, $quantity->source, $quantity->evidenceIds, $quantity->modelVersion, $quantity->assumptions);
                            $item['quantity'] = $amount;
                            $item['quantity_status'] = $quantity->assumptions === [] ? 'supported' : 'assumed';
                            $item['quantity_evidence'] = $quantity->toArray();
                            $item['quantity_basis_details'] = $quantity->toArray();
                            $node = $this->evidence->materialize($context, $quantity, $item);
                            $item['quantity_evidence_id'] = $node->id;
                            $item['quantity_evidence_fingerprint'] = $node->fingerprint;
                            $item['quantity_evidence_source_version'] = $node->sourceVersion;
                        }
                    }
                    $changed = $oldAmount !== $item['quantity'];
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
                        foreach (['materials', 'labor', 'machinery', 'other_resources', 'equipment'] as $group) {
                            $item[$group] = [];
                        }
                        unset($item['price_snapshot'], $item['evaluation_price_basis_quantity']);
                        foreach (['unit_price', 'total_cost', 'work_cost', 'materials_cost', 'labor_cost', 'machinery_cost', 'other_cost'] as $money) {
                            $item[$money] = null;
                        }
                        $item['pricing_status'] = 'not_calculated';
                        $item['pricing_blocker'] = $item['quantity'] === null ? 'quantity_unknown' : 'price_unknown';
                    }
                    $explicitPrice = $session !== null && isset($session->input_payload['price_selections'][$item['key']]);
                    if (! $explicitPrice && $item['quantity'] !== null && isset($item['normative_match']['norm_id']) && is_string($regional['normative_dataset_version'] ?? null)) {
                        $match = $this->normatives->matchSelectedNorm((int) $item['normative_match']['norm_id'], $item, $regional);
                        // matchSelectedNorm has a legacy fallback; only the exact pinned dataset is admissible here.
                        if ($match !== null && ($match['version']['version_key'] ?? null) === $regional['normative_dataset_version']) {
                            $item['pricing_blocker'] = 'normative_required';
                            $item = $this->resources->applySelectedNormativeMatch($item, $match, $regional);
                            $item = $this->pricing->price([$item], $regional, $context)[0];
                            $item['evaluation_price_basis_quantity'] = $item['quantity'];
                        } elseif ($changed) {
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
}
