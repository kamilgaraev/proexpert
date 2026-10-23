<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Exceptions;

use RuntimeException;

final class AssistantProviderFailure extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('assistant_provider_failed');
    }
}
