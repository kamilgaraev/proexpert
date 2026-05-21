<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Responses\LandingResponse;
use App\Services\Landing\PackageService;
use App\Exceptions\Billing\InsufficientBalanceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;

use function trans_message;

class OrganizationPackageController
{
    public function __construct(
        private readonly PackageService $packageService
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;

            $packages = $this->packageService->getAllPackages($organizationId);

            return LandingResponse::success($packages, trans_message('landing.packages.loaded'));
        } catch (\Exception $e) {
            Log::error('PackageController@index: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return LandingResponse::error(trans_message('landing.packages.load_error'), 500);
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
            $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;

            $result = $this->packageService->subscribeToPackage(
                $organizationId,
                $validated['package_slug'],
                $validated['tier'],
                $validated['duration_days'] ?? 30
            );

            return LandingResponse::success($result, trans_message('landing.packages.subscribe_success'));
        } catch (\InvalidArgumentException $e) {
            return LandingResponse::error(trans_message('errors.business_logic_error'), 422);
        } catch (InsufficientBalanceException $e) {
            return LandingResponse::error(trans_message('errors.insufficient_balance'), 402);
        } catch (\RuntimeException $e) {
            Log::error('PackageController@subscribe: '.$e->getMessage(), [
                'request' => $request->all(),
            ]);

            return LandingResponse::error(trans_message('errors.resource_not_found'), 404);
        } catch (\Exception $e) {
            Log::error('PackageController@subscribe: '.$e->getMessage(), [
                'request' => $request->all(),
                'trace' => $e->getTraceAsString(),
            ]);

            return LandingResponse::error(trans_message('landing.packages.subscribe_error'), 500);
        }
    }

    public function unsubscribe(Request $request, string $packageSlug): JsonResponse
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;

            $this->packageService->unsubscribeFromPackage($organizationId, $packageSlug);

            return LandingResponse::success(null, trans_message('landing.packages.unsubscribe_success'));
        } catch (\RuntimeException $e) {
            return LandingResponse::error(trans_message('errors.resource_not_found'), 404);
        } catch (\Exception $e) {
            Log::error('PackageController@unsubscribe: '.$e->getMessage(), [
                'package_slug' => $packageSlug,
                'trace' => $e->getTraceAsString(),
            ]);

            return LandingResponse::error(trans_message('landing.packages.unsubscribe_error'), 500);
        }
    }
}
