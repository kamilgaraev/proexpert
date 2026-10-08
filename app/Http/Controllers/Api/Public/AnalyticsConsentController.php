<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Public\StoreAnalyticsConsentRequest;
use App\Http\Responses\LandingResponse;
use App\Services\Legal\AnalyticsConsentService;
use Illuminate\Http\JsonResponse;

final class AnalyticsConsentController extends Controller
{
    public function __invoke(StoreAnalyticsConsentRequest $request, AnalyticsConsentService $consents): JsonResponse
    {
        $event = $consents->record($request->validated(), $request);

        return LandingResponse::success(['receipt_id' => $event->id], code: 201);
    }
}
