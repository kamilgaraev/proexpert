<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\ScopeCompletenessEvaluator;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class SaveEvaluationScopeSelection
{
    public function __construct(private Connection $database, private EstimateGenerationActionAuthorization $authorization,
        private UserEvaluationChoiceWriter $choices) {}

    public function handle(User $actor, int $projectId, int $sessionId, array $input): array
    {
        $sections = $input['selected_sections'] ?? null;
        $profile = $input['profile_id'] ?? null;
        $construction = $input['construction_type'] ?? null;
        if (! Str::isUuid($input['request_id'] ?? '') || ! is_int($input['state_version'] ?? null) || $input['state_version'] < 0
            || ! is_array($sections) || ! array_is_list($sections) || count($sections) < 1 || count($sections) > 12
            || count(array_filter($sections, 'is_string')) !== count($sections) || count(array_unique($sections)) !== count($sections)
            || ! in_array($profile, ['construction', 'renovation', 'industrial'], true)
            || ! in_array($construction, ['new_construction', 'current_repair', 'capital_repair', 'reconstruction'], true)
            || ! in_array($input['price_policy'] ?? null, ['normative', 'catalog', 'mixed'], true)) {
            throw ValidationException::withMessages(['scope' => trans_message('estimate_evaluation.scope_request_invalid')]);
        }
        foreach ($sections as $section) {
            if (ScopeCompletenessEvaluator::requirementsFor($section, $construction, $profile) === []) {
                throw ValidationException::withMessages(['selected_sections' => trans_message('estimate_evaluation.section_unsupported')]);
            }
        }

        return $this->database->transaction(function () use ($actor, $projectId, $sessionId, $input, $sections, $profile, $construction): array {
            $session = EstimateGenerationSession::query()->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $projectId)->whereKey($sessionId)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize($actor, $session, 'estimate_generation.review');
            if (! config('estimate-generation.universal_enabled', false) || ($session->input_payload['evaluation_mode'] ?? null) !== 'universal') {
                throw ValidationException::withMessages(['evaluation' => trans_message('estimate_evaluation.disabled')]);
            }
            $payload = $session->input_payload;
            $receipts = $payload['evaluation_scope_receipts'] ?? [];
            $fingerprint = hash('sha256', CanonicalPipelineJson::encode($input));
            if (isset($receipts[$input['request_id']])) {
                $receipt = $receipts[$input['request_id']];
                if ($receipt['actor_id'] !== (int) $actor->id || ! hash_equals($receipt['fingerprint'], $fingerprint)) {
                    throw ValidationException::withMessages(['request_id' => trans_message('estimate_evaluation.scope_receipt_conflict')]);
                }

                return $receipt['result'];
            }
            if ($session->state_version !== $input['state_version'] || $session->status->isTerminal()
                || in_array($session->status->value, ['applying', 'generating', 'processing_documents'], true)) {
                throw new StaleEstimateGenerationState($sessionId, $input['state_version']);
            }
            if (count($receipts) >= 10000) {
                throw ValidationException::withMessages(['request_id' => trans_message('estimate_evaluation.scope_limit')]);
            }
            sort($sections, SORT_STRING);
            $selection = ['selected_sections' => $sections, 'profile_id' => $profile, 'construction_type' => $construction,
                'price_policy' => $input['price_policy'], 'profile_version' => ScopeCompletenessEvaluator::VERSION];
            $proof = $this->choices->write($actor, $session, 'scope', 'evaluation_scope', $selection,
                trans_message('estimate_evaluation.scope_selected'), $input['request_id']);
            $added = array_values(array_diff($sections, $payload['selected_sections'] ?? []));
            $payload = [...$payload, ...$selection, 'scope_selection' => [...$selection, ...$proof],
                'scope_selection_revision' => ($payload['scope_selection_revision'] ?? 0) + 1];
            unset($payload['confirmed_evaluation']);
            $result = [...$proof, 'request_id' => $input['request_id'], 'state_version' => $session->state_version + 1,
                'cost_units' => '0', 'recalculation_required' => true, 'composition_required_for_sections' => $added];
            $receipts[$input['request_id']] = ['actor_id' => (int) $actor->id, 'fingerprint' => $fingerprint, 'result' => $result];
            $payload['evaluation_scope_receipts'] = $receipts;
            $session->update(['input_payload' => $payload, 'state_version' => $result['state_version']]);

            return $result;
        }, 3);
    }
}
