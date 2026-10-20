<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\FrozenWorkItemLimit;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalReviewSummaryProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Planning\SupplementaryWorkCatalog;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class EvaluationWorkComposition
{
    public function __construct(private ConnectionInterface $database, private EstimateGenerationActionAuthorization $authorization,
        private EvaluationCompositionPreview $preview, private UserEvaluationChoiceWriter $choices,
        private EvaluationInputFingerprint $inputs, private BuildMostEstimateDraft $artifacts,
        private UniversalReviewSummaryProjector $reviews) {}

    public function show(User $actor, int $projectId, int $sessionId): array
    {
        return $this->database->transaction(function () use ($actor, $projectId, $sessionId): array {
            $session = $this->session($actor, $projectId, $sessionId);
            $this->authorization->authorize($actor, $session, 'estimate_generation.view');

            return $this->preview->current($session);
        });
    }

    public function accept(User $actor, int $projectId, int $sessionId, array $input): array
    {
        $works = $input['work_keys'] ?? null;
        if (! is_string($input['request_id'] ?? null) || ! Str::isUuid($input['request_id']) || ! is_int($input['state_version'] ?? null) || $input['state_version'] < 0
            || ! is_string($input['preview_fingerprint'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $input['preview_fingerprint']) !== 1
            || ! is_array($works) || ! array_is_list($works) || count($works) < 1 || count($works) > 32
            || count(array_filter($works, 'is_string')) !== count($works) || count(array_unique($works)) !== count($works)
            || ! is_string($input['reason'] ?? null) || mb_strlen(trim($input['reason'])) < 10 || strlen($input['reason']) > 1000) {
            throw ValidationException::withMessages(['composition' => trans_message('estimate_composition.invalid')]);
        }

        return $this->database->transaction(function () use ($actor, $projectId, $sessionId, $input, $works): array {
            $session = $this->session($actor, $projectId, $sessionId, true);
            $this->authorization->authorize($actor, $session, 'estimate_generation.review');
            $payload = $session->input_payload ?? [];
            $receipts = $payload['evaluation_composition_receipts'] ?? [];
            $fingerprint = hash('sha256', CanonicalPipelineJson::encode($input));
            if (isset($receipts[$input['request_id']])) {
                $receipt = $receipts[$input['request_id']];
                if ($receipt['actor_id'] !== (int) $actor->id || ! hash_equals($receipt['fingerprint'], $fingerprint)) {
                    throw ValidationException::withMessages(['request_id' => trans_message('estimate_composition.receipt_conflict')]);
                }

                return $receipt['result'];
            }
            if ((int) $session->state_version !== $input['state_version']) {
                throw new StaleEstimateGenerationState($sessionId, $input['state_version']);
            }
            if (count($receipts) >= 10000) {
                throw ValidationException::withMessages(['composition' => trans_message('estimate_composition.unavailable')]);
            }
            $preview = $this->preview->current($session);
            $candidates = array_column($preview['candidates'], null, 'work_key');
            if (! hash_equals($preview['preview_fingerprint'], $input['preview_fingerprint']) || array_diff($works, array_keys($candidates)) !== []) {
                throw ValidationException::withMessages(['composition' => trans_message('estimate_composition.changed')]);
            }
            if (count($works) > $preview['maximum_additional_rows']) {
                throw ValidationException::withMessages(['composition' => trans_message('estimate_composition.row_limit')]);
            }
            $draft = $session->draft_payload;
            $keys = [];
            foreach ($draft['local_estimates'] as $local) {
                foreach ($local['sections'] as $section) {
                    foreach ($section['work_items'] ?? [] as $item) {
                        $keys[$item['key']] = true;
                    }
                }
            }
            foreach ($works as $work) {
                if (isset($keys['supplementary-work:'.hash('sha256', $sessionId."\0".$work)])) {
                    throw ValidationException::withMessages(['composition' => trans_message('estimate_composition.changed')]);
                }
            }
            $proof = $this->choices->write($actor, $session, 'work-composition:'.$input['request_id'], 'evaluation_answer',
                ['answer_kind' => 'work_composition', 'catalog_version' => SupplementaryWorkCatalog::VERSION,
                    'work_keys' => $works, 'preview_fingerprint' => $input['preview_fingerprint']], $input['reason'], $input['request_id']);
            $normative = ($payload['price_policy'] ?? 'normative') === 'normative';
            $added = [];
            foreach ($works as $work) {
                $candidate = $candidates[$work];
                $unit = $normative ? $candidate['suggested_unit'] : null;
                $key = 'supplementary-work:'.hash('sha256', $sessionId."\0".$work);
                $item = ['key' => $key, 'work_key' => $work, 'name' => $candidate['name'],
                    'work_category' => $candidate['package'], 'quantity_formula' => $work,
                    'normative_search_text' => $candidate['normative_search_text'], 'normative_rate_code' => null,
                    'item_type' => 'priced_work', 'unit' => $unit, 'quantity' => null, 'quantity_status' => 'unknown',
                    'quantity_basis_details' => null, 'unit_price' => null, 'total_cost' => null,
                    'pricing_status' => 'not_calculated', 'pricing_blocker' => 'quantity_unknown',
                    'materials' => [], 'labor' => [], 'machinery' => [], 'equipment' => [], 'other_resources' => [],
                    'validation_flags' => ['normative_required', 'quantity_review_required', ...($unit === null ? ['unit_requires_confirmation'] : [])],
                    'evaluation_section' => $candidate['section'],
                    'metadata' => ['generation_source' => 'accepted_supplementary_work', 'composition_work_key' => $work,
                        'quantity_key' => $work, 'package_key' => $candidate['package'], 'display_role' => 'work',
                        'requires_quantity_confirmation' => true, 'composition_choice' => $proof,
                        'composition_catalog_version' => SupplementaryWorkCatalog::VERSION]];
                $localIndex = null;
                foreach ($draft['local_estimates'] as $index => $local) {
                    if ($local['key'] === $candidate['package']) {
                        $localIndex = $index;
                        break;
                    }
                }
                if ($localIndex === null) {
                    $draft['local_estimates'][] = ['key' => $candidate['package'],
                        'title' => trans_message('estimate_composition.packages.'.$candidate['package']), 'sections' => []];
                    $localIndex = array_key_last($draft['local_estimates']);
                }
                $sectionKey = 'accepted-supplementary-works';
                $sectionIndex = null;
                foreach ($draft['local_estimates'][$localIndex]['sections'] as $index => $section) {
                    if (($section['key'] ?? null) === $sectionKey) {
                        $sectionIndex = $index;
                        break;
                    }
                }
                if ($sectionIndex === null) {
                    $draft['local_estimates'][$localIndex]['sections'][] = ['key' => $sectionKey,
                        'title' => trans_message('estimate_composition.section'), 'work_items' => []];
                    $sectionIndex = array_key_last($draft['local_estimates'][$localIndex]['sections']);
                }
                $draft['local_estimates'][$localIndex]['sections'][$sectionIndex]['work_items'][] = $item;
                $payload['composition_selections'][$work] = ['work_item_key' => $key,
                    'generation_id' => $payload['composition_generation_id'] ?? null, ...$proof];
                $added[] = $key;
            }
            FrozenWorkItemLimit::assertDraft($draft, 1000);
            $draft['evaluation_rows_count'] = array_sum(array_map(static fn (array $local): int => array_sum(array_map(
                static fn (array $section): int => count($section['work_items'] ?? []), $local['sections'] ?? [])), $draft['local_estimates']));
            $payload['composition_selection_revision'] = ($payload['composition_selection_revision'] ?? 0) + 1;
            unset($payload['confirmed_evaluation'], $payload['scope_selection'], $payload['scenario_selections']);
            unset($draft['evaluation_result'], $draft['evaluation_policy']['scope_selection'], $draft['evaluation_policy']['scenario_selections']);
            $session->input_payload = $payload;
            $draft['source_input_version'] = 'sha256:'.$this->inputs->fromSession($session);
            $draft['is_complete'] = false;
            $draft['stage6_status'] = 'scenario_confirmation_required';
            $result = ['request_id' => $input['request_id'], 'state_version' => (int) $session->state_version + 1,
                'added_work_item_keys' => $added, 'recalculation_required' => true, 'scope_confirmation_required' => true,
                'scenario_confirmation_required' => true, 'estimate_complete' => false, 'cost_units' => '0'];
            $receipts[$input['request_id']] = ['fingerprint' => $fingerprint, 'actor_id' => (int) $actor->id, 'result' => $result];
            $payload['evaluation_composition_receipts'] = $receipts;
            $this->authorization->authorize($actor, $session, 'estimate_generation.review');
            $session->update(['input_payload' => $payload, 'draft_payload' => $this->artifacts->seal($this->reviews->project($draft)),
                'state_version' => $result['state_version']]);

            return $result;
        }, 3);
    }

    private function session(User $actor, int $projectId, int $sessionId, bool $lock = false): EstimateGenerationSession
    {
        $query = EstimateGenerationSession::query()->where('organization_id', $actor->current_organization_id)
            ->where('project_id', $projectId)->whereKey($sessionId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }
}
