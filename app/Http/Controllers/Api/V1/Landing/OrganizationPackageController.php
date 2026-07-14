<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing;

use App\Exceptions\BusinessLogicException;
use App\Http\Responses\LandingResponse;
use App\Models\User;
use App\Services\Billing\PackageTrialService;
use App\Services\Landing\PackageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

use function trans_message;

class OrganizationPackageController
{
    public function __construct(
        private readonly PackageService $packageService,
        private readonly PackageTrialService $packageTrialService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (! $user instanceof User) {
                return LandingResponse::error(
                    trans_message('landing.not_authenticated'),
                    Response::HTTP_UNAUTHORIZED,
                );
            }
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

    public function startTrial(Request $request, string $packageSlug): JsonResponse
    {
        try {
            $user = $request->user();

            if (! $user instanceof User) {
                return LandingResponse::error(
                    trans_message('landing.not_authenticated'),
                    Response::HTTP_UNAUTHORIZED,
                );
            }
            $organizationId = $request->attributes->get('current_organization_id') ?? $user->current_organization_id;

            if (! is_numeric($organizationId)) {
                return LandingResponse::error(
                    trans_message('landing.organization_context_missing'),
                    Response::HTTP_FORBIDDEN,
                );
            }

            $subscription = $this->packageTrialService->start((int) $organizationId, $packageSlug, (int) $user->id);

            return LandingResponse::success([
                'package_slug' => $subscription->package_slug,
                'status' => $subscription->status->value,
                'access_source' => $subscription->access_source->value,
                'trial_started_at' => $subscription->trial_started_at?->toISOString(),
                'trial_ends_at' => $subscription->trial_ends_at?->toISOString(),
                'duration_hours' => (int) config('commercial_offers.trial_hours', 72),
            ], trans_message('landing.packages.trial_started'), Response::HTTP_CREATED);
        } catch (BusinessLogicException $exception) {
            return LandingResponse::error($exception->getMessage(), $exception->getCode());
        } catch (\Throwable $exception) {
            Log::error('PackageController@startTrial failed.', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'package_slug' => $packageSlug,
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);

            return LandingResponse::error(trans_message('landing.packages.trial_start_error'), 500);
        }
    }
}
