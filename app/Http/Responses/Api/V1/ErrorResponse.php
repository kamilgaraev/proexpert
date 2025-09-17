<?php

namespace App\Http\Responses\Api\V1;

use App\Http\Responses\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

class ErrorResponse extends ApiResponse
{
    public function __construct(
        string $message,
        int $statusCode = Response::HTTP_BAD_REQUEST,
        array $data = [],
        array $headers = []
    )
    {
        parent::__construct(
            $data ?: null, // 1. data (может содержать детали ошибки)
            $statusCode,   // 2. statusCode
            $message,      // 3. message
            $headers       // 4. headers
        );
    }
} 