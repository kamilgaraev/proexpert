<?php

namespace App\Http\Responses\Api\V1;

use App\Http\Responses\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

class ErrorResponse extends ApiResponse
{
    public function __construct(
        string $message,
        int $statusCode = Response::HTTP_BAD_REQUEST,
        array | null $errors = null,
        array $headers = []
    )
    {
        parent::__construct($errors, $statusCode, $message, $headers);
    }
} 