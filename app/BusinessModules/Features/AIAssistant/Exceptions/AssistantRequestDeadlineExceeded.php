<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Exceptions;

use RuntimeException;

final class AssistantRequestDeadlineExceeded extends RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(trans_message('ai_assistant.request_deadline_exceeded'), 0, $previous);
    }
}
