<?php

namespace App\Http\Responses;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Symfony\Component\HttpFoundation\Response;

abstract class ApiResponse implements Responsable
{
    protected $data;
    protected int $statusCode;
    protected string $message;
    protected array $headers;

    public function __construct(
        JsonResource|array|null $data = null,
        int $statusCode = 200,
        string|null $message = '',
        array $headers = []
    ) {
        $this->data = $data;
        $this->statusCode = $statusCode;
        $this->message = $message ?? '';
        $this->headers = $headers;
    }

    /**
     * Create an HTTP response that represents the object.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function toResponse($request): JsonResponse
    {
        $response = [
            'success' => $this->isSuccessful(),
            'message' => $this->message,
        ];

        if ($this->data !== null) {
            $response['data'] = $this->data instanceof JsonResource
                ? $this->data->response($request)->getData(true)
                : $this->data;
        }

        return new JsonResponse(
            $response,
            $this->statusCode,
            $this->headers
        );
    }

    /**
     * Check if the status code represents success.
     */
    protected function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    /**
     * Prepare data for the response.
     * Handles cases where data might be a Resource, Collection, or plain array/object.
     */
    protected function prepareData(mixed $data): array
    {
        if ($data instanceof \Illuminate\Http\Resources\Json\JsonResource) {
            // Оборачиваем одиночный ресурс в ключ 'data' по умолчанию
            return ['data' => $data->resolve()];
        }

        if ($data instanceof \Illuminate\Http\Resources\Json\ResourceCollection) {
            // Коллекции уже содержат ключ 'data' внутри себя
            return $data->resolve();
        }

        // Для простых массивов или объектов возвращаем как есть
        if (is_array($data) || is_object($data)) {
            return (array) $data; // Может потребоваться обернуть в 'data' ключ
        }
        
        // Если простое значение, можно обернуть
        return ['data' => $data];
    }

    public static function success(string $message = 'Операция выполнена успешно', array $data = [], int $statusCode = 200): self
    {
        return new static($data, $statusCode, $message);
    }

    public static function error(string $message = 'Произошла ошибка', int $statusCode = 400, array $data = []): self
    {
        return new static($data, $statusCode, $message);
    }
} 