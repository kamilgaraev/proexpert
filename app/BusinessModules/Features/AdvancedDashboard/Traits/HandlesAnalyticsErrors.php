<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

trait HandlesAnalyticsErrors
{
    protected function handleAnalyticsError(\Exception $e, string $context): JsonResponse
    {
        $errorDetails = [
            'context' => $context,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ];
        
        Log::error("Advanced Dashboard Analytics Error: {$context}", $errorDetails);

        return response()->json([
            'success' => false,
            'message' => "Error retrieving {$context} data",
            'error' => config('app.debug') ? $e->getMessage() : null,
            'debug_info' => config('app.debug') ? [
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
            ] : null,
        ], 500);
    }
}

