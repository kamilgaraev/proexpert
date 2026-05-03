<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Landing\Organization\StoreOrganizationRequest;
use App\Http\Requests\Api\V1\Landing\Organization\UpdateOrganizationRequest;
use App\Http\Resources\Api\V1\Landing\Organization\OrganizationResource;
use App\Http\Resources\Api\V1\Landing\Organization\OrganizationSummaryResource;
use App\Http\Responses\LandingResponse;
use App\Models\Organization;
use App\Models\User;
use App\Services\Storage\OrgBucketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

use function trans_message;

class OrganizationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            /** @var User|null $user */
            $user = $request->user();

            if (!$user) {
                return LandingResponse::error(
                    trans_message('organization.access_denied'),
                    Response::HTTP_FORBIDDEN
                );
            }

            $organizations = $user->organizations()->get();

            return LandingResponse::success(
                OrganizationSummaryResource::collection($organizations)->resolve($request),
                trans_message('organization.list_loaded')
            );
        } catch (\Throwable $e) {
            Log::error('landing.organization.index_failed', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(
                trans_message('organization.load_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function store(StoreOrganizationRequest $request, OrgBucketService $bucketService): JsonResponse
    {
        try {
            /** @var User $user */
            $user = $request->user();
            $validated = $request->validated();

            $organization = Organization::create($validated);
            $bucketService->createBucket($organization);

            $user->organizations()->syncWithoutDetaching([
                $organization->id => [
                    'is_owner' => true,
                    'is_active' => true,
                ],
            ]);

            $user->current_organization_id = $organization->id;
            $user->save();

            return LandingResponse::success(
                new OrganizationResource($organization->fresh()),
                trans_message('organization.created'),
                Response::HTTP_CREATED
            );
        } catch (\Throwable $e) {
            Log::error('landing.organization.store_failed', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(
                trans_message('organization.create_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function show(Request $request): JsonResponse
    {
        try {
            $organization = $this->currentOrganization($request);

            if (!$organization) {
                return LandingResponse::error(
                    trans_message('organization.not_found'),
                    Response::HTTP_NOT_FOUND
                );
            }

            return LandingResponse::success(
                new OrganizationResource($organization),
                trans_message('organization.loaded')
            );
        } catch (\Throwable $e) {
            Log::error('landing.organization.show_failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(
                trans_message('organization.load_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    public function update(UpdateOrganizationRequest $request): JsonResponse
    {
        try {
            $organization = $this->currentOrganization($request);

            if (!$organization) {
                return LandingResponse::error(
                    trans_message('organization.not_found'),
                    Response::HTTP_NOT_FOUND
                );
            }

            $organization->update($request->validated());

            return LandingResponse::success(
                new OrganizationResource($organization->refresh()),
                trans_message('organization.updated')
            );
        } catch (\Throwable $e) {
            Log::error('landing.organization.update_failed', [
                'user_id' => $request->user()?->id,
                'organization_id' => $request->user()?->current_organization_id,
                'error' => $e->getMessage(),
            ]);

            return LandingResponse::error(
                trans_message('organization.update_error'),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    private function currentOrganization(Request $request): ?Organization
    {
        $organization = $request->user()?->currentOrganization;

        return $organization instanceof Organization ? $organization : null;
    }
}
