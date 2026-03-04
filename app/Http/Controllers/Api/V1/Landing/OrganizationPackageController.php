<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Responses\LandingResponse;
use App\Services\Landing\PackageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;

class OrganizationPackageController
{
    public function __construct(
        private readonly PackageService $packageService
    ) {}

    public function index(): JsonResponse
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $organizationId = (int) $user->organization_id;

            $packages = $this->packageService->getAllPackages($organizationId);

            return LandingResponse::success($packages);
        } catch (\Exception $e) {
            Log::error('PackageController@index: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return LandingResponse::error('Не удалось получить список пакетов', 500);
        }
    }

    public function subscribe(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'package_slug' => 'required|string|max:100',
            'tier' => 'required|in:base,pro,enterprise',
            'duration_days' => 'nullable|integer|min:1|max:365',
        ]);

        try {
            $user = JWTAuth::parseToken()->authenticate();
            $organizationId = (int) $user->organization_id;

            $result = $this->packageService->subscribeToPackage(
                $organizationId,
                $validated['package_slug'],
                $validated['tier'],
                $validated['duration_days'] ?? 30
            );

            return LandingResponse::success($result, 'Пакет успешно подключён');
        } catch (\InvalidArgumentException $e) {
            return LandingResponse::error($e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            Log::error('PackageController@subscribe: '.$e->getMessage(), [
                'request' => $request->all(),
            ]);

            return LandingResponse::error($e->getMessage(), 404);
        } catch (\Exception $e) {
            Log::error('PackageController@subscribe: '.$e->getMessage(), [
                'request' => $request->all(),
                'trace' => $e->getTraceAsString(),
            ]);

            return LandingResponse::error('Не удалось подключить пакет', 500);
        }
    }

    public function unsubscribe(string $packageSlug): JsonResponse
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $organizationId = (int) $user->organization_id;

            $this->packageService->unsubscribeFromPackage($organizationId, $packageSlug);

            return LandingResponse::success(null, 'Пакет успешно отключён');
        } catch (\RuntimeException $e) {
            return LandingResponse::error($e->getMessage(), 404);
        } catch (\Exception $e) {
            Log::error('PackageController@unsubscribe: '.$e->getMessage(), [
                'package_slug' => $packageSlug,
                'trace' => $e->getTraceAsString(),
            ]);

            return LandingResponse::error('Не удалось отключить пакет', 500);
        }
    }
}
