<?php

namespace App\Exceptions\AI;

/**
 * Превышена квота / rate limit AI сервиса (429)
 */
class AIQuotaExceededException extends AIServiceException
{
    protected int $httpStatusCode = 429;
    
    public function __construct(
        string $message = 'Превышен лимит запросов к AI сервису. Попробуйте позже.',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous, 429);
    }
}
