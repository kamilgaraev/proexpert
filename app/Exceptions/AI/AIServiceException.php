<?php

namespace App\Exceptions\AI;

use Exception;

/**
 * Базовое исключение для всех AI сервисов
 */
class AIServiceException extends Exception
{
    protected int $httpStatusCode = 500;
    
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        int $httpStatusCode = 500
    ) {
        parent::__construct($message, $code, $previous);
        $this->httpStatusCode = $httpStatusCode;
    }
    
    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }
}
