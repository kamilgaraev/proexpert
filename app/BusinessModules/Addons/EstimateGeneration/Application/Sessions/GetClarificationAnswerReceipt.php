<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Sessions;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Validation\ValidationException;

/** Reads a previously committed answer; never submits it or starts reanalysis. */
final readonly class GetClarificationAnswerReceipt
{
    public function __construct(private Connection $database, private ProjectModelRepository $models,
        private EstimateGenerationActionAuthorization $authorization) {}

    public function handle(User $actor, int $projectId, int $sessionId, string $question, array $input): array
    {
        $rules = ['idempotency_key' => ['required', 'string', 'min:8', 'max:128', 'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]+\z/'],
            'expected_source_version' => ['required', 'string', 'regex:/\Asha256:[a-f0-9]{64}\z/'],
            'answer_fingerprint' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/']];
        $input = validator($input, $rules)->validate();
        if (preg_match('/\A[a-z][a-z0-9_]{1,79}\z/', $question) !== 1) {
            throw ValidationException::withMessages(['question' => trans_message('estimate_generation.question_not_found')]);
        }

        return $this->database->transaction(function () use ($actor, $projectId, $sessionId, $question, $input): array {
            $session = EstimateGenerationSession::query()->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $projectId)->whereKey($sessionId)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize($actor, $session, 'estimate_generation.view');
            $id = 'decision:clarification:'.hash('sha256', $input['idempotency_key']);
            $decision = $this->models->decisions((int) $session->organization_id, $projectId, $sessionId, [$id])[0] ?? null;
            $result = ['question_key' => $question, 'idempotency_key' => $input['idempotency_key'],
                'status' => 'not_found', 'decision_id' => null, 'response' => null, 'other' => null,
                'state_version' => (int) $session->state_version, 'cost_units' => '0'];
            if ($decision === null) {
                return $result;
            }
            $fact = $decision->selectedFactId === null ? null : $this->models->fact(
                (int) $session->organization_id, $projectId, $sessionId, $decision->selectedFactId,
            );
            if ($decision->actorType !== 'user' || $decision->actorId !== (string) $actor->id
                || ! hash_equals($decision->sourceVersion, $input['expected_source_version'])
                || ! is_array($fact?->value)
                || ($fact->value['question_key'] ?? null) !== $question
                || ($fact->value['question_fingerprint'] ?? null) !== $input['answer_fingerprint']
                || ! is_string($fact->value['choice_value'] ?? null)) {
                return [...$result, 'status' => 'unavailable'];
            }

            return [...$result, 'status' => 'saved', 'decision_id' => $id,
                'response' => $fact->value['choice_value'], 'other' => $fact->value['other'] ?? null];
        });
    }
}
