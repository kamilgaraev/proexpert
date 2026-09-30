<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Exceptions;

use RuntimeException;

final class AssistantBudgetExceeded extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(trans_message('ai_assistant.approved_budget_exceeded'));
    }
}
