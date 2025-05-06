<?php

namespace App\Http\Responses\Api\V1;

use Symfony\Component\HttpFoundation\Response;

class NotFoundResponse extends ErrorResponse
{
    public function __construct(
        string $message = 'Resource not found',
        array $headers = []
    )
    {
        parent::__construct($message, Response::HTTP_NOT_FOUND, $headers);
    }
} 