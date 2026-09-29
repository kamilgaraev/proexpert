<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Exceptions;

use RuntimeException;

final class AssistantRequestCancelled extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(trans_message('ai_assistant.request_cancelled'));
    }
}
