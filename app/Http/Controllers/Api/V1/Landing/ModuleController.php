<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use App\Models\Module;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Landing\ModulesOverviewService;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

use function trans_message;

final class ModuleController extends Controller
{
    public function __construct(
        private readonly OrganizationEntitlementService $entitlements,
        private readonly ModulesOverviewService $overviewService,
        private readonly PackageCatalogService $catalog,
    ) {}

    public function overview(Request $request): JsonResponse
    {
        return $this->respond($request, fn (int $organizationId): array => $this->overviewService->build($organizationId));
    }

    public function index(Request $request): JsonResponse
    {
        return $this->respond($request, function (int $organizationId): array {
            $active = $this->entitlements->getEffectiveModuleSlugs($organizationId);

            return Module::query()
                ->where('is_active', true)
                ->orderBy('display_order')
                ->orderBy('id')
                ->get()
                ->map(static fn (Module $module): array => [
                    'slug' => $module->slug,
                    'name' => $module->name,
                    'description' => $module->description,
                    'category' => $module->category,
                    'icon' => $module->icon,
                    'is_active' => in_array($module->slug, $active, true),
                    'permissions' => $module->permissions ?? [],
                    'development_status' => $module->getDevelopmentStatusInfo(),
                ])
                ->values()
                ->all();
        });
    }

    public function active(Request $request): JsonResponse
    {
        return $this->respond(
            $request,
            fn (int $organizationId): array => Module::toPublicCollection(
                $this->entitlements->getEffectiveModules($organizationId),
            )->all(),
        );
    }

    public function checkAccess(Request $request): JsonResponse
    {
        $validated = $request->validate(['module_slug' => ['required', 'string']]);

        return $this->respond($request, fn (int $organizationId): array => [
            'module_slug' => $validated['module_slug'],
            'has_access' => $this->entitlements->hasModuleAccess($organizationId, $validated['module_slug']),
        ]);
    }

    public function permissions(Request $request): JsonResponse
    {
        return $this->respond($request, fn (int $organizationId): array => $this->entitlements
            ->getEffectiveModules($organizationId)
            ->flatMap(static fn (Module $module): array => (array) $module->permissions)
            ->unique()
            ->values()
            ->all());
    }

    public function getBundledModules(Request $request): JsonResponse
    {
        return $this->respond($request, fn (): array => collect($this->catalog->allPackages())
            ->mapWithKeys(static fn (array $package): array => [
                $package['slug'] => $package['tiers']['standard']['included_modules']
                    ?? $package['tiers']['standard']['modules']
                    ?? [],
            ])
            ->all());
    }

    private function respond(Request $request, callable $resolver): JsonResponse
    {
        try {
            $organizationId = (int) ($request->attributes->get('organization_id')
                ?? $request->user()?->current_organization_id);

            if ($organizationId <= 0) {
                return LandingResponse::error(trans_message('organizations.not_found'), 404);
            }

            return LandingResponse::success($resolver($organizationId));
        } catch (Throwable $exception) {
            Log::error('Failed to read organization module access.', [
                'organization_id' => $request->attributes->get('organization_id'),
                'user_id' => $request->user()?->id,
                'exception' => $exception->getMessage(),
            ]);

            return LandingResponse::error(trans_message('landing_modules.load_error'), 500);
        }
    }
}
