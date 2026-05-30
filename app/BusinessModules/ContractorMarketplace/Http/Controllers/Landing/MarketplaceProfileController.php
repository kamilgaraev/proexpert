<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Http\Controllers\Landing;

use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceContractorDocument;
use App\BusinessModules\ContractorMarketplace\Domain\Services\MarketplaceProfileService;
use App\BusinessModules\ContractorMarketplace\Http\Requests\Landing\StoreMarketplaceDocumentRequest;
use App\BusinessModules\ContractorMarketplace\Http\Requests\Landing\UpdateMarketplaceProfileRequest;
use App\BusinessModules\ContractorMarketplace\Http\Resources\MarketplaceContractorProfileResource;
use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Responses\LandingResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class MarketplaceProfileController extends Controller
{
    public function __construct(
        private readonly MarketplaceProfileService $profileService
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);

        if ($organizationId === null) {
            return LandingResponse::error(trans_message('contractor_marketplace.organization_context_missing'), 400);
        }

        try {
            return LandingResponse::success(
                new MarketplaceContractorProfileResource($this->profileService->getOrCreateForOrganization($organizationId))
            );
        } catch (\Throwable $e) {
            Log::error('Failed to fetch marketplace profile', [
                'error' => $e->getMessage(),
                'organization_id' => $organizationId,
            ]);

            return LandingResponse::error(trans_message('contractor_marketplace.profile_load_error'), 500);
        }
    }

    public function update(UpdateMarketplaceProfileRequest $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);

        if ($organizationId === null) {
            return LandingResponse::error(trans_message('contractor_marketplace.organization_context_missing'), 400);
        }

        try {
            return LandingResponse::success(
                new MarketplaceContractorProfileResource(
                    $this->profileService->updateForOrganization($organizationId, $request->validated())
                ),
                trans_message('contractor_marketplace.profile_saved')
            );
        } catch (BusinessLogicException $e) {
            return LandingResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Log::error('Failed to update marketplace profile', [
                'error' => $e->getMessage(),
                'organization_id' => $organizationId,
            ]);

            return LandingResponse::error(trans_message('contractor_marketplace.profile_save_error'), 500);
        }
    }

    public function publish(Request $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);

        if ($organizationId === null) {
            return LandingResponse::error(trans_message('contractor_marketplace.organization_context_missing'), 400);
        }

        try {
            return LandingResponse::success(
                new MarketplaceContractorProfileResource($this->profileService->publish($organizationId, $this->resolveUser($request))),
                trans_message('contractor_marketplace.profile_published')
            );
        } catch (BusinessLogicException $e) {
            return LandingResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Log::error('Failed to publish marketplace profile', [
                'error' => $e->getMessage(),
                'organization_id' => $organizationId,
            ]);

            return LandingResponse::error(trans_message('contractor_marketplace.profile_publish_error'), 500);
        }
    }

    public function pause(Request $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);

        if ($organizationId === null) {
            return LandingResponse::error(trans_message('contractor_marketplace.organization_context_missing'), 400);
        }

        try {
            return LandingResponse::success(
                new MarketplaceContractorProfileResource($this->profileService->pause($organizationId, $this->resolveUser($request))),
                trans_message('contractor_marketplace.profile_paused')
            );
        } catch (BusinessLogicException $e) {
            return LandingResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Log::error('Failed to pause marketplace profile', [
                'error' => $e->getMessage(),
                'organization_id' => $organizationId,
            ]);

            return LandingResponse::error(trans_message('contractor_marketplace.profile_pause_error'), 500);
        }
    }

    public function uploadDocument(StoreMarketplaceDocumentRequest $request): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);

        if ($organizationId === null) {
            return LandingResponse::error(trans_message('contractor_marketplace.organization_context_missing'), 400);
        }

        try {
            $file = $request->file('document');

            if (! $file instanceof UploadedFile) {
                return LandingResponse::error(trans_message('contractor_marketplace.document_file_invalid'), 422);
            }

            return LandingResponse::success(
                new MarketplaceContractorProfileResource($this->profileService->uploadDocumentForOrganization(
                    $organizationId,
                    $file,
                    $request->validated(),
                    $this->resolveUser($request)
                )),
                trans_message('contractor_marketplace.document_uploaded'),
                201
            );
        } catch (BusinessLogicException $e) {
            return LandingResponse::error($e->getMessage(), (int) $e->getCode() ?: 422);
        } catch (\Throwable $e) {
            Log::error('Failed to upload marketplace document', [
                'error' => $e->getMessage(),
                'organization_id' => $organizationId,
                'user_id' => $request->user()?->id,
            ]);

            return LandingResponse::error(trans_message('contractor_marketplace.document_upload_failed'), 500);
        }
    }

    public function deleteDocument(Request $request, MarketplaceContractorDocument $document): JsonResponse
    {
        $organizationId = $this->resolveOrganizationId($request);

        if ($organizationId === null) {
            return LandingResponse::error(trans_message('contractor_marketplace.organization_context_missing'), 400);
        }

        try {
            return LandingResponse::success(
                new MarketplaceContractorProfileResource($this->profileService->deleteDocumentForOrganization($organizationId, $document)),
                trans_message('contractor_marketplace.document_deleted')
            );
        } catch (BusinessLogicException $e) {
            return LandingResponse::error($e->getMessage(), (int) $e->getCode() ?: 422);
        } catch (\Throwable $e) {
            Log::error('Failed to delete marketplace document', [
                'error' => $e->getMessage(),
                'organization_id' => $organizationId,
                'document_id' => $document->id,
                'user_id' => $request->user()?->id,
            ]);

            return LandingResponse::error(trans_message('contractor_marketplace.document_delete_failed'), 500);
        }
    }

    private function resolveOrganizationId(Request $request): ?int
    {
        $organizationId = $request->attributes->get('current_organization_id')
            ?? $request->user()?->current_organization_id;

        return $organizationId ? (int) $organizationId : null;
    }

    private function resolveUser(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
