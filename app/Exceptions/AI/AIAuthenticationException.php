<?php

namespace App\Exceptions\AI;

/**
 * Ошибка аутентификации с AI сервисом (401, 403)
 */
class AIAuthenticationException extends AIServiceException
{
    protected int $httpStatusCode = 500; // Для пользователя это серверная ошибка
    
    public function __construct(
        string $message = 'Ошибка конфигурации AI сервиса.',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous, 500);
    }
}
