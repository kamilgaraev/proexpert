<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\AssembleMatchedResources;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\AcceptedNormativeDecisionData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeWorkflowResultData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinResolver;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeRetrievalService;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeWorkIntentFactory;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\PinnedNormativeCandidateFactory;
use App\BusinessModules\Addons\EstimateGeneration\Services\ResourceAssemblyService;
use Illuminate\Database\ConnectionInterface;

class ResolveSelectedNormativeResources
{
    public function __construct(
        private readonly ConnectionInterface $database,
        private readonly NormativeContextPinResolver $pins,
        private readonly NormativeWorkIntentFactory $intents,
        private readonly NormativeRetrievalService $retrieval,
        private readonly ResourceAssemblyService $resources,
        private readonly NormativeCandidateSelectionHardGate $selectionHardGate,
        private readonly PinnedNormativeCandidateFactory $candidates = new PinnedNormativeCandidateFactory,
        private readonly AssembleMatchedResources $materials = new AssembleMatchedResources,
    ) {}

    public function resolve(array $item, int $normId, array $context): ?array
    {
        $regional = $context['regional_context'] ?? [];
        $dataset = $regional['normative_dataset_version'] ?? null;
        $code = $this->database->table('estimate_norms')->where('id', $normId)->value('code');
        $date = $this->pins->applicabilityDate($regional);
        if (! is_string($dataset) || ! is_string($code) || ! is_string($date)) {
            return null;
        }
        $intent = $this->intents->intent($item, [...$context, 'applicability_date' => $date], $dataset);
        $pin = $this->pins->resolve($regional, [[
            'work_item_key' => $item['key'], 'search_text' => $intent->intent, 'unit' => $intent->canonicalUnit,
            'code' => $code, 'material' => $intent->material, 'action' => $intent->technology,
            'scope' => $intent->structure, 'system' => $intent->system, 'object' => $intent->workObject,
            'object_type' => $intent->objectType, 'normative_sections' => $intent->normativeSections,
            'specialization_scenario' => $intent->specializationScenario,
            'specialization_evidence' => $intent->specializationEvidence,
        ]]);
        if (($pin['status'] ?? null) !== 'pinned') {
            return null;
        }
        $catalog = array_values(array_filter($pin['catalog_candidates'], static fn (array $candidate): bool => ($candidate['normative_id'] ?? null) === $normId));
        $candidates = $this->candidates->forWorkItem($catalog, $item, $intent->normativeSections, $intent,
            $pin['candidate_ids_by_work_item'] ?? []);
        if ($candidates === []) {
            return null;
        }
        $set = $this->retrieval->retrieve($intent, $candidates);
        $result = new NormativeWorkflowResultData($set->candidates === [] ? 'review_required' : 'retrieval_only', $set, null, []);
        foreach ($catalog as $candidate) {
            if ($candidate['candidate_id'] !== $result->selectedCandidateId()) {
                continue;
            }
            $gateCandidate = [...$candidate, 'object_type' => $candidate['retrieval_metadata']['object_type'] ?? null];
            if ($this->selectionHardGate->rejectionReasons($item, $context, ['selected' => $gateCandidate]) !== []) {
                return null;
            }
            $item = $this->resources->assembleFromDecision($item,
                AcceptedNormativeDecisionData::fromWorkflowResult($result, $candidate), $pin['regional_context']);
            $assembled = $this->materials->handle([
                'local_estimates' => [['sections' => [['work_items' => [$item]]]]],
                'supplementary_materials' => $pin['supplementary_materials'] ?? [],
            ]);
            $item = $assembled['data']['local_estimates'][0]['sections'][0]['work_items'][0];
            $item['normative_match']['hard_gate_passed'] = true;
            $item['normative_match']['catalog_identity'] = (new NormativeCatalogIdentity)->fromCandidate($candidate);

            return $item;
        }

        return null;
    }
}
