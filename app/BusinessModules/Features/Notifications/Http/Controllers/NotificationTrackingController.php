<?php

namespace App\BusinessModules\Features\Notifications\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\Notifications\Services\AnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class NotificationTrackingController extends Controller
{
    protected AnalyticsService $analyticsService;

    public function __construct(AnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
    }

    public function trackOpen(Request $request, string $trackingId): Response
    {
        $this->analyticsService->trackOpen($trackingId);

        $pixel = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

        return response($pixel)
            ->header('Content-Type', 'image/gif')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    public function trackClick(Request $request, string $trackingId): \Illuminate\Http\RedirectResponse
    {
        $url = $request->query('url');

        if (!$request->hasValidSignature() || !is_string($url) || !filter_var($url, FILTER_VALIDATE_URL)
            || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            abort(403);
        }

        if (!$this->analyticsService->trackClick($trackingId, $url)) {
            abort(404);
        }

        return redirect()->away($url);
    }
}

