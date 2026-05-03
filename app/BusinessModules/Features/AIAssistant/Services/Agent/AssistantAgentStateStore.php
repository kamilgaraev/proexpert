<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Agent;

use App\BusinessModules\Features\AIAssistant\DTOs\Agent\AssistantTaskState;
use App\BusinessModules\Features\AIAssistant\Models\Conversation;

final class AssistantAgentStateStore
{
    public function load(Conversation $conversation): ?AssistantTaskState
    {
        $context = $this->context($conversation);
        $state = $context['agent_state'] ?? null;

        if (! is_array($state)) {
            return null;
        }

        return AssistantTaskState::fromArray($state);
    }

    public function save(Conversation $conversation, AssistantTaskState $state): void
    {
        $context = $this->context($conversation);
        $context['agent_state'] = $state->toArray();

        $conversation->context = $context;
        $this->persist($conversation);
    }

    public function clear(Conversation $conversation): void
    {
        $context = $this->context($conversation);
        unset($context['agent_state']);

        $conversation->context = $context;
        $this->persist($conversation);
    }

    private function context(Conversation $conversation): array
    {
        return is_array($conversation->context ?? null) ? $conversation->context : [];
    }

    private function persist(Conversation $conversation): void
    {
        if ($conversation->exists) {
            $conversation->save();
        }
    }
}
