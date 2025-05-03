<?php

namespace App\Exceptions;

use Exception;

class BusinessLogicException extends Exception
{
    // Можно добавить кастомную логику или свойства при необходимости
    // Например, можно передавать дополнительные данные в конструктор

    /**
     * Report the exception.
     *
     * @return bool|null
     */
    public function report()
    {
        // Верните false, чтобы предотвратить логирование по умолчанию Laravel,
        // если вы хотите обрабатывать логирование самостоятельно.
        // return false;
    }

    /**
     * Render the exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function render($request)
    {
        // Можно добавить кастомную логику для рендеринга ответа,
        // если стандартной обработки в Handler.php недостаточно.
        // Например, вернуть JSON-ответ:
        /*
        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => $this->getMessage() ?: 'Business logic error occurred.'
            ], $this->getCode() ?: 400);
        }
        */
        
        // По умолчанию используем стандартный рендеринг
        return false; // Передаем обработку дальше
    }
} 