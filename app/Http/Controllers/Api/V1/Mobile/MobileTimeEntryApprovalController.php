<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Exceptions\BusinessLogicException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Mobile\ApproveMobileTimeEntryRequest;
use App\Http\Requests\Api\V1\Mobile\ListMobileTimeEntryApprovalsRequest;
use App\Http\Requests\Api\V1\Mobile\RejectMobileTimeEntryRequest;
use App\Http\Responses\MobileResponse;
use App\Models\User;
use App\Services\Mobile\MobileTimeEntryApprovalService;
use Illuminate\Http\JsonResponse;

final class MobileTimeEntryApprovalController extends Controller
{
    public function __construct(private readonly MobileTimeEntryApprovalService $service) {}

    public function index(ListMobileTimeEntryApprovalsRequest $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return MobileResponse::error(trans_message('errors.unauthenticated'), 401);
        }
        try {
            $result = $this->service->pending(
                $actor,
                (int) $request->attributes->get('current_organization_id'),
                $request->validated(),
            );
        } catch (BusinessLogicException $exception) {
            return $this->businessError($exception);
        } catch (\DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), in_array($exception->getCode(), [403, 409, 422], true) ? $exception->getCode() : 422);
        }

        return MobileResponse::paginated($result['items'], $result['meta']);
    }

    public function approve(ApproveMobileTimeEntryRequest $request, int $entry): JsonResponse
    {
        return $this->decide($request, $entry, 'approve', null);
    }

    public function reject(RejectMobileTimeEntryRequest $request, int $entry): JsonResponse
    {
        return $this->decide($request, $entry, 'reject', $request->validated('reason'));
    }

    private function decide(\Illuminate\Http\Request $request, int $entry, string $action, ?string $reason): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return MobileResponse::error(trans_message('errors.unauthenticated'), 401);
        }

        try {
            return MobileResponse::success($this->service->decide(
                $actor,
                (int) $request->attributes->get('current_organization_id'),
                $entry,
                $action,
                $reason,
            ));
        } catch (BusinessLogicException $exception) {
            return $this->businessError($exception);
        } catch (\DomainException $exception) {
            return MobileResponse::error($exception->getMessage(), in_array($exception->getCode(), [403, 409, 422], true) ? $exception->getCode() : 422);
        }
    }

    private function businessError(BusinessLogicException $exception): JsonResponse
    {
        $status = $exception->getCode();
        if ($status < 400 || $status >= 500) {
            $status = 400;
        }

        return MobileResponse::error($exception->getMessage(), $status);
    }
}
