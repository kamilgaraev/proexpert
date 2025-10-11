<?php

namespace App\BusinessModules\Features\AIAssistant\Services;

/**
 * Результат выполнения действия ИИ
 */
class ActionResult
{
    public bool $success;
    public mixed $data;
    public ?string $error;
    public ?string $confirmation_required;
    public array $metadata;

    public function __construct(
        bool $success = false,
        mixed $data = null,
        ?string $error = null,
        ?string $confirmation_required = null,
        array $metadata = []
    ) {
        $this->success = $success;
        $this->data = $data;
        $this->error = $error;
        $this->confirmation_required = $confirmation_required;
        $this->metadata = $metadata;
    }

    /**
     * Успешный результат
     */
    public static function success(mixed $data = null, array $metadata = []): self
    {
        return new self(true, $data, null, null, $metadata);
    }

    /**
     * Результат с ошибкой
     */
    public static function error(string $error, array $metadata = []): self
    {
        return new self(false, null, $error, null, $metadata);
    }

    /**
     * Результат требующий подтверждения
     */
    public static function confirmationRequired(string $confirmation_message, array $metadata = []): self
    {
        return new self(false, null, null, $confirmation_message, $metadata);
    }

    /**
     * Проверить, успешен ли результат
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Проверить, требуется ли подтверждение
     */
    public function requiresConfirmation(): bool
    {
        return $this->confirmation_required !== null;
    }

    /**
     * Получить сообщение о подтверждении
     */
    public function getConfirmationMessage(): ?string
    {
        return $this->confirmation_required;
    }

    /**
     * Получить данные результата
     */
    public function getData(): mixed
    {
        return $this->data;
    }

    /**
     * Получить ошибку
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * Получить метаданные
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Добавить метаданные
     */
    public function addMetadata(string $key, mixed $value): self
    {
        $this->metadata[$key] = $value;
        return $this;
    }
}
