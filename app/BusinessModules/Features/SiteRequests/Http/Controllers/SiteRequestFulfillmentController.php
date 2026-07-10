<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SiteRequests\Http\Controllers;

use App\BusinessModules\Features\SiteRequests\Http\Requests\FulfillmentDecisionRequest;
use App\BusinessModules\Features\SiteRequests\Http\Resources\SiteRequestResource;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestFulfillmentService;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

use function trans_message;

class SiteRequestFulfillmentController extends Controller
{
    public function __construct(
        private readonly SiteRequestService $siteRequestService,
        private readonly SiteRequestFulfillmentService $fulfillmentService
    ) {}

    public function options(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $siteRequest = $this->siteRequestService->find($id, $organizationId, (int) $request->user()->id);

            if (! $siteRequest) {
                return AdminResponse::error(trans_message('site_requests.not_found'), 404);
            }

            return AdminResponse::success(
                $this->fulfillmentService->options($siteRequest, $request->user()),
                trans_message('site_requests.fulfillment.options_loaded')
            );
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            Log::error('site_requests.fulfillment.options.error', [
                'site_request_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('site_requests.fulfillment.options_error'), 500);
        }
    }

    public function decide(FulfillmentDecisionRequest $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $siteRequest = $this->siteRequestService->find($id, $organizationId, (int) $request->user()->id);

            if (! $siteRequest) {
                return AdminResponse::error(trans_message('site_requests.not_found'), 404);
            }

            $result = $this->fulfillmentService->decide($siteRequest, $request->user(), $request->validated());

            return AdminResponse::success(
                [
                    'site_request' => new SiteRequestResource($result['site_request']),
                    'decision' => $result['decision'],
                ],
                trans_message('site_requests.fulfillment.decision_saved')
            );
        } catch (AuthorizationException $exception) {
            return AdminResponse::error($exception->getMessage(), 403);
        } catch (ConflictHttpException $exception) {
            return AdminResponse::error($exception->getMessage(), 409);
        } catch (DomainException $exception) {
            return AdminResponse::error($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            Log::error('site_requests.fulfillment.decision.error', [
                'site_request_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return AdminResponse::error(trans_message('site_requests.fulfillment.decision_error'), 500);
        }
    }
}
