<?php

namespace App\Http\Responses\Api\V1;

use App\Http\Responses\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

class SuccessResponse extends ApiResponse
{
    public function __construct(
        array|null $data = null,
        string $message = 'Operation completed successfully',
        int $statusCode = Response::HTTP_OK,
        array $headers = []
    )
    {
        parent::__construct($data, $statusCode, $message, $headers);
    }
} 