<?php

namespace App\Domain\Project\ValueObjects;

use App\Enums\ProjectOrganizationRole;

class ProjectContext
{
    public function __construct(
        public readonly int $projectId,
        public readonly string $projectName,
        public readonly int $organizationId,
        public readonly string $organizationName,
        public readonly ProjectOrganizationRole $role,
        public readonly ProjectRoleConfig $roleConfig,
        public readonly bool $isOwner,
    ) {}
    
    /**
     * Проверить есть ли право
     */
    public function hasPermission(string $permission): bool
    {
        return $this->roleConfig->hasPermission($permission);
    }
    
    /**
     * Может ли создавать работы
     */
    public function canCreateWorks(): bool
    {
        return $this->roleConfig->canCreateWorks();
    }
    
    /**
     * Может ли утверждать работы
     */
    public function canApproveWorks(): bool
    {
        return $this->roleConfig->canApproveWorks();
    }
    
    /**
     * Нужно ли автоматически заполнять contractor_id
     */
    public function shouldAutoFillContractor(): bool
    {
        return $this->roleConfig->shouldAutoFillContractor();
    }
    
    /**
     * Получить auto-filled contractor_id (если нужно)
     */
    public function getAutoFilledContractorId(): ?int
    {
        if ($this->shouldAutoFillContractor()) {
            return $this->organizationId;
        }
        return null;
    }
    
    /**
     * Может ли организация создавать entity с указанным contractor_id
     */
    public function canCreateEntityWithContractor(?int $contractorId): bool
    {
        // Owner и general_contractor могут создавать для любого подрядчика
        if (in_array($this->role, [
            ProjectOrganizationRole::OWNER,
            ProjectOrganizationRole::GENERAL_CONTRACTOR
        ])) {
            return true;
        }
        
        // Contractor и Subcontractor могут создавать только для себя
        if (in_array($this->role, [
            ProjectOrganizationRole::CONTRACTOR,
            ProjectOrganizationRole::SUBCONTRACTOR
        ])) {
            return $contractorId === null || $contractorId === $this->organizationId;
        }
        
        // Остальные не могут создавать с contractor_id
        return false;
    }
    
    /**
     * Видит только свои данные?
     */
    public function viewsOnlyOwn(): bool
    {
        return $this->roleConfig->viewsOnlyOwn();
    }
    
    /**
     * Только чтение?
     */
    public function isReadOnly(): bool
    {
        return $this->roleConfig->isReadOnly();
    }
    
    /**
     * Преобразовать в массив для JSON response
     */
    public function toArray(): array
    {
        return [
            'project_id' => $this->projectId,
            'project_name' => $this->projectName,
            'organization_id' => $this->organizationId,
            'organization_name' => $this->organizationName,
            'role' => [
                'value' => $this->role->value,
                'label' => $this->role->label(),
            ],
            'is_owner' => $this->isOwner,
            'role_config' => $this->roleConfig->toArray(),
        ];
    }
}

