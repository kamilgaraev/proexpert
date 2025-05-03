<?php

namespace App\Http\Responses\Api\V1;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Symfony\Component\HttpFoundation\Response;

class SuccessCreationResponse extends ApiResponse
{
    public function __construct(
        JsonResource | array | null $data = null,
        string $message = 'Resource created successfully',
        array $headers = []
    )
    {
        parent::__construct($data, Response::HTTP_CREATED, $message, $headers);
    }
} 