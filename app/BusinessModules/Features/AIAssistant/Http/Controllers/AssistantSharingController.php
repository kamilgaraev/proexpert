<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Controllers;

use App\BusinessModules\Features\AIAssistant\Http\Requests\UpdateConversationParticipantsRequest;
use App\BusinessModules\Features\AIAssistant\Services\ConversationManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AssistantSharingController extends AbstractAssistantApiController
{
    public function __construct(private readonly ConversationManager $conversations) {}

    public function index(Request $request, int $conversation): JsonResponse
    {
        $item = $this->conversations->findAccessibleConversation($conversation, $this->actor($request), $this->organizationId($request));

        return $item ? $this->success($request, $this->conversations->getParticipants($item, $this->actor($request))->map(fn ($participant) => ['user_id' => $participant->user_id, 'name' => $participant->user?->name, 'role' => $participant->role])) : $this->error($request, 404);
    }

    public function update(UpdateConversationParticipantsRequest $request, int $conversation): JsonResponse
    {
        $item = $this->conversations->findAccessibleConversation($conversation, $this->actor($request), $this->organizationId($request));
        if (! $item) {
            return $this->error($request, 404);
        } try {
            $participants = $this->conversations->updateParticipants($item, $this->actor($request), $this->organizationId($request), $request->validated('participants'));

            return $this->success($request, $participants->map(fn ($participant) => ['user_id' => $participant->user_id, 'name' => $participant->user?->name, 'role' => $participant->role]));
        } catch (RuntimeException) {
            return $this->error($request, 403);
        }
    }
}
