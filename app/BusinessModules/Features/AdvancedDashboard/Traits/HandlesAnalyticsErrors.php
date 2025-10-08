<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

trait HandlesAnalyticsErrors
{
    protected function handleAnalyticsError(\Exception $e, string $context): JsonResponse
    {
        Log::error("Advanced Dashboard Analytics Error: {$context}", [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        return response()->json([
            'success' => false,
            'message' => "Error retrieving {$context} data",
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], 500);
    }
}

