<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

final class ContractBuilderException extends BusinessLogicException
{
    public function __construct(private readonly string $messageKey, int $status, private readonly array $parameters = [], private readonly ?string $field = null)
    {
        parent::__construct(trans_message($messageKey, $parameters), $status);
    }

    public function render($request): JsonResponse
    {
        $message = trans_message($this->messageKey, $this->parameters);

        return AdminResponse::error($message, $this->getCode(), $this->field === null ? null : [$this->field => [$message]]);
    }

    public function atField(string $field): self
    {
        return new self($this->messageKey, $this->getCode(), $this->parameters, $field);
    }

    public function messageKey(): string
    {
        return $this->messageKey;
    }
}
