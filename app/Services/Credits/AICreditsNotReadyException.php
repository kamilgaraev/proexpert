<?php

declare(strict_types=1);

namespace App\Services\Credits;

final class AICreditsNotReadyException extends \DomainException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(trans_message('ai_assistant.credits_not_ready'), 0, $previous);
    }
}
