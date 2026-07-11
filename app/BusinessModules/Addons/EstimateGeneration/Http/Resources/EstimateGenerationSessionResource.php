<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Resources;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\BuildSessionSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SessionSnapshotData;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\EstimatorReadinessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class EstimateGenerationSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        if ($this->resource instanceof SessionSnapshotData) {
            return $this->resource->toArray();
        }

        /** @var EstimateGenerationSession $session */
        $session = $this->resource;
        $snapshot = app(BuildSessionSnapshot::class)->handle(
            session: $session,
            permissions: EstimateGenerationSessionListResource::permissions($request, $session),
            readinessSummary: app(EstimatorReadinessService::class)->evaluate($session),
            documentsSummary: $this->documentsSummary($session),
        );

        return $snapshot->toArray();
    }

    /** @return array<string, mixed> */
    private function documentsSummary(EstimateGenerationSession $session): array
    {
        if (!$session->relationLoaded('documents')) {
            return [];
        }

        return app(DocumentGenerationReadinessService::class)->evaluate($session)['summary'] ?? [];
    }
}
