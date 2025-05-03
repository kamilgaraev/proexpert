<?php

namespace App\Http\Responses;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;

class ApiResponse implements Responsable
{
    protected bool $success;
    protected string $message;
    protected int $statusCode;
    protected array $data;

    public function __construct(bool $success, string $message, int $statusCode = 200, array $data = [])
    {
        $this->success = $success;
        $this->message = $message;
        $this->statusCode = $statusCode;
        $this->data = $data;
    }

    public function toResponse($request): JsonResponse
    {
        $response = [
            'success' => $this->success,
            'message' => $this->message,
        ];

        if (!empty($this->data)) {
            $response = array_merge($response, $this->data);
        }

        return response()->json($response, $this->statusCode);
    }

    public static function success(string $message = 'Операция выполнена успешно', array $data = [], int $statusCode = 200): self
    {
        return new self(true, $message, $statusCode, $data);
    }

    public static function error(string $message = 'Произошла ошибка', int $statusCode = 400, array $data = []): self
    {
        return new self(false, $message, $statusCode, $data);
    }
} 