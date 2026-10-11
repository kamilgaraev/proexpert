<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Credits\EstimatorProductAccess;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationExecutionActor;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;

/** Rechecks current access after HTTP; provider expenses remain recorded even when publication is denied. */
final readonly class DocumentResultAuthorization
{
    public function __construct(private Connection $database, private EstimateGenerationActionAuthorization $authorization) {}

    public function denialReason(EstimateGenerationSession $session, int $fallbackUserId): ?string
    {
        if (in_array($session->status->value, ['cancelled', 'archived', 'applying', 'applied'], true)
            || (($session->input_payload['evaluation_mode'] ?? null) === 'universal'
                && ! config('estimate-generation.universal_enabled', false))) {
            return 'document_processing_stopped';
        }
        if (! app(EstimatorProductAccess::class)->available((int) $session->organization_id)) {
            return 'estimator_product_not_available';
        }
        $actor = EstimateGenerationExecutionActor::resolve($session->input_payload ?? [], $fallbackUserId);
        if ($actor === null) {
            return 'document_result_access_revoked';
        }
        try {
            $this->authorization->authorize($actor, $session, 'estimate_generation.generate');
        } catch (AuthorizationException) {
            return 'document_result_access_revoked';
        }
        $id = $session->input_payload['documents_credit_operation_id'] ?? null;
        if (is_string($id) && ! $this->database->table('estimate_generation_credit_operations')->where('operation_id', $id)
            ->where('organization_id', $session->organization_id)->where('project_id', $session->project_id)
            ->where('session_id', $session->id)->where('actor_user_id', $actor->id)->where('kind', 'documents')
            ->whereIn('status', ['reserved', 'running'])->exists()) {
            return 'document_processing_stopped';
        }

        return null;
    }
}
