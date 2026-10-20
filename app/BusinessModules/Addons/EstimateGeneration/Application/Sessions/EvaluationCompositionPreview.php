<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\FrozenWorkItemLimit;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\OrdinaryDraftReviewCandidates;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Planning\SupplementaryWorkCatalog;
use Illuminate\Validation\ValidationException;

final readonly class EvaluationCompositionPreview
{
    public const CONTRACT = 'evaluation-composition-preview:v1';

    private const COMPOSITION_AND_CONFIRMATION_FACT_RESERVE = 4;

    private const NEW_WORK_FACT_RESERVE = 7;

    public function __construct(private ProjectModelRepository $models, private BuildMostEstimateDraft $artifacts,
        private EvaluationInputFingerprint $inputs, private SupplementaryWorkCatalog $catalog) {}

    public function current(EstimateGenerationSession $session): array
    {
        $payload = $session->input_payload ?? [];
        $draft = $session->draft_payload ?? [];
        if (! config('estimate-generation.universal_enabled', false) || ($payload['evaluation_mode'] ?? null) !== 'universal'
            || ($draft['generation_contract'] ?? null) !== UniversalDraftProjector::CONTRACT
            || ! $this->artifacts->verifyArtifact($draft)
            || ! in_array($session->status->value, ['estimate_review_required', 'ready_to_apply'], true)) {
            throw ValidationException::withMessages(['composition' => trans_message('estimate_composition.unavailable')]);
        }
        $restored = (new OrdinaryDraftReviewCandidates)->restore($draft);
        try {
            FrozenWorkItemLimit::assertDraft($restored, 1000);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['composition' => trans_message('estimate_composition.row_limit')]);
        }
        $existing = [];
        $rowCount = 0;
        $pendingQuantities = 0;
        $priceReviews = 0;
        $pendingBindings = 0;
        foreach ($restored['local_estimates'] ?? [] as $local) {
            foreach ($local['sections'] ?? [] as $section) {
                foreach ($section['work_items'] ?? [] as $item) {
                    $rowCount++;
                    if (($item['item_type'] ?? null) === 'priced_work') {
                        $priceReviews++;
                    }
                    if (($item['metadata']['generation_source'] ?? null) === 'accepted_supplementary_work') {
                        if (($item['quantity'] ?? null) === null) {
                            $pendingQuantities++;
                        }
                        if (! isset($item['metadata']['scope_entity_id'], $item['metadata']['scope_entity_binding'])) {
                            $pendingBindings++;
                        }
                    }
                    $work = $item['metadata']['composition_work_key'] ?? $item['work_key'] ?? $item['metadata']['quantity_key'] ?? null;
                    if (is_string($work)) {
                        $existing[$work] = true;
                    }
                }
            }
        }
        $selected = $payload['selected_sections'] ?? [];
        $candidates = [];
        foreach ($this->catalog->all() as $work => $row) {
            if (! isset($existing[$work]) && ! isset($payload['composition_selections'][$work])
                && (in_array($row['section'], $selected, true) || in_array($row['package'], $selected, true))) {
                $candidates[] = $row;
            }
        }
        $capture = $this->models->snapshotForPlanning((int) $session->organization_id, (int) $session->project_id, (int) $session->id, 10001);
        $reservedFacts = self::COMPOSITION_AND_CONFIRMATION_FACT_RESERVE + 2 * $rowCount + 2 * $pendingQuantities + $priceReviews + 2 * $pendingBindings;
        $factCapacity = intdiv(max(0, 10000 - count($capture['snapshot']->facts) - $reservedFacts), self::NEW_WORK_FACT_RESERVE);
        if ($factCapacity < 1) {
            throw ValidationException::withMessages(['composition' => trans_message('estimate_composition.unavailable')]);
        }
        $binding = ['contract' => self::CONTRACT, 'catalog_version' => SupplementaryWorkCatalog::VERSION,
            'organization_id' => (int) $session->organization_id, 'project_id' => (int) $session->project_id,
            'session_id' => (int) $session->id, 'state_version' => (int) $session->state_version,
            'artifact_hash' => $draft['artifact_hash'], 'model_token' => $capture['token'],
            'input_fingerprint' => $this->inputs->fromSession($session), 'candidates' => $candidates];

        return ['contract_version' => self::CONTRACT, 'catalog_version' => SupplementaryWorkCatalog::VERSION,
            'state_version' => (int) $session->state_version,
            'preview_fingerprint' => hash('sha256', CanonicalPipelineJson::encode($binding)),
            'artifact_hash' => $draft['artifact_hash'], 'candidates' => $candidates,
            'maximum_additional_rows' => min(1000 - $rowCount, $factCapacity),
            'estimate_complete' => false, 'cost_units' => '0'];
    }
}
