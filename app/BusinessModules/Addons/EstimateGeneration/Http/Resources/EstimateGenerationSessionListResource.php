<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Resources;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\BuildSessionSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Throwable;

final class EstimateGenerationSessionListResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var EstimateGenerationSession $session */
        $session = $this->resource;

        return app(BuildSessionSnapshot::class)->handle(
            session: $session,
            permissions: self::permissions($request, $session),
        )->toArray();
    }

    /** @return list<string> */
    public static function permissions(Request $request, EstimateGenerationSession $session): array
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
            'estimate_generation.view',
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
}
