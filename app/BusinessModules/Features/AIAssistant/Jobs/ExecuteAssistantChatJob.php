<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Jobs;

use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestLifecycle;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ExecuteAssistantChatJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 420;

    public function __construct(public readonly int $assistantRequestId)
    {
        $this->onConnection('redis_ai_rag');
        $this->onQueue('ai-chat');
    }

    public function handle(AssistantRequestLifecycle $requests, AIAssistantService $assistant, AssistantDataAccessPolicy $policy): void
    {
        $request = $requests->claimQueued($this->assistantRequestId);
        if ($request === null) {
            return;
        }
        $surface = KnowledgeSurface::tryFrom((string) $request->surface);
        if ($surface === null || $surface === KnowledgeSurface::SUPERADMIN) {
            $requests->fail($request, 'request_invalid');
            return;
        }
        $policy->setTrustedSurface($surface);
        try {
            $actor = User::query()->findOrFail($request->user_id);
            $assistant->executeStartedRequest($request, $actor);
        } catch (Throwable $exception) {
            $requests->fail($request, 'request_failed');
            Log::error('ai.assistant.queued_request_failed', ['request_id' => $request->request_id, 'exception_class' => $exception::class]);
            throw $exception;
        } finally {
            $policy->setTrustedSurface(null);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $request = AssistantRequest::query()->find($this->assistantRequestId);
        if ($request !== null) {
            app(AssistantRequestLifecycle::class)->fail($request, 'request_failed');
        }
    }
}
