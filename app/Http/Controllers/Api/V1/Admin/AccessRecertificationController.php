<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\BusinessModules\Core\AccessRecertification\Models\AccessRecertificationCampaign;
use App\BusinessModules\Core\AccessRecertification\Models\AccessRecertificationException;
use App\BusinessModules\Core\AccessRecertification\Models\AccessRecertificationItem;
use App\BusinessModules\Core\AccessRecertification\Models\AccessRecertificationRevocation;
use App\BusinessModules\Core\AccessRecertification\Services\AccessRecertificationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AccessRecertification\AccessRecertificationCampaignIndexRequest;
use App\Http\Requests\Api\V1\Admin\AccessRecertification\AccessRecertificationCampaignRequest;
use App\Http\Requests\Api\V1\Admin\AccessRecertification\AccessRecertificationDecisionRequest;
use App\Http\Requests\Api\V1\Admin\AccessRecertification\AccessRecertificationExceptionDecisionRequest;
use App\Http\Requests\Api\V1\Admin\AccessRecertification\AccessRecertificationExceptionIndexRequest;
use App\Http\Requests\Api\V1\Admin\AccessRecertification\AccessRecertificationItemIndexRequest;
use App\Http\Requests\Api\V1\Admin\AccessRecertification\AccessRecertificationReassignRequest;
use App\Http\Requests\Api\V1\Admin\AccessRecertification\AccessRecertificationReportRequest;
use App\Http\Requests\Api\V1\Admin\AccessRecertification\AccessRecertificationRevocationCompleteRequest;
use App\Http\Requests\Api\V1\Admin\AccessRecertification\AccessRecertificationRevocationIndexRequest;
use App\Http\Resources\Api\V1\Admin\AccessRecertification\AccessRecertificationCampaignResource;
use App\Http\Resources\Api\V1\Admin\AccessRecertification\AccessRecertificationDecisionResource;
use App\Http\Resources\Api\V1\Admin\AccessRecertification\AccessRecertificationExceptionResource;
use App\Http\Resources\Api\V1\Admin\AccessRecertification\AccessRecertificationItemResource;
use App\Http\Resources\Api\V1\Admin\AccessRecertification\AccessRecertificationRevocationResource;
use App\Http\Responses\AdminResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

use function trans_message;

final class AccessRecertificationController extends Controller
{
    public function __construct(
        private readonly AccessRecertificationService $service,
    ) {}

    public function index(AccessRecertificationCampaignIndexRequest $request): JsonResponse
    {
        try {
            $campaigns = $this->service->campaigns(
                $this->organizationId($request),
                $request->validated(),
                $this->perPage($request)
            );

            return AdminResponse::paginated(
                AccessRecertificationCampaignResource::collection($campaigns->getCollection()),
                $this->meta($campaigns),
                trans_message('access_recertification.campaigns_loaded'),
                200,
                $this->service->campaignSummary($this->organizationId($request))
            );
        } catch (Throwable $e) {
            return $this->fail($request, $e, 'campaigns.index', 'campaigns_load_error');
        }
    }

    public function store(AccessRecertificationCampaignRequest $request): JsonResponse
    {
        try {
            $campaign = $this->service->createCampaign(
                $this->organizationId($request),
                $request->user(),
                $request->validated()
            );

            return AdminResponse::success(
                new AccessRecertificationCampaignResource($campaign->load(['owner', 'createdBy'])),
                trans_message('access_recertification.campaign_created'),
                201
            );
        } catch (Throwable $e) {
            return $this->fail($request, $e, 'campaigns.store', 'campaign_create_error');
        }
    }

    public function show(Request $request, AccessRecertificationCampaign $campaign): JsonResponse
    {
        try {
            $organizationId = $this->organizationId($request);
            $this->service->assertCampaignOrganization($campaign, $organizationId);

            $campaign->load(['owner', 'escalationUser', 'createdBy'])->loadCount([
                'items',
                'items as pending_items_count' => fn ($query) => $query->whereIn('status', ['pending', 'escalated']),
                'items as overdue_items_count' => fn ($query) => $query->whereIn('status', ['pending', 'escalated'])->where('due_at', '<', now()),
                'revocations as pending_revocations_count' => fn ($query) => $query->where('status', 'pending'),
                'exceptions as requested_exceptions_count' => fn ($query) => $query->where('status', 'requested'),
            ]);

            return AdminResponse::success([
                'campaign' => new AccessRecertificationCampaignResource($campaign),
                'summary' => $this->service->report($organizationId, ['campaign_id' => $campaign->id])['summary'],
            ], trans_message('access_recertification.campaign_loaded'));
        } catch (Throwable $e) {
            return $this->fail($request, $e, 'campaigns.show', 'campaign_load_error');
        }
    }

    public function update(AccessRecertificationCampaignRequest $request, AccessRecertificationCampaign $campaign): JsonResponse
    {
        try {
            $updated = $this->service->updateCampaign(
                $campaign,
                $this->organizationId($request),
                $request->user(),
                $request->validated()
            );

            return AdminResponse::success(
                new AccessRecertificationCampaignResource($updated->load(['owner', 'createdBy'])),
                trans_message('access_recertification.campaign_updated')
            );
        } catch (Throwable $e) {
            return $this->fail($request, $e, 'campaigns.update', 'campaign_update_error');
        }
    }

    public function launch(Request $request, AccessRecertificationCampaign $campaign): JsonResponse
    {
        try {
            $launched = $this->service->launchCampaign($campaign, $this->organizationId($request), $request->user());

            return AdminResponse::success(
                new AccessRecertificationCampaignResource($launched->load(['owner', 'createdBy'])),
                trans_message('access_recertification.campaign_launched')
            );
        } catch (Throwable $e) {
            return $this->fail($request, $e, 'campaigns.launch', 'campaign_launch_error');
        }
    }

    public function complete(Request $request, AccessRecertificationCampaign $campaign): JsonResponse
    {
        try {
            $completed = $this->service->completeCampaign($campaign, $this->organizationId($request), $request->user());

            return AdminResponse::success(
                new AccessRecertificationCampaignResource($completed->load(['owner', 'createdBy'])),
                trans_message('access_recertification.campaign_completed')
            );
        } catch (Throwable $e) {
            return $this->fail($request, $e, 'campaigns.complete', 'campaign_complete_error');
        }
    }

    public function cancel(Request $request, AccessRecertificationCampaign $campaign): JsonResponse
    {
        try {
            $cancelled = $this->service->cancelCampaign(
                $campaign,
                $this->organizationId($request),
                $request->user(),
                $request->input('reason')
            );

            return AdminResponse::success(
                new AccessRecertificationCampaignResource($cancelled->load(['owner', 'createdBy'])),
                trans_message('access_recertification.campaign_cancelled')
            );
        } catch (Throwable $e) {
            return $this->fail($request, $e, 'campaigns.cancel', 'campaign_cancel_error');
        }
    }

    public function items(AccessRecertificationItemIndexRequest $request, AccessRecertificationCampaign $campaign): JsonResponse
    {
        try {
            $items = $this->service->items($campaign, $this->organizationId($request), $request->validated(), $this->perPage($request));

            return AdminResponse::paginated(
                AccessRecertificationItemResource::collection($items->getCollection()),
                $this->meta($items),
                trans_message('access_recertification.items_loaded')
            );
        } catch (Throwable $e) {
            return $this->fail($request, $e, 'items.index', 'items_load_error');
        }
    }

    public function reviewQueue(AccessRecertificationItemIndexRequest $request): JsonResponse
    {
        try {
            $items = $this->service->reviewQueue(
                $this->organizationId($request),
                $request->user(),
                $request->validated(),
                $this->perPage($request)
            );

            return AdminResponse::paginated(
                AccessRecertificationItemResource::collection($items->getCollection()),
                $this->meta($items),
                trans_message('access_recertification.items_loaded')
            );
        } catch (Throwable $e) {
            return $this->fail($request, $e, 'reviews.my', 'items_load_error');
        }
    }

    public function decide(AccessRecertificationDecisionRequest $request, AccessRecertificationItem $item): JsonResponse
    {
        try {
            $decision = $this->service->decide($item, $this->organizationId($request), $request->user(), $request->validated());

            return AdminResponse::success(
                new AccessRecertificationDecisionResource($decision),
                trans_message('access_recertification.decision_saved')
            );
        } catch (Throwable $e) {
            return $this->fail($request, $e, 'items.decide', 'decision_save_error');
        }
    }

    public function reassign(AccessRecertificationReassignRequest $request, AccessRecertificationItem $item): JsonResponse
    {
        try {
            $updated = $this->service->reassign(
                $item,
                $this->organizationId($request),
                $request->user(),
                (int) $request->validated('reviewer_user_id'),
                $request->validated('reason')
            );

            return AdminResponse::success(
                new AccessRecertificationItemResource($updated),
                trans_message('access_recertification.reviewer_reassigned')
            );
        } catch (Throwable $e) {
            return $this->fail($request, $e, 'items.reassign', 'reviewer_reassign_error');
        }
    }

    public function revocations(AccessRecertificationRevocationIndexRequest $request): JsonResponse
    {
        try {
            $revocations = $this->service->revocations($this->organizationId($request), $request->validated(), $this->perPage($request));

            return AdminResponse::paginated(
                AccessRecertificationRevocationResource::collection($revocations->getCollection()),
                $this->meta($revocations),
                trans_message('access_recertification.revocations_loaded')
            );
        } catch (Throwable $e) {
            return $this->fail($request, $e, 'revocations.index', 'revocations_load_error');
        }
    }

    public function completeRevocation(
        AccessRecertificationRevocationCompleteRequest $request,
        AccessRecertificationRevocation $revocation
    ): JsonResponse {
        try {
            $completed = $this->service->completeRevocation(
                $revocation,
                $this->organizationId($request),
                $request->user(),
                $request->validated()
            );

            return AdminResponse::success(
                new AccessRecertificationRevocationResource($completed),
                trans_message('access_recertification.revocation_completed')
            );
        } catch (Throwable $e) {
            return $this->fail($request, $e, 'revocations.complete', 'revocation_complete_error');
        }
    }

    public function exceptions(AccessRecertificationExceptionIndexRequest $request): JsonResponse
    {
        try {
            $exceptions = $this->service->exceptions($this->organizationId($request), $request->validated(), $this->perPage($request));

            return AdminResponse::paginated(
                AccessRecertificationExceptionResource::collection($exceptions->getCollection()),
                $this->meta($exceptions),
                trans_message('access_recertification.exceptions_loaded')
            );
        } catch (Throwable $e) {
            return $this->fail($request, $e, 'exceptions.index', 'exceptions_load_error');
        }
    }

    public function decideException(
        AccessRecertificationExceptionDecisionRequest $request,
        AccessRecertificationException $exception
    ): JsonResponse {
        try {
            $updated = $this->service->decideException(
                $exception,
                $this->organizationId($request),
                $request->user(),
                $request->validated('status'),
                $request->validated('reason')
            );

            return AdminResponse::success(
                new AccessRecertificationExceptionResource($updated),
                trans_message('access_recertification.exception_decided')
            );
        } catch (Throwable $e) {
            return $this->fail($request, $e, 'exceptions.decide', 'exception_decide_error');
        }
    }

    public function report(AccessRecertificationReportRequest $request): JsonResponse
    {
        try {
            return AdminResponse::success(
                $this->service->report($this->organizationId($request), $request->validated()),
                trans_message('access_recertification.report_loaded')
            );
        } catch (Throwable $e) {
            return $this->fail($request, $e, 'reports.summary', 'report_load_error');
        }
    }

    public function export(AccessRecertificationReportRequest $request): StreamedResponse|JsonResponse
    {
        try {
            return $this->service->exportEvidence(
                $this->organizationId($request),
                $request->user(),
                $request->validated()
            );
        } catch (Throwable $e) {
            return $this->fail($request, $e, 'reports.export', 'export_error');
        }
    }

    private function organizationId(Request $request): int
    {
        return (int) $request->attributes->get('current_organization_id');
    }

    private function perPage(Request $request): int
    {
        return min((int) $request->input('per_page', 25), 100);
    }

    private function meta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    private function fail(Request $request, Throwable $e, string $operation, string $messageKey): JsonResponse
    {
        Log::error('access_recertification.' . $operation . '.error', [
            'user_id' => $request->user()?->id,
            'organization_id' => $request->attributes->get('current_organization_id'),
            'message' => $e->getMessage(),
        ]);

        if ($e instanceof InvalidArgumentException) {
            return AdminResponse::error(trans_message('access_recertification.errors.' . $e->getMessage()), 422);
        }

        return AdminResponse::error(trans_message('access_recertification.' . $messageKey), 500);
    }
}
