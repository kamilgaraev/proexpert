<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Export;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ConfirmedEvaluationDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Application\Generation\BuildMostEstimateDraft;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\ReadConfirmedUniversalEvaluation;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\UniversalDraftProjector;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\SystemAdmin;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ReadConfirmedEvaluationExport
{
    public function __construct(private EstimateGenerationActionAuthorization $authorization,
        private ReadConfirmedUniversalEvaluation $confirmed, private ConfirmedEvaluationDraftProjector $projector) {}

    public function read(User|SystemAdmin $actor, int $projectId, int $sessionId): EstimateGenerationSession
    {
        return DB::transaction(function () use ($actor, $projectId, $sessionId): EstimateGenerationSession {
            $query = EstimateGenerationSession::query()->where('project_id', $projectId)->whereKey($sessionId);
            if ($actor instanceof User) {
                $query->where('organization_id', $actor->current_organization_id);
            }
            $session = $query->lockForUpdate()->firstOrFail();
            $this->authorization->authorize($actor, $session, 'estimate_generation.view');
            $draft = $session->draft_payload ?? [];
            if (! config('estimate-generation.universal_enabled', false) || ! $session->status->allowsExport()
                || ($session->input_payload['evaluation_mode'] ?? null) !== 'universal'
                || ($draft['generation_contract'] ?? null) !== UniversalDraftProjector::CONTRACT
                || ! (new BuildMostEstimateDraft)->verifyArtifact($draft)) {
                $this->reject();
            }
            $accepted = $this->confirmed->read($session, $actor);
            if ($accepted === null) {
                $this->reject();
            }
            $export = clone $session;
            $export->draft_payload = $this->projector->projectVerifiedSelection($draft, $accepted['selected'], $accepted['confirmation']);

            return $export;
        });
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['draft' => [trans_message('estimate_generation.export_not_ready')]]);
    }
}
