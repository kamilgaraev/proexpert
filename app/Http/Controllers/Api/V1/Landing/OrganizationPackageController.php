<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Responses\LandingResponse;
use App\Services\Landing\PackageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
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

            if (! is_numeric($organizationId)) {
                return LandingResponse::error(
                    trans_message('landing.organization_context_missing'),
                    Response::HTTP_FORBIDDEN
                );
            }

            $packages = $this->packageService->getAllPackages((int) $organizationId);

            return LandingResponse::success($packages, trans_message('landing.packages.loaded'));
        } catch (\Exception $e) {
            Log::error('PackageController@index: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return LandingResponse::error(trans_message('landing.packages.load_error'), 500);
        }
    }
}
