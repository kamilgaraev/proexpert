<?php

namespace App\Exceptions\AI;

/**
 * AI сервис недоступен (таймаут, сетевые ошибки, 503)
 */
class AIServiceUnavailableException extends AIServiceException
{
    protected int $httpStatusCode = 503;
    
    public function __construct(
        string $message = 'AI сервис временно недоступен. Попробуйте позже.',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous, 503);
    }
}
