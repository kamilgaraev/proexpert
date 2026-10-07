<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Controllers;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestInProgress;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestCancelled;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantBudgetExceeded;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantResponseIncomplete;
use App\BusinessModules\Features\AIAssistant\Http\Requests\AssistantActionExecuteRequest;
use App\BusinessModules\Features\AIAssistant\Http\Requests\AssistantActionPreviewRequest;
use App\BusinessModules\Features\AIAssistant\Http\Requests\AssistantChatRequest;
use App\BusinessModules\Features\AIAssistant\Http\Requests\AssistantPaginationRequest;
use App\BusinessModules\Features\AIAssistant\Http\Requests\StoreAssistantConversationRequest;
use App\BusinessModules\Features\AIAssistant\Http\Resources\ConversationResource;
use App\BusinessModules\Features\AIAssistant\Http\Resources\MessageResource;
use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Services\AssistantActionProposalService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantActionService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestLifecycle;
use App\BusinessModules\Features\AIAssistant\Services\QueuedAssistantChatService;
use App\BusinessModules\Features\AIAssistant\Services\ConversationManager;
use App\BusinessModules\Features\AIAssistant\Services\UsageTracker;
use App\Http\Responses\AdminResponse;
use App\Http\Responses\LandingResponse;
use App\Http\Responses\MobileResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use DomainException;
use RuntimeException;
use Throwable;
use App\Services\Credits\AICreditsNotReadyException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class AIAssistantController extends AbstractAssistantApiController
{
    public function __construct(
        private readonly AssistantRequestLifecycle $requests,
        private readonly QueuedAssistantChatService $queuedChats,
        private readonly ConversationManager $conversations,
        private readonly UsageTracker $usage,
    ) {}

    public function chat(AssistantChatRequest $request): JsonResponse
    {
        return $this->respond($request, function () use ($request): JsonResponse {
            $payload = $request->validated();
            unset($payload['async']);
            $surface = $this->surface($request);
            $started = $this->queuedChats->submit(
                $this->lifecycleOrganizationId($request),
                $this->actor($request),
                isset($payload['conversation_id']) ? (int) $payload['conversation_id'] : null,
                $payload,
                $surface,
            );
            if (is_array($started['response'])) {
                return $this->success($request, $started['response']);
            }
            $assistantRequest = $started['request'];
            return $this->success($request, [
                'request_id' => $assistantRequest->request_id,
                'conversation_id' => $assistantRequest->conversation_id,
                'status' => 'running',
                'stage' => $assistantRequest->stage,
            ], 202);
        });
    }

    public function conversations(AssistantPaginationRequest $request): JsonResponse
    {
        return $this->respond($request, function () use ($request): JsonResponse {
            $page = $this->conversations->queryVisibleConversations($this->actor($request), $this->lifecycleOrganizationId($request), failWhenDenied: true)
                ->paginate($request->integer('per_page', 30), ['*'], 'page', $request->integer('page', 1));
            return $this->success($request, ConversationResource::collection($page->getCollection()), 200, $this->pagination($page));
        });
    }

    public function createConversation(StoreAssistantConversationRequest $request): JsonResponse
    {
        return $this->respond($request, function () use ($request): JsonResponse {
            $conversation = $this->conversations->createConversation($this->organizationId($request), $this->actor($request), $request->validated('title'));
            $conversation->load('participants');
            return $this->success($request, new ConversationResource($conversation), 201);
        });
    }

    public function conversation(AssistantPaginationRequest $request, int $conversation): JsonResponse
    {
        return $this->respond($request, function () use ($request, $conversation): JsonResponse {
            $result = $this->accessibleConversationPage($request, $conversation);
            $model = $result['conversation'];
            $page = $result['page'];
            return $this->success($request, [
                'conversation' => new ConversationResource($model),
                'messages' => MessageResource::collection($page->getCollection()),
            ], 200, $this->pagination($page));
        });
    }

    public function history(AssistantPaginationRequest $request, int $conversation): JsonResponse
    {
        return $this->respond($request, function () use ($request, $conversation): JsonResponse {
            $page = $this->accessibleConversationPage($request, $conversation)['page'];
            return $this->success($request, MessageResource::collection($page->getCollection()), 200, $this->pagination($page));
        });
    }

    public function deleteConversation(Request $request, int $conversation): JsonResponse
    {
        return $this->respond($request, function () use ($request, $conversation): JsonResponse {
            $this->conversations->deleteConversation($this->accessibleConversation($request, $conversation), $this->actor($request));
            return $this->success($request);
        });
    }

    public function previewAction(AssistantActionPreviewRequest $request, AssistantActionProposalService $proposals, AssistantActionService $actions): JsonResponse
    {
        return $this->respond($request, function () use ($request, $proposals, $actions): JsonResponse {
            $conversation = $this->accessibleConversation($request, $request->integer('conversation_id'), true);
            $proposal = $proposals->resolve($conversation, $this->actor($request), $request->validated('action'));
            return $this->success($request, $actions->preview($proposal, $this->organizationId($request), $this->actor($request), $conversation));
        });
    }

    public function executeAction(AssistantActionExecuteRequest $request, AssistantActionService $actions): JsonResponse
    {
        return $this->respond($request, function () use ($request, $actions): JsonResponse {
            $conversation = $this->accessibleConversation($request, $request->integer('conversation_id'), true);
            $payload = $request->validated('action');
            $payload['confirmed'] = $request->boolean('action.confirmed');
            $result = $actions->execute($payload, $this->organizationId($request), $this->actor($request), $conversation);
            $this->conversations->touchActivity($conversation);
            return $this->success($request, $result);
        });
    }

    public function requestStatus(Request $request, string $requestId): JsonResponse
    {
        return $this->respond($request, fn (): JsonResponse => $this->success($request, $this->requests->status($requestId, $this->actor($request), $this->lifecycleOrganizationId($request), $this->surface($request))));
    }

    public function cancelRequest(Request $request, string $requestId): JsonResponse
    {
        return $this->respond($request, fn (): JsonResponse => $this->success($request, $this->requests->cancelOwned($requestId, $this->actor($request), $this->surface($request))));
    }

    public function usage(Request $request): JsonResponse
    {
        return $this->respond($request, function () use ($request): JsonResponse {
            return $this->success($request, $this->usage->getUsageStats($this->organizationId($request)));
        });
    }

    private function accessibleConversationPage(Request $request, int $id): array
    {
        $result = $this->conversations->findAccessibleConversationPage($id, $this->actor($request), $this->organizationId($request), $request->integer('per_page', 30), $request->integer('page', 1));
        if ($result === null) {
            throw new AuthorizationException(trans_message('ai_assistant.conversation_not_found'));
        }

        return $result;
    }

    private function accessibleConversation(Request $request, int $id, bool $write = false): Conversation
    {
        $conversation = $this->conversations->findAccessibleConversation($id, $this->actor($request), $this->organizationId($request), $write);
        if ($conversation === null) {
            throw new AuthorizationException(trans_message('ai_assistant.conversation_not_found'));
        }
        $conversation->load('participants');
        return $conversation;
    }

    private function surface(Request $request): string
    {
        return $request->is('api/v1/admin/*') ? 'admin' : ($request->is('api/v1/mobile/*') ? 'mobile' : 'lk');
    }

    private function pagination(LengthAwarePaginator $page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()];
    }

    private function respond(Request $request, callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (AssistantRequestInProgress) {
            return $this->reject($request, trans_message('ai_assistant.request_in_progress'), 409);
        } catch (AssistantRequestCancelled) {
            return $this->reject($request, trans_message('ai_assistant.request_cancelled'), 409);
        } catch (AssistantBudgetExceeded) {
            return $this->reject($request, trans_message('ai_assistant.approved_budget_exceeded'), 409);
        } catch (AssistantResponseIncomplete $exception) {
            return $this->reject($request, trans_message($exception->messageKey()), 409);
        } catch (AICreditsNotReadyException) {
            return $this->reject($request, trans_message('ai_assistant.credits_not_ready'), 503);
        } catch (AuthenticationException) {
            return $this->reject($request, trans_message('errors.unauthorized'), 401);
        } catch (AuthorizationException|AccessDeniedHttpException) {
            return $this->reject($request, trans_message('ai_assistant.access_denied'), 403);
        } catch (ModelNotFoundException) {
            return $this->reject($request, trans_message('ai_assistant.conversation_not_found'), 404);
        } catch (RuntimeException|DomainException) {
            return $this->reject($request, trans_message('ai_assistant.request_invalid'), 422);
        } catch (Throwable $exception) {
            Log::error('ai.assistant.request_failed', ['exception_class' => $exception::class, 'user_id' => $request->user()?->id]);
            return $this->reject($request, trans_message('ai_assistant.request_failed'), 500);
        }
    }

    private function reject(Request $request, string $message, int $status): JsonResponse
    {
        $response = $request->is('api/v1/admin/*') ? AdminResponse::class : ($request->is('api/v1/mobile/*') ? MobileResponse::class : LandingResponse::class);
        return $response::error($message, $status);
    }
}
