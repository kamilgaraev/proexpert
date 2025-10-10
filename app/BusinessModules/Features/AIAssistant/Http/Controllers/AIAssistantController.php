<?php

namespace App\BusinessModules\Features\AIAssistant\Http\Controllers;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\ConversationManager;
use App\BusinessModules\Features\AIAssistant\Services\UsageTracker;
use App\BusinessModules\Features\AIAssistant\Models\Conversation;
use App\BusinessModules\Features\AIAssistant\Http\Resources\ConversationResource;
use App\BusinessModules\Features\AIAssistant\Http\Resources\MessageResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AIAssistantController extends Controller
{
    protected AIAssistantService $aiAssistant;
    protected ConversationManager $conversationManager;
    protected UsageTracker $usageTracker;

    public function __construct(
        AIAssistantService $aiAssistant,
        ConversationManager $conversationManager,
        UsageTracker $usageTracker
    ) {
        $this->aiAssistant = $aiAssistant;
        $this->conversationManager = $conversationManager;
        $this->usageTracker = $usageTracker;
    }

    public function chat(Request $request): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:4000',
            'conversation_id' => 'nullable|integer|exists:ai_conversations,id',
        ]);

        $user = $request->user();
        $organizationId = $user->current_organization_id;

        if (!$this->usageTracker->canMakeRequest($organizationId)) {
            return response()->json([
                'success' => false,
                'error' => 'AI_LIMIT_EXCEEDED',
                'message' => 'Исчерпан месячный лимит запросов к AI-ассистенту',
                'usage' => $this->usageTracker->getUsageStats($organizationId),
            ], 429);
        }

        try {
            $result = $this->aiAssistant->ask(
                $request->message,
                $organizationId,
                $user,
                $request->conversation_id
            );

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'AI_REQUEST_FAILED',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function conversations(Request $request): JsonResponse
    {
        $user = $request->user();

        $conversations = $this->conversationManager->getConversationsByUser($user, 20);

        return response()->json([
            'success' => true,
            'data' => ConversationResource::collection($conversations),
        ]);
    }

    public function conversation(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();

        if ($conversation->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $messages = $this->conversationManager->getHistory($conversation, 50);

        return response()->json([
            'success' => true,
            'data' => [
                'conversation' => new ConversationResource($conversation),
                'messages' => MessageResource::collection($messages),
            ],
        ]);
    }

    public function deleteConversation(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();

        if ($conversation->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $conversation->delete();

        return response()->json([
            'success' => true,
            'message' => 'Conversation deleted',
        ]);
    }

    public function usage(Request $request): JsonResponse
    {
        $user = $request->user();
        
        if (!$user || !isset($user->current_organization_id)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }
        
        $organizationId = $user->current_organization_id;

        $stats = $this->usageTracker->getUsageStats($organizationId);

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}

