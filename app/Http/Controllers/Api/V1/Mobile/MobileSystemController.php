<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\BusinessModules\Core\AccessRecertification\Models\AccessRecertificationItem;
use App\BusinessModules\Core\ImmutableAudit\DTO\ImmutableAuditEventFilters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Mobile\System\MobileAccessRecertificationDecisionRequest;
use App\Http\Requests\Api\V1\Mobile\System\MobileAccessRecertificationIndexRequest;
use App\Http\Requests\Api\V1\Mobile\System\MobileSystemEventIndexRequest;
use App\Http\Requests\Api\V1\Mobile\System\MobileSystemRateCoefficientIndexRequest;
use App\Http\Requests\Api\V1\Mobile\System\MobileSystemRunIndexRequest;
use App\Http\Resources\Api\V1\Admin\AccessRecertification\AccessRecertificationCampaignResource;
use App\Http\Resources\Api\V1\Admin\AccessRecertification\AccessRecertificationDecisionResource;
use App\Http\Resources\Api\V1\Admin\AccessRecertification\AccessRecertificationItemResource;
use App\Http\Resources\Api\V1\Admin\ImmutableAudit\ImmutableAuditEventResource;
use App\Http\Resources\Api\V1\Admin\RateCoefficient\RateCoefficientResource;
use App\Http\Resources\Api\V1\Mobile\MobileOneCExchangeRunResource;
use App\Http\Responses\MobileResponse;
use App\Services\Mobile\MobileSystemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class MobileSystemController extends Controller
{
    public function __construct(private readonly MobileSystemService $service) {}

    public function oneCStatus(Request $request): JsonResponse
    {
        $status = $this->service->oneCStatus($request->user(), $this->organizationId($request));

        if ($status['last_run'] !== null) {
            $status['last_run'] = (new MobileOneCExchangeRunResource($status['last_run']))->resolve($request);
        }

        return MobileResponse::success(
            $status,
            trans_message('one_c_exchange.status_loaded'),
        );
    }

    public function oneCHistory(MobileSystemRunIndexRequest $request): JsonResponse
    {
        $runs = $this->service->oneCHistory(
            $request->user(),
            $this->organizationId($request),
            (int) $request->validated('per_page', 20),
        );

        return MobileResponse::paginated(
            MobileOneCExchangeRunResource::collection(collect($runs->items()))->resolve($request),
            $this->meta($runs),
            trans_message('one_c_exchange.history_loaded'),
        );
    }

    public function retryOneCOperation(Request $request, int $operationId): JsonResponse
    {
        $result = $this->service->retryOneCOperation(
            $request->user(),
            $this->organizationId($request),
            $operationId,
        );

        if ($result['operation'] === null) {
            return MobileResponse::error((string) $result['message'], 404);
        }

        if (! $result['allowed']) {
            return MobileResponse::error((string) $result['message'], 409);
        }

        return MobileResponse::success($result['operation'], (string) $result['message']);
    }

    public function campaigns(MobileAccessRecertificationIndexRequest $request): JsonResponse
    {
        $campaigns = $this->service->campaigns(
            $request->user(),
            $this->organizationId($request),
            $request->safe()->only(['status', 'type', 'search']),
            (int) $request->validated('per_page', 20),
        );

        return MobileResponse::paginated(
            AccessRecertificationCampaignResource::collection($campaigns->getCollection())->resolve($request),
            $this->meta($campaigns),
            trans_message('access_recertification.campaigns_loaded'),
        );
    }

    public function reviewQueue(MobileAccessRecertificationIndexRequest $request): JsonResponse
    {
        $items = $this->service->reviewQueue(
            $request->user(),
            $this->organizationId($request),
            $request->safe()->only(['status', 'risk_level']),
            (int) $request->validated('per_page', 20),
        );

        return MobileResponse::paginated(
            AccessRecertificationItemResource::collection($items->getCollection())->resolve($request),
            $this->meta($items),
            trans_message('access_recertification.items_loaded'),
        );
    }

    public function decide(
        MobileAccessRecertificationDecisionRequest $request,
        AccessRecertificationItem $item,
    ): JsonResponse {
        try {
            $decision = $this->service->decide(
                $request->user(),
                $this->organizationId($request),
                $item,
                $request->validated(),
            );
        } catch (InvalidArgumentException $exception) {
            return MobileResponse::error(
                trans_message('access_recertification.errors.'.$exception->getMessage()),
                422,
            );
        }

        return MobileResponse::success(
            new AccessRecertificationDecisionResource($decision),
            trans_message('access_recertification.decision_saved'),
        );
    }

    public function currentRateCoefficients(MobileSystemRateCoefficientIndexRequest $request): JsonResponse
    {
        $coefficients = $this->service->currentRateCoefficients(
            $request->user(),
            $this->organizationId($request),
            (string) $request->validated('applies_to'),
            $request->validated('scope'),
            (int) $request->validated('per_page', 20),
        );

        return MobileResponse::paginated(
            RateCoefficientResource::collection($coefficients->getCollection())->resolve($request),
            $this->meta($coefficients),
            null,
        );
    }

    public function systemEvents(MobileSystemEventIndexRequest $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);
        $filters = ImmutableAuditEventFilters::fromRequest($request, $organizationId);
        $events = $this->service->systemEvents($request->user(), $organizationId, $filters);

        return MobileResponse::paginated(
            ImmutableAuditEventResource::collection($events->getCollection())->resolve($request),
            $this->meta($events),
            trans_message('immutable_audit.events_loaded'),
        );
    }

    private function organizationId(Request $request): int
    {
        $organizationId = $request->attributes->get('current_organization_id')
            ?? $request->user()?->current_organization_id;

        return (int) $organizationId;
    }

    private function meta(mixed $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }
}
