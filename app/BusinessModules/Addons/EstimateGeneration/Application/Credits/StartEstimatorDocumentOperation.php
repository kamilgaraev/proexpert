<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Credits;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\AdvanceEstimateGeneration;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationExecutionActor;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\Models\Credits\AICreditQuote;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Starts only the approved prepared manifest; it cannot request draft generation. */
final readonly class StartEstimatorDocumentOperation
{
    public function __construct(private Connection $database, private EstimatorCreditOperations $credits,
        private EstimateGenerationActionAuthorization $authorization, private AdvanceEstimateGeneration $advance) {}

    public function handle(User $actor, int $projectId, int $sessionId, array $approval): EstimateGenerationSession
    {
        if (! Str::isUuid($approval['request_id'] ?? '') || ! Str::isUuid($approval['quote_id'] ?? '')
            || ! is_int($approval['state_version'] ?? null)) {
            throw ValidationException::withMessages(['quote_id' => 'Подтвердите стоимость обработки подготовленных документов.']);
        }

        return $this->database->transaction(function () use ($actor, $projectId, $sessionId, $approval): EstimateGenerationSession {
            $session = EstimateGenerationSession::query()->whereKey($sessionId)->where('organization_id', $actor->current_organization_id)
                ->where('project_id', $projectId)->lockForUpdate()->firstOrFail();
            $this->authorization->authorize($actor, $session, 'estimate_generation.generate');
            app(EstimatorProductAccess::class)->requireAvailable((int) $session->organization_id);
            $id = strtolower($approval['request_id']);
            $operation = $this->database->table('estimate_generation_credit_operations')->where('operation_id', $id)
                ->where('organization_id', $session->organization_id)->where('project_id', $projectId)->where('session_id', $sessionId)
                ->where('actor_user_id', $actor->id)->where('kind', 'documents')->lockForUpdate()->first();
            if ($operation !== null && $operation->receipt !== null) {
                $quote = AICreditQuote::query()->whereKey($operation->quote_id)->firstOrFail();
                if ($quote->public_id !== strtolower($approval['quote_id']) || (int) $operation->approved_state_version !== $approval['state_version']) {
                    throw ValidationException::withMessages(['request_id' => 'Идентификатор относится к другой обработке документов.']);
                }

                return $session;
            }
            if (! in_array($session->status, [EstimateGenerationStatus::Draft, EstimateGenerationStatus::ProcessingDocuments], true)
                || (int) $session->state_version !== $approval['state_version']) {
                throw ValidationException::withMessages(['state_version' => 'Подготовьте актуальный пакет и получите новое предложение стоимости.']);
            }
            try {
                $this->credits->begin($actor, $session, $approval['state_version'], $id, $approval['quote_id'], 'documents');
            } catch (\DomainException) {
                throw ValidationException::withMessages(['quote_id' => 'Предложение стоимости обработки документов недействительно.']);
            }
            $input = [...($session->input_payload ?? []), ...EstimateGenerationExecutionActor::identity($actor),
                'documents_credit_operation_id' => $id, 'generation_requested' => false];
            unset($input['generation_credit_operation_id']);
            $session = $this->advance->documentsStarted($session, ['input_payload' => $input]);
            $receipt = ['operation_id' => $id, 'status' => 'queued', 'state_version' => (int) $session->state_version];
            $this->database->table('estimate_generation_credit_operations')->where('operation_id', $id)->update([
                'receipt' => CanonicalPipelineJson::encode($receipt),
                'updated_at' => now()]);
            $session->update(['analysis_payload' => [...($session->analysis_payload ?? []), 'credit_operation_receipt' => $receipt]]);

            return $session;
        }, 3);
    }
}
