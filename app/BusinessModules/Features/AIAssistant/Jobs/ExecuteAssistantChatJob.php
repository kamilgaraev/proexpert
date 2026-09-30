<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Jobs;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestCancelled;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestDeadlineExceeded;
use App\BusinessModules\Features\AIAssistant\Models\AssistantRequest;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext;
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

    public function handle(AssistantRequestLifecycle $requests, AssistantDataAccessPolicy $policy): void
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
        $context = null;
        try {
            $actor = User::query()->findOrFail($request->user_id);
            $context = $requests->createExecutionContext($request, $actor);
            app()->instance(AssistantRequestExecutionContext::class, $context);
            $context->activate();
            $policy->setTrustedSurface($surface);
            $context->assertCanContinue();
            app(AIAssistantService::class)->executeStartedRequest($request, $actor);
        } catch (AssistantRequestCancelled) {
            $this->failAndReleaseExecutionContext($requests, $request, 'request_cancelled', $context);
        } catch (AssistantRequestDeadlineExceeded) {
            $this->failAndReleaseExecutionContext($requests, $request, 'request_deadline_exceeded', $context);
        } catch (Throwable $exception) {
            try {
                $context?->assertCanContinue();
            } catch (AssistantRequestCancelled) {
                $this->failAndReleaseExecutionContext($requests, $request, 'request_cancelled', $context);

                return;
            } catch (AssistantRequestDeadlineExceeded) {
                $this->failAndReleaseExecutionContext($requests, $request, 'request_deadline_exceeded', $context);

                return;
            } catch (Throwable) {
            }
            $this->failAndReleaseExecutionContext($requests, $request, 'request_failed', $context);
            Log::error('ai.assistant.queued_request_failed', ['request_id' => $request->request_id, 'exception_class' => $exception::class]);
            throw $exception;
        } finally {
            $policy->setTrustedSurface(null);
            $this->releaseExecutionContext($context);
        }
    }

    private function failAndReleaseExecutionContext(
        AssistantRequestLifecycle $requests,
        AssistantRequest $request,
        string $errorCode,
        ?AssistantRequestExecutionContext $context,
    ): void {
        try {
            if ($context === null) {
                $requests->fail($request, $errorCode);

                return;
            }

            $context->withCleanupBudget(fn () => $requests->fail($request, $errorCode));
        } finally {
            $this->releaseExecutionContext($context);
        }
    }

    private function releaseExecutionContext(?AssistantRequestExecutionContext $context): void
    {
        $context?->restoreDatabaseStatementTimeouts();
        app()->forgetInstance(AssistantRequestExecutionContext::class);
    }

    public function failed(?Throwable $exception): void
    {
        $request = AssistantRequest::query()->find($this->assistantRequestId);
        if ($request !== null) {
            app(AssistantRequestLifecycle::class)->fail($request, 'request_failed');
        }
    }
}
