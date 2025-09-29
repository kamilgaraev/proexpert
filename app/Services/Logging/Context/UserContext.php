<?php

namespace App\Services\Logging\Context;

use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\LandingAdmin;
use App\Services\Organization\OrganizationContext;

class UserContext
{
    protected ?int $userId = null;
    protected ?int $organizationId = null;
    protected ?string $userType = null;
    protected array $userRoles = [];
    protected ?string $interface = null;

    public function __construct()
    {
        $this->collectUserContext();
    }

    /**
     * Собрать информацию о текущем пользователе
     */
    protected function collectUserContext(): void
    {
        $user = Auth::user();

        if ($user) {
            $this->userId = $user->id;
            $this->userType = $this->determineUserType($user);
            
            // Получить ID организации из контекста
            $this->organizationId = OrganizationContext::getOrganizationId();
            
            // Если не найдено в контексте, попробовать из пользователя
            if (!$this->organizationId && method_exists($user, 'current_organization_id')) {
                $this->organizationId = $user->current_organization_id;
            }

            // Для обычных пользователей попробовать получить из связей
            if (!$this->organizationId && $user instanceof User) {
                $firstOrg = $user->organizations()->first();
                if ($firstOrg) {
                    $this->organizationId = $firstOrg->id;
                }
            }

            $this->collectUserRoles($user);
        }
    }

    /**
     * Определить тип пользователя
     */
    protected function determineUserType($user): string
    {
        if ($user instanceof LandingAdmin) {
            return 'landing_admin';
        } elseif ($user instanceof User) {
            return 'user';
        }

        return 'unknown';
    }

    /**
     * Собрать роли пользователя (безопасно)
     */
    protected function collectUserRoles($user): void
    {
        try {
            if ($user instanceof User && $this->organizationId) {
                // Попробовать получить роли из новой системы авторизации
                $authService = app(\App\Domain\Authorization\Services\AuthorizationService::class);
                $roles = $authService->getUserRoles($user);
                
                $this->userRoles = $roles->map(function ($assignment) {
                    return [
                        'slug' => $assignment->role_slug,
                        'type' => $assignment->role_type,
                        'context_id' => $assignment->context_id
                    ];
                })->toArray();
            }
        } catch (\Exception $e) {
            // Новая система авторизации может быть не готова
            $this->userRoles = [];
        }
    }

    /**
     * Получить ID пользователя
     */
    public function getUserId(): ?int
    {
        return $this->userId;
    }

    /**
     * Получить ID организации
     */
    public function getOrganizationId(): ?int
    {
        return $this->organizationId;
    }

    /**
     * Получить тип пользователя
     */
    public function getUserType(): ?string
    {
        return $this->userType;
    }

    /**
     * Получить роли пользователя
     */
    public function getUserRoles(): array
    {
        return $this->userRoles;
    }

    /**
     * Установить контекст пользователя извне
     */
    public function setUserContext(?int $userId, ?int $organizationId = null): void
    {
        $this->userId = $userId;
        $this->organizationId = $organizationId;
        
        if ($userId) {
            $user = User::find($userId);
            if ($user) {
                $this->userType = $this->determineUserType($user);
                $this->collectUserRoles($user);
            }
        }
    }

    /**
     * Проверить, аутентифицирован ли пользователь
     */
    public function isAuthenticated(): bool
    {
        return $this->userId !== null;
    }

    /**
     * Проверить, есть ли у пользователя определенная роль
     */
    public function hasRole(string $roleSlug): bool
    {
        foreach ($this->userRoles as $role) {
            if ($role['slug'] === $roleSlug) {
                return true;
            }
        }

        return false;
    }

    /**
     * Получить контекстную информацию для логирования
     */
    public function getContextInfo(): array
    {
        return [
            'user_id' => $this->userId,
            'organization_id' => $this->organizationId,
            'user_type' => $this->userType,
            'roles_count' => count($this->userRoles),
            'is_authenticated' => $this->isAuthenticated()
        ];
    }

    /**
     * Получить роли для безопасного логирования (только slugs)
     */
    public function getRolesSafe(): array
    {
        return array_column($this->userRoles, 'slug');
    }

    /**
     * Очистить контекст (для тестирования)
     */
    public function clear(): void
    {
        $this->userId = null;
        $this->organizationId = null;
        $this->userType = null;
        $this->userRoles = [];
    }
}
