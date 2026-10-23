<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Exceptions;

use Exception;

final class AssistantProviderFailure extends Exception
{
    public function __construct()
    {
        parent::__construct('assistant_provider_failed');
    }
}
