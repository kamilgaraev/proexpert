<?php

namespace App\Domain\Authorization\Exceptions;

use Exception;

/**
 * Исключение для случаев, когда роль не найдена
 */
class RoleNotFoundException extends Exception
{
    protected string $roleSlug;
    protected ?int $organizationId;
    protected string $roleType;

    public function __construct(
        string $roleSlug,
        string $roleType = 'system',
        ?int $organizationId = null,
        string $message = null,
        int $code = 404,
        Exception $previous = null
    ) {
        $this->roleSlug = $roleSlug;
        $this->roleType = $roleType;
        $this->organizationId = $organizationId;

        $message = $message ?: $this->generateMessage();
        
        parent::__construct($message, $code, $previous);
    }

    /**
     * Создать исключение для системной роли
     */
    public static function systemRole(string $roleSlug): self
    {
        return new self(
            $roleSlug,
            'system',
            null,
            "Системная роль '$roleSlug' не найдена"
        );
    }

    /**
     * Создать исключение для кастомной роли
     */
    public static function customRole(string $roleSlug, int $organizationId): self
    {
        return new self(
            $roleSlug,
            'custom',
            $organizationId,
            "Кастомная роль '$roleSlug' не найдена в организации $organizationId"
        );
    }

    /**
     * Создать исключение для неактивной роли
     */
    public static function inactiveRole(string $roleSlug, string $roleType = 'system', ?int $organizationId = null): self
    {
        $orgText = $organizationId ? " в организации $organizationId" : "";
        $message = "Роль '$roleSlug' ($roleType) деактивирована$orgText";
        
        return new self($roleSlug, $roleType, $organizationId, $message);
    }

    /**
     * Получить слаг роли
     */
    public function getRoleSlug(): string
    {
        return $this->roleSlug;
    }

    /**
     * Получить тип роли
     */
    public function getRoleType(): string
    {
        return $this->roleType;
    }

    /**
     * Получить ID организации
     */
    public function getOrganizationId(): ?int
    {
        return $this->organizationId;
    }

    /**
     * Получить данные для логирования
     */
    public function getLoggingData(): array
    {
        return [
            'exception' => static::class,
            'role_slug' => $this->roleSlug,
            'role_type' => $this->roleType,
            'organization_id' => $this->organizationId,
            'message' => $this->getMessage(),
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Преобразовать в HTTP ответ
     */
    public function toHttpResponse(): array
    {
        return [
            'error' => 'Role Not Found',
            'message' => $this->getMessage(),
            'role_slug' => $this->roleSlug,
            'role_type' => $this->roleType,
            'organization_id' => $this->organizationId,
            'code' => $this->getCode(),
        ];
    }

    /**
     * Сгенерировать сообщение об ошибке
     */
    protected function generateMessage(): string
    {
        $typeText = $this->roleType === 'system' ? 'Системная' : 'Кастомная';
        $orgText = $this->organizationId ? " в организации {$this->organizationId}" : "";
        
        return "{$typeText} роль '{$this->roleSlug}' не найдена{$orgText}";
    }
}
