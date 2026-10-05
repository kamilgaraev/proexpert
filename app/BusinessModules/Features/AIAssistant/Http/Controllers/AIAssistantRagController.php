<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Controllers;

use App\BusinessModules\Features\AIAssistant\Http\Requests\AssistantRagReindexRequest;
use App\BusinessModules\Features\AIAssistant\Http\Requests\AssistantRagStatusRequest;
use App\BusinessModules\Features\AIAssistant\Http\Resources\RagIndexStatusResource;
use App\BusinessModules\Features\AIAssistant\Services\AssistantIndexStatusService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AIAssistantRagController extends AbstractAssistantApiController
{
    public function __construct(private readonly AssistantIndexStatusService $index) {}

    public function status(AssistantRagStatusRequest $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        try {
            return $this->success($request, new RagIndexStatusResource($this->index->status($organizationId, $this->actor($request), $request->validated('section', 'all'))));
        } catch (AuthorizationException) {
            return $this->error($request, 403);
        } catch (Throwable $exception) {
            Log::error('ai_assistant.rag.status_failed', ['organization_id' => $organizationId, 'user_id' => $this->actor($request)->id, 'exception_class' => $exception::class]);

            return $this->error($request, 500, 'ai_assistant.rag_status_failed');
        }
    }

    public function reindex(AssistantRagReindexRequest $request): JsonResponse
    {
        $organizationId = $this->organizationId($request);

        try {
            $run = $this->index->reindex($organizationId, $this->actor($request), $request->validated());

            return $this->success($request, ['queued' => true, 'run' => RagIndexStatusResource::runPayload($run)]);
        } catch (AuthorizationException) {
            return $this->error($request, 403);
        } catch (Throwable $exception) {
            Log::error('ai_assistant.rag.reindex_failed', ['organization_id' => $organizationId, 'user_id' => $this->actor($request)->id, 'exception_class' => $exception::class]);

            return $this->error($request, 500, 'ai_assistant.rag_reindex_failed');
        }
    }
}
