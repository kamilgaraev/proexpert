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
        if ($started['request']->status === 'running' && $started['request']->stage === 'queued'
            && $started['request']->started_at === null && $started['request']->cancel_requested_at === null) {
            $dispatchStartedAt = hrtime(true);
            try {
                ExecuteAssistantChatJob::dispatch((int) $started['request']->id)->afterCommit();
            } catch (Throwable $exception) {
                AssistantRequestLifecycle::recordSubmitPhaseDuration(
                    (string) $started['request']->request_id,
                    'queue_dispatch_call',
                    AssistantRequestLifecycle::elapsedMilliseconds($dispatchStartedAt),
                );
                throw $exception;
            }
            AssistantRequestLifecycle::recordSubmitPhaseDuration(
                (string) $started['request']->request_id,
                'queue_dispatch_call',
                AssistantRequestLifecycle::elapsedMilliseconds($dispatchStartedAt),
            );
        }
        return $started;
    }
}
