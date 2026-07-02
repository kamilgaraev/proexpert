<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Controllers;

use App\BusinessModules\Features\AIAssistant\Services\AIUsageReportService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class AIUsageReportController extends Controller
{
    public function __construct(
        private readonly AIUsageReportService $usageReportService
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'organization_id' => ['nullable', 'integer', 'min:1'],
            'provider' => ['nullable', 'string', 'max:40'],
            'model' => ['nullable', 'string', 'max:160'],
            'operation' => ['nullable', 'string', 'max:80'],
        ]);

        try {
            return AdminResponse::success($this->usageReportService->summary($validated));
        } catch (Throwable $throwable) {
            Log::error('ai_assistant.usage_report.failed', [
                'user_id' => $request->user()?->id,
                'filters' => array_intersect_key($validated, array_flip([
                    'date_from',
                    'date_to',
                    'organization_id',
                    'provider',
                    'model',
                    'operation',
                ])),
                'exception_class' => $throwable::class,
            ]);

            return AdminResponse::error(trans_message('ai_assistant.usage_failed'), 500);
        }
    }
}
