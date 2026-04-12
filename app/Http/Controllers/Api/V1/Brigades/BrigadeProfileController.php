<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Brigades;

use App\BusinessModules\Contractors\Brigades\Domain\Services\BrigadeWorkflowService;
use App\BusinessModules\Contractors\Brigades\Support\BrigadeStatuses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Brigades\UpdateBrigadeProfileRequest;
use App\Http\Resources\Brigades\BrigadeProfileResource;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class BrigadeProfileController extends Controller
{
    public function __construct(private readonly BrigadeWorkflowService $workflowService)
    {
    }

    public function show(): JsonResponse
    {
        try {
            $brigade = $this->workflowService->getOwnedBrigade(auth()->user());

            return AdminResponse::success(new BrigadeProfileResource($brigade));
        } catch (\Throwable $exception) {
            return AdminResponse::error(trans_message('brigades.profile_not_found'), 404);
        }
    }

    public function update(UpdateBrigadeProfileRequest $request): JsonResponse
    {
        try {
            $brigade = $this->workflowService->getOwnedBrigade($request->user());
            $data = $request->validated();

            $brigade->fill(collect($data)->except(['specializations', 'submit_for_review'])->all());

            if (($data['submit_for_review'] ?? false) === true) {
                $brigade->verification_status = BrigadeStatuses::PROFILE_PENDING_REVIEW;
            }

            $brigade->save();

            if (array_key_exists('specializations', $data)) {
                $this->workflowService->syncSpecializations($brigade, $data['specializations']);
            }

            return AdminResponse::success(
                new BrigadeProfileResource($brigade->load(['specializations', 'members', 'documents'])),
                trans_message('brigades.profile_updated')
            );
        } catch (\Throwable $exception) {
            Log::error('Brigade profile update failed', ['error' => $exception->getMessage()]);

            return AdminResponse::error(trans_message('brigades.profile_update_failed'), 500);
        }
    }
}
