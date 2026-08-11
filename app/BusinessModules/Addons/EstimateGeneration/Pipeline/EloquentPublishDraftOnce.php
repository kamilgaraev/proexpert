<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimate;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimateCommand;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use InvalidArgumentException;

class EloquentPublishDraftOnce implements PublishDraftOnce
{
    public function __construct(private ApplyGeneratedEstimate $apply) {}

    public function publish(
        string $sessionId,
        string $pipelineVersion,
        string $artifactHash,
    ): DraftPublicationResult {
        if (preg_match('/\A[1-9][0-9]*\z/D', $sessionId) !== 1) {
            throw new InvalidArgumentException('Pipeline publication session is invalid.');
        }
        PipelineVersionValidator::assertValid($pipelineVersion, 'pipeline publication');
        PipelineVersionValidator::assertSha256($artifactHash, 'publication artifact');

        $session = $this->loadSession((int) $sessionId);
        $result = $this->apply->handle(new ApplyGeneratedEstimateCommand(
            sessionId: (int) $session->getKey(),
            organizationId: (int) $session->organization_id,
            projectId: (int) $session->project_id,
            expectedStateVersion: (int) $session->state_version,
            idempotencyKey: 'pipeline:'.$pipelineVersion,
            artifactHash: $artifactHash,
        ));

        return new DraftPublicationResult(
            estimateId: $result->estimateId,
            created: $result->created,
            sessionId: $sessionId,
            pipelineVersion: $pipelineVersion,
            artifactHash: $artifactHash,
        );
    }

    protected function loadSession(int $sessionId): EstimateGenerationSession
    {
        return EstimateGenerationSession::query()->whereKey($sessionId)->firstOrFail();
    }
}
