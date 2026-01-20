<?php

namespace App\Exceptions\AI;

/**
 * Ошибка парсинга ответа от AI
 */
class AIParsingException extends AIServiceException
{
    protected int $httpStatusCode = 500;
    
    public function __construct(
        string $message = 'Не удалось обработать ответ от AI сервиса.',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous, 500);
    }
}
