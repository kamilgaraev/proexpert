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
use Throwable;

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
            permissions: $this->permissions($request, $session),
            readinessSummary: app(EstimatorReadinessService::class)->evaluate($session),
            documentsSummary: $this->documentsSummary($session),
        );

        return $snapshot->toArray();
    }

    /** @return list<string> */
    private function permissions(Request $request, EstimateGenerationSession $session): array
    {
        $user = $request->user();
        if ($user === null) {
            return [];
        }

        $cacheKey = 'estimate_generation_permissions_' . (int) $session->project_id;
        $cached = $request->attributes->get($cacheKey);
        if (is_array($cached)) {
            return array_values(array_filter($cached, 'is_string'));
        }

        $permissions = [];
        foreach ([
            'estimate_generation.upload_documents',
            'estimate_generation.generate',
            'estimate_generation.review',
            'estimate_generation.apply',
        ] as $permission) {
            try {
                if ($user->hasPermission($permission, ['project_id' => (int) $session->project_id])) {
                    $permissions[] = $permission;
                }
            } catch (Throwable) {
                continue;
            }
        }

        $request->attributes->set($cacheKey, $permissions);

        return $permissions;
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
