<?php

namespace App\Domain\Project\ValueObjects;

class ProjectRoleConfig
{
    public function __construct(
        public readonly \App\Enums\ProjectOrganizationRole $role,
        public readonly array $permissions,
        public readonly bool $canManageContracts,
        public readonly bool $canViewFinances,
        public readonly bool $canManageWorks,
        public readonly bool $canManageWarehouse,
        public readonly bool $canInviteParticipants,
        public readonly string $displayLabel,
    ) {}
    
    /**
     * Проверить есть ли право
     */
    public function hasPermission(string $permission): bool
    {
        // Wildcard support: projects.* matches projects.view
        foreach ($this->permissions as $allowed) {
            if ($this->matchesPermission($permission, $allowed)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Нужно ли автоматически заполнять contractor_id
     */
    public function shouldAutoFillContractor(): bool
    {
        return ($this->ui_behavior['contractor_field'] ?? '') === 'hidden_autofill';
    }
    
    /**
     * Может ли создавать работы
     */
    public function canCreateWorks(): bool
    {
        return $this->ui_behavior['can_create_works'] ?? false;
    }
    
    /**
     * Может ли утверждать работы
     */
    public function canApproveWorks(): bool
    {
        return $this->ui_behavior['can_approve_works'] ?? false;
    }
    
    /**
     * Может ли назначать подрядчиков
     */
    public function canAssignContractors(): bool
    {
        return $this->ui_behavior['can_assign_contractors'] ?? false;
    }
    
    /**
     * Область видимости данных (all или own)
     */
    public function getViewScope(): string
    {
        return $this->ui_behavior['view_scope'] ?? 'all';
    }
    
    /**
     * Видит только свои данные?
     */
    public function viewsOnlyOwn(): bool
    {
        return $this->getViewScope() === 'own';
    }
    
    /**
     * Только чтение?
     */
    public function isReadOnly(): bool
    {
        return $this->ui_behavior['read_only'] ?? false;
    }
    
    /**
     * Получить явно видимые модули (null = все кроме hidden)
     */
    public function getVisibleModules(): ?array
    {
        return $this->ui_behavior['modules_visible'] ?? null;
    }
    
    /**
     * Получить скрытые модули
     */
    public function getHiddenModules(): array
    {
        return $this->ui_behavior['modules_hidden'] ?? [];
    }
    
    /**
     * Получить режим поля contractor_id для форм
     */
    public function getContractorFieldMode(): string
    {
        return $this->ui_behavior['contractor_field'] ?? 'visible';
    }
    
    /**
     * Проверка совпадения permission с pattern (поддержка wildcard)
     */
    private function matchesPermission(string $permission, string $pattern): bool
    {
        // Полный wildcard
        if ($pattern === '*') {
            return true;
        }
        
        // Wildcard на конце: projects.*
        if (str_ends_with($pattern, '.*')) {
            $prefix = rtrim($pattern, '.*');
            return str_starts_with($permission, $prefix);
        }
        
        // Wildcard в начале: *.view
        if (str_starts_with($pattern, '*.')) {
            $suffix = ltrim($pattern, '*');
            return str_ends_with($permission, $suffix);
        }
        
        // Точное совпадение
        return $permission === $pattern;
    }
    
    /**
     * Преобразовать в массив для JSON response
     */
    public function toArray(): array
    {
        return [
            'role' => $this->role->value,
            'permissions' => $this->permissions,
            'can_manage_contracts' => $this->canManageContracts,
            'can_view_finances' => $this->canViewFinances,
            'can_manage_works' => $this->canManageWorks,
            'can_manage_warehouse' => $this->canManageWarehouse,
            'can_invite_participants' => $this->canInviteParticipants,
            'display_label' => $this->displayLabel,
        ];
    }
}

