<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Http\Controllers;

use App\BusinessModules\Core\Mdm\Http\Requests\ListMdmChangeRequestsRequest;
use App\BusinessModules\Core\Mdm\Http\Requests\MdmChangeRequestCommentRequest;
use App\BusinessModules\Core\Mdm\Http\Requests\ReviewMdmChangeRequest;
use App\BusinessModules\Core\Mdm\Http\Requests\StoreMdmChangeRequest;
use App\BusinessModules\Core\Mdm\Http\Requests\UpdateMdmChangeRequest;
use App\BusinessModules\Core\Mdm\Models\MdmChangeRequest;
use App\BusinessModules\Core\Mdm\Services\MdmChangeRequestService;
use App\BusinessModules\Core\Mdm\Services\MdmReadService;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MdmChangeRequestsController extends MdmBaseController
{
    public function __construct(
        private readonly MdmReadService $readService,
        private readonly MdmChangeRequestService $changeRequestService
    ) {}

    public function changeRequests(ListMdmChangeRequestsRequest $request): JsonResponse
    {
        return $this->handle($request, 'MDM change requests failed', 'mdm.errors.change_requests_failed', function () use ($request): JsonResponse {
            $organizationId = $request->organizationId();
            $requests = $this->changeRequestService->list($organizationId, $request->validated());

            return $this->paginated($requests, $this->readService->changeRequestSummary($organizationId));
        });
    }

    public function changeRequest(Request $request, MdmChangeRequest $mdmChangeRequest): JsonResponse
    {
        return $this->handle($request, 'MDM change request show failed', 'mdm.errors.change_requests_failed', fn (): JsonResponse => AdminResponse::success($this->changeRequestService->detail($mdmChangeRequest)));
    }

    public function previewChangeRequest(StoreMdmChangeRequest $request): JsonResponse
    {
        return $this->handle($request, 'MDM change request preview failed', 'mdm.errors.change_request_preview_failed', fn (): JsonResponse => AdminResponse::success($this->changeRequestService->preview($request->organizationId(), $request->validated())));
    }

    public function submitChangeRequest(StoreMdmChangeRequest $request): JsonResponse
    {
        return $this->handle($request, 'MDM change request submit failed', 'mdm.errors.change_request_submit_failed', function () use ($request): JsonResponse {
            $changeRequest = $this->changeRequestService->createDraft($request->organizationId(), $request->validated(), $request->user()?->id);

            return AdminResponse::success($changeRequest, trans_message('mdm.messages.change_request_created'), 201);
        });
    }

    public function updateChangeRequest(UpdateMdmChangeRequest $request, MdmChangeRequest $mdmChangeRequest): JsonResponse
    {
        return $this->handle($request, 'MDM change request update failed', 'mdm.errors.change_request_save_failed', function () use ($request, $mdmChangeRequest): JsonResponse {
            $updated = $this->changeRequestService->updateDraft($mdmChangeRequest, $request->validated(), $request->user()?->id);

            return AdminResponse::success($updated, trans_message('mdm.messages.change_request_saved'));
        });
    }

    public function submitDraftChangeRequest(MdmChangeRequestCommentRequest $request, MdmChangeRequest $mdmChangeRequest): JsonResponse
    {
        return $this->handle($request, 'MDM change request submit action failed', 'mdm.errors.change_request_submit_failed', function () use ($request, $mdmChangeRequest): JsonResponse {
            $submitted = $this->changeRequestService->submitDraft($mdmChangeRequest, $request->user()?->id, $request->validated('comment'));

            return AdminResponse::success($submitted, trans_message('mdm.messages.change_request_submitted'));
        });
    }

    public function startReviewChangeRequest(MdmChangeRequestCommentRequest $request, MdmChangeRequest $mdmChangeRequest): JsonResponse
    {
        return $this->handle($request, 'MDM change request review start failed', 'mdm.errors.change_request_review_failed', function () use ($request, $mdmChangeRequest): JsonResponse {
            $review = $this->changeRequestService->startReview($mdmChangeRequest, $request->user()?->id, $request->validated('comment'));

            return AdminResponse::success($review, trans_message('mdm.messages.change_request_review_started'));
        });
    }

    public function reviewChangeRequest(ReviewMdmChangeRequest $request, MdmChangeRequest $mdmChangeRequest): JsonResponse
    {
        return $this->handle($request, 'MDM change request review failed', 'mdm.errors.change_request_review_failed', function () use ($request, $mdmChangeRequest): JsonResponse {
            $validated = $request->validated();
            $reviewed = $validated['decision'] === 'approved'
                ? $this->changeRequestService->approve($mdmChangeRequest, $request->user()?->id, $validated['note'] ?? null)
                : $this->changeRequestService->reject($mdmChangeRequest, $request->user()?->id, $validated['note'] ?? null);

            return AdminResponse::success($reviewed, trans_message('mdm.messages.change_request_reviewed'));
        });
    }

    public function approveChangeRequest(MdmChangeRequestCommentRequest $request, MdmChangeRequest $mdmChangeRequest): JsonResponse
    {
        return $this->handle($request, 'MDM change request approve failed', 'mdm.errors.change_request_review_failed', function () use ($request, $mdmChangeRequest): JsonResponse {
            $approved = $this->changeRequestService->approve($mdmChangeRequest, $request->user()?->id, $request->validated('note'));

            return AdminResponse::success($approved, trans_message('mdm.messages.change_request_approved'));
        });
    }

    public function rejectChangeRequest(MdmChangeRequestCommentRequest $request, MdmChangeRequest $mdmChangeRequest): JsonResponse
    {
        return $this->handle($request, 'MDM change request reject failed', 'mdm.errors.change_request_review_failed', function () use ($request, $mdmChangeRequest): JsonResponse {
            $rejected = $this->changeRequestService->reject($mdmChangeRequest, $request->user()?->id, $request->validated('note'));

            return AdminResponse::success($rejected, trans_message('mdm.messages.change_request_rejected'));
        });
    }

    public function applyChangeRequest(MdmChangeRequestCommentRequest $request, MdmChangeRequest $mdmChangeRequest): JsonResponse
    {
        return $this->handle($request, 'MDM change request apply failed', 'mdm.errors.change_request_apply_failed', function () use ($request, $mdmChangeRequest): JsonResponse {
            $applied = $this->changeRequestService->applyApproved($mdmChangeRequest, $request->user()?->id, $request->validated('note'));

            return AdminResponse::success($applied, trans_message('mdm.messages.change_request_applied'));
        });
    }

    public function cancelChangeRequest(MdmChangeRequestCommentRequest $request, MdmChangeRequest $mdmChangeRequest): JsonResponse
    {
        return $this->handle($request, 'MDM change request cancel failed', 'mdm.errors.change_request_cancel_failed', function () use ($request, $mdmChangeRequest): JsonResponse {
            $cancelled = $this->changeRequestService->cancel($mdmChangeRequest, $request->user()?->id, $request->validated('reason'));

            return AdminResponse::success($cancelled, trans_message('mdm.messages.change_request_cancelled'));
        });
    }

    public function changeRequestTimeline(Request $request, MdmChangeRequest $mdmChangeRequest): JsonResponse
    {
        return $this->handle($request, 'MDM change request timeline failed', 'mdm.errors.change_request_timeline_failed', fn (): JsonResponse => AdminResponse::success($mdmChangeRequest->events()->with('actor:id,name,email')->get()));
    }

    public function changeRequestImpact(Request $request, MdmChangeRequest $mdmChangeRequest): JsonResponse
    {
        return $this->handle($request, 'MDM change request impact failed', 'mdm.errors.change_request_impact_failed', fn (): JsonResponse => AdminResponse::success($this->changeRequestService->refreshImpact($mdmChangeRequest)));
    }
}
