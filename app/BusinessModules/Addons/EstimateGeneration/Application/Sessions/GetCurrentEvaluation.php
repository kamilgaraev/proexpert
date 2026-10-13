<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\User;
use Illuminate\Database\Connection;

final readonly class GetCurrentEvaluation
{
    public function __construct(private Connection $database, private EstimateGenerationActionAuthorization $authorization, private EvaluationInputFingerprint $inputs) {}

    public function handle(User $actor, int $projectId, int $sessionId): array
    {
        return $this->database->transaction(function () use ($actor, $projectId, $sessionId): array {
            $session = EstimateGenerationSession::query()->where('organization_id', $actor->current_organization_id)->where('project_id', $projectId)
                ->whereKey($sessionId)->sharedLock()->firstOrFail();
            $this->authorization->authorize($actor, $session, 'estimate_generation.view');
            $policy = ['evaluation_mode' => $session->input_payload['evaluation_mode'] ?? 'legacy',
                'price_policy' => $session->input_payload['price_policy'] ?? 'normative',
                'profile_id' => $session->input_payload['profile_id'] ?? 'construction',
                'construction_type' => $session->input_payload['construction_type'] ?? 'new_construction',
                'selected_sections' => $session->input_payload['selected_sections'] ?? []];
            $sourceInfoOnly = ($session->draft_payload['source_info_only'] ?? false) === true;
            $policy['request_info'] = $session->draft_payload['estimate_composition']['request_info'] ?? [];
            $policy['source_info_required'] = $sourceInfoOnly;
            $row = $this->database->table('estimate_generation_evaluation_revisions')->where('organization_id', $session->organization_id)
                ->where('project_id', $projectId)->where('session_id', $sessionId)->orderByDesc('revision')->first();
            if ($row === null || $sourceInfoOnly) {
                return [...$policy, 'revision' => null, 'state_version' => (int) $session->state_version, 'result' => null, 'confirmed_scenario' => null];
            }
            $recalculationPending = in_array($session->status->value, ['generating', 'processing_documents'], true);
            $inputHash = $this->inputs->fromSession($session);
            $result = json_decode($row->result, true, 64, JSON_THROW_ON_ERROR);
            $confirmation = null;
            if (! $recalculationPending && isset($session->input_payload['confirmed_evaluation'])) {
                $confirmation = app(ReadConfirmedUniversalEvaluation::class)->read($session, $actor, $row)['confirmation'] ?? null;
            }

            return [...$policy, 'revision_id' => $row->public_id, 'revision' => (int) $row->revision, 'content_hash' => $row->content_hash,
                'operation_id' => $row->operation_id, 'input_hash' => $row->input_hash, 'state_version' => (int) $session->state_version,
                'result_stale' => $recalculationPending || ! hash_equals($row->input_hash, $inputHash)
                    || (isset($session->input_payload['confirmed_evaluation']) && $confirmation === null),
                'recalculation_pending' => $recalculationPending,
                'confirmed_scenario' => $confirmation, 'result' => $result];
        });
    }
}
