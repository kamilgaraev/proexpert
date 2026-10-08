<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Public\AnalyticsConsentStatusRequest;
use App\Http\Responses\LandingResponse;
use App\Services\Legal\AnalyticsConsentService;
use Illuminate\Http\JsonResponse;

final class AnalyticsConsentStatusController extends Controller
{
    public function __invoke(AnalyticsConsentStatusRequest $request, AnalyticsConsentService $consents): JsonResponse
    {
        return LandingResponse::success(['active' => $consents->active($request->validated())])->header('Cache-Control', 'no-store');
    }
}
