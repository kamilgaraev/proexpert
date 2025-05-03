<?php

namespace App\Http\Responses\Api\V1;

use App\Http\Responses\ApiResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class SuccessResourceResponse extends ApiResponse
{
    public function __construct(
        JsonResource | ResourceCollection | array | null $data = null,
        ?string $message = null,
        int $statusCode = Response::HTTP_OK,
        array $headers = []
    )
    {
        parent::__construct($data, $statusCode, $message, $headers);
    }
} 