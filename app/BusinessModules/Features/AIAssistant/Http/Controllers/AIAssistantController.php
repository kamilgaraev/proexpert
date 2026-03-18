<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Controllers;

use App\BusinessModules\Features\AIAssistant\Http\Resources\ConversationResource;
use App\BusinessModules\Features\AIAssistant\Http\Resources\MessageResource;
use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\ConversationManager;
use App\BusinessModules\Features\AIAssistant\Services\UsageTracker;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use App\Http\Responses\LandingResponse;
use App\Http\Responses\MobileResponse;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AIAssistantController extends Controller
{
    public function __construct(
        private readonly AIAssistantService $aiAssistant,
        private readonly ConversationManager $conversationManager,
        private readonly UsageTracker $usageTracker,
        private readonly AIPermissionChecker $permissionChecker
    ) {
    }

    public function chat(Request $request): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:4000',
            'conversation_id' => 'nullable|integer|exists:ai_conversations,id',
        ]);

        $user = $request->user();
        if (!$user instanceof User || !$user->current_organization_id) {
            return $this->errorResponse($request, $this->assistantMessage('ai_assistant.unauthorized', 'Пользователь не авторизован.'), 401);
        }

        $organizationId = (int) $user->current_organization_id;

        try {
            if (!$this->usageTracker->canMakeRequest($organizationId)) {
                return $this->errorResponse(
                    $request,
                    $this->assistantMessage('ai_assistant.limit_exceeded', 'Исчерпан месячный лимит запросов к AI-ассистенту.'),
                    429,
                    ['usage' => $this->usageTracker->getUsageStats($organizationId)]
                );
            }

            $conversationId = $request->integer('conversation_id') ?: null;
            if ($conversationId !== null && !$this->findConversationForRequest($request, $conversationId, $user, $organizationId)) {
                return $this->errorResponse($request, $this->assistantMessage('ai_assistant.conversation_not_found', 'Диалог не найден или недоступен.'), 403);
            }

            $result = $this->aiAssistant->ask(
                $request->string('message')->toString(),
                $organizationId,
                $user,
                $conversationId
            );

            return $this->successResponse($request, $result);
        } catch (AuthorizationException $exception) {
            Log::warning('AI assistant access denied', [
                'user_id' => $user->id,
                'organization_id' => $organizationId,
                'message' => $exception->getMessage(),
            ]);

            return $this->errorResponse($request, $exception->getMessage(), 403);
        } catch (RuntimeException $exception) {
            Log::warning('AI assistant request rejected', [
                'user_id' => $user->id,
                'organization_id' => $organizationId,
                'message' => $exception->getMessage(),
            ]);

            return $this->errorResponse($request, $exception->getMessage(), 422);
        } catch (Throwable $exception) {
            Log::error('AI assistant request failed', [
                'user_id' => $user->id,
                'organization_id' => $organizationId,
                'message' => $exception->getMessage(),
            ]);

            return $this->errorResponse($request, $this->assistantMessage('ai_assistant.request_failed', 'Не удалось выполнить запрос к AI-ассистенту.'), 500);
        }
    }

    public function conversations(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User || !$user->current_organization_id) {
            return $this->errorResponse($request, $this->assistantMessage('ai_assistant.unauthorized', 'Пользователь не авторизован.'), 401);
        }

        try {
            $organizationId = (int) $user->current_organization_id;
            $conversations = $this->isAdminRequest($request)
                && $this->permissionChecker->canManageOrganizationConversations($user, $organizationId)
                ? $this->conversationManager->getConversationsByOrganization($organizationId, 20)
                : $this->conversationManager->getConversationsByUserInOrganization($user, $organizationId, 20);

            return $this->successResponse($request, ConversationResource::collection($conversations));
        } catch (Throwable $exception) {
            Log::error('Failed to load AI assistant conversations', [
                'user_id' => $user->id,
                'organization_id' => $user->current_organization_id,
                'message' => $exception->getMessage(),
            ]);

            return $this->errorResponse($request, $this->assistantMessage('ai_assistant.load_conversations_failed', 'Не удалось загрузить список диалогов.'), 500);
        }
    }

    public function conversation(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User || !$user->current_organization_id) {
            return $this->errorResponse($request, $this->assistantMessage('ai_assistant.unauthorized', 'Пользователь не авторизован.'), 401);
        }

        try {
            $this->authorizeConversation($conversation, $user);
            $messages = $this->conversationManager->getHistory($conversation, 50);

            return $this->successResponse($request, [
                'conversation' => new ConversationResource($conversation),
                'messages' => MessageResource::collection($messages),
            ]);
        } catch (AuthorizationException $exception) {
            return $this->errorResponse($request, $exception->getMessage(), 403);
        } catch (Throwable $exception) {
            Log::error('Failed to load AI assistant conversation', [
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'organization_id' => $user->current_organization_id,
                'message' => $exception->getMessage(),
            ]);

            return $this->errorResponse($request, $this->assistantMessage('ai_assistant.load_conversation_failed', 'Не удалось загрузить диалог.'), 500);
        }
    }

    public function deleteConversation(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User || !$user->current_organization_id) {
            return $this->errorResponse($request, $this->assistantMessage('ai_assistant.unauthorized', 'Пользователь не авторизован.'), 401);
        }

        try {
            $this->authorizeConversation($conversation, $user);
            $conversation->delete();

            return $this->successResponse($request, null, $this->assistantMessage('ai_assistant.conversation_deleted', 'Диалог удален.'));
        } catch (AuthorizationException $exception) {
            return $this->errorResponse($request, $exception->getMessage(), 403);
        } catch (Throwable $exception) {
            Log::error('Failed to delete AI assistant conversation', [
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'organization_id' => $user->current_organization_id,
                'message' => $exception->getMessage(),
            ]);

            return $this->errorResponse($request, $this->assistantMessage('ai_assistant.delete_conversation_failed', 'Не удалось удалить диалог.'), 500);
        }
    }

    public function usage(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User || !$user->current_organization_id) {
            return $this->errorResponse($request, $this->assistantMessage('ai_assistant.unauthorized', 'Пользователь не авторизован.'), 401);
        }

        try {
            $stats = $this->usageTracker->getUsageStats((int) $user->current_organization_id);

            return $this->successResponse($request, $stats);
        } catch (Throwable $exception) {
            Log::error('Failed to load AI assistant usage', [
                'user_id' => $user->id,
                'organization_id' => $user->current_organization_id,
                'message' => $exception->getMessage(),
            ]);

            return $this->errorResponse($request, $this->assistantMessage('ai_assistant.usage_failed', 'Не удалось получить статистику использования AI-ассистента.'), 500);
        }
    }

    private function authorizeConversation(Conversation $conversation, User $user): void
    {
        $organizationId = (int) $user->current_organization_id;

        if (!$this->permissionChecker->canAccessConversation($user, $conversation, $organizationId)) {
            throw new AuthorizationException($this->assistantMessage('ai_assistant.conversation_not_found', 'Диалог не найден или недоступен.'));
        }
    }

    private function findConversationForRequest(Request $request, int $conversationId, User $user, int $organizationId): ?Conversation
    {
        if ($this->isAdminRequest($request) && $this->permissionChecker->canManageOrganizationConversations($user, $organizationId)) {
            return $this->conversationManager->findOrganizationConversation($conversationId, $organizationId);
        }

        return $this->conversationManager->findUserConversation($conversationId, $user, $organizationId);
    }

    private function assistantMessage(string $key, string $fallback, array $replace = []): string
    {
        $translated = trans_message($key, $replace, 'ru');

        if (!is_string($translated)) {
            return $fallback;
        }

        $translated = trim($translated);

        if ($translated === '' || $translated === $key) {
            return $fallback;
        }

        return $translated;
    }

    private function successResponse(Request $request, mixed $data = null, ?string $message = null, int $code = 200): JsonResponse
    {
        if ($this->isAdminRequest($request)) {
            return AdminResponse::success($data, $message, $code);
        }

        if ($this->isMobileRequest($request)) {
            return MobileResponse::success($data, $message, $code);
        }

        return LandingResponse::success($data, $message, $code);
    }

    private function errorResponse(Request $request, string $message, int $code = 400, mixed $errors = null): JsonResponse
    {
        if ($this->isAdminRequest($request)) {
            return AdminResponse::error($message, $code, $errors);
        }

        if ($this->isMobileRequest($request)) {
            return MobileResponse::error($message, $code, $errors);
        }

        return LandingResponse::error($message, $code, $errors);
    }

    private function isAdminRequest(Request $request): bool
    {
        return $request->is('api/v1/admin/*');
    }

    private function isMobileRequest(Request $request): bool
    {
        return $request->is('api/v1/mobile/*');
    }
}
