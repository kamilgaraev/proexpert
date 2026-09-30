<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Jobs\ExecuteAssistantChatJob;
use App\Models\Organization;
use App\Models\User;
use InvalidArgumentException;
use Throwable;

final class QueuedAssistantChatService
{
    public function __construct(private readonly AssistantRequestLifecycle $requests) {}

    public function submit(int $organizationId, User $actor, ?int $conversationId, array $payload, string $surface): array
    {
        if (!in_array($surface, ['admin', 'lk', 'mobile'], true)) {
            throw new InvalidArgumentException('Unsupported assistant surface');
        }
        $organization = Organization::query()->findOrFail($organizationId);
        $started = $this->requests->startQueued($organization, $actor, $conversationId, $payload, $surface);
        if ($started['created']) {
            try {
                ExecuteAssistantChatJob::dispatch((int) $started['request']->id)->afterCommit();
            } catch (Throwable $exception) {
                $this->requests->fail($started['request'], 'queue_unavailable');
                throw $exception;
            }
        }
        return $started;
    }
}
