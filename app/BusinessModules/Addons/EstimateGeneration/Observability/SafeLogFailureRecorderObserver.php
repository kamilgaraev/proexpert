<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use Illuminate\Support\Facades\Log;

final class SafeLogFailureRecorderObserver implements FailureRecorderObserver
{
    public function recordingFailed(FailureData $failure): void
    {
        Log::error('[EstimateGeneration] failure observability persistence failed', [
            'failure_code' => $failure->code,
            'failure_category' => $failure->category->value,
            'failure_fingerprint' => $failure->fingerprint,
            'organization_id' => $failure->context->organizationId,
            'project_id' => $failure->context->projectId,
            'session_id' => $failure->context->sessionId,
            'document_id' => $failure->context->documentId,
            'page_id' => $failure->context->pageId,
            'unit_id' => $failure->context->unitId,
            'stage' => $failure->context->stage->value,
            'operation' => $failure->context->operation,
            'provider' => $failure->context->provider,
            'model' => $failure->context->model,
            'safe_context' => $failure->safeContext,
        ]);
    }
}
