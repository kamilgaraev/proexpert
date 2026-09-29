<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use Illuminate\Support\Facades\Queue;
use Throwable;

final class RagQueueBacklog
{
    public static function isEmpty(): bool
    {
        try {
            $connection = (string) config('ai-assistant.rag.queue_connection', 'redis_ai_rag');
            $queue = Queue::connection($connection);

            return $queue->size((string) config('ai-assistant.rag.queue', 'ai-rag')) === 0
                && $queue->size((string) config('ai-assistant.rag.live_queue', 'ai-rag-live')) === 0;
        } catch (Throwable) {
            return false;
        }
    }
}
