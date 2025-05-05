<?php

namespace App\Http\Responses\Api\V1;

use App\Http\Responses\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

class ErrorResponse extends ApiResponse
{
    public function __construct(
        string $message,
        int $statusCode = Response::HTTP_BAD_REQUEST,
        array $headers = []
    )
    {
        parent::__construct(
            null,          // 1. data (null для ошибки)
            $statusCode,   // 2. statusCode
            $message,      // 3. message
            $headers       // 4. headers
        );
    }
} 