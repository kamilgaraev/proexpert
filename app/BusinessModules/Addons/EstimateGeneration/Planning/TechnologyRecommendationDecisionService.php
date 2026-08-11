<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ApplyProjectModelDecision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use InvalidArgumentException;

final readonly class TechnologyRecommendationDecisionService
{
    public function __construct(private ApplyProjectModelDecision $decisions) {}

    public function respond(
        TechnologyRecommendation $recommendation,
        string $response,
        ?string $other,
        string $actorId,
        string $reason,
        string $decisionId,
    ): ?Decision {
        if ($response === 'leave_unresolved') {
            return null;
        }
        if ($response === 'other') {
            if ($other === null || trim($other) === '' || mb_strlen($other) > 500) {
                throw new InvalidArgumentException('Other technology system is invalid.');
            }
            $value = [
                'kind' => 'other',
                'other' => trim($other),
                'catalog_version' => $recommendation->catalogVersion,
                'catalog_hash' => $recommendation->catalogHash,
            ];
        } else {
            $option = null;
            foreach ($recommendation->options as $candidate) {
                if ($candidate instanceof TechnologySystemOption && $candidate->system->id === $response) {
                    $option = $candidate;
                    break;
                }
            }
            if (! $option instanceof TechnologySystemOption) {
                throw new InvalidArgumentException('Technology system response is invalid.');
            }
            $value = [
                'kind' => 'catalog_system',
                'system_id' => $option->system->id,
                'catalog_version' => $recommendation->catalogVersion,
                'catalog_hash' => $recommendation->catalogHash,
                'provenance' => $option->system->provenance,
            ];
        }

        return $this->decisions->apply(
            organizationId: $recommendation->organizationId,
            projectId: $recommendation->projectId,
            sessionId: $recommendation->sessionId,
            sourceVersion: $recommendation->sourceVersion,
            factId: $recommendation->targetFactId,
            value: $value,
            unit: null,
            actorId: $actorId,
            reason: $reason,
            decisionId: $decisionId,
        );
    }
}
