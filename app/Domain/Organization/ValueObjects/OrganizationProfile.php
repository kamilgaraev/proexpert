<?php

namespace App\Domain\Organization\ValueObjects;

use App\Enums\OrganizationCapability;
use App\Enums\ProjectOrganizationRole;

class OrganizationProfile
{
    public function __construct(
        private int $organizationId,
        private array $capabilities,
        private ?string $primaryBusinessType,
        private array $specializations,
        private array $certifications,
        private int $profileCompleteness,
        private bool $onboardingCompleted,
        private ?\DateTime $onboardingCompletedAt = null,
    ) {}
    
    /**
     * Есть ли capability
     */
    public function hasCapability(OrganizationCapability $capability): bool
    {
        return in_array($capability->value, $this->capabilities);
    }
    
    /**
     * Может ли организация выполнять роль в проекте
     */
    public function canPerformRole(ProjectOrganizationRole $role): bool
    {
        // Если нет capabilities - можем выполнять любую роль (для обратной совместимости)
        if (empty($this->capabilities)) {
            return true;
        }
        
        // Проверяем каждую capability
        foreach ($this->capabilities as $capabilityValue) {
            $capability = OrganizationCapability::tryFrom($capabilityValue);
            
            if ($capability && $capability->supportsProjectRole($role)) {
                return true;
            }
        }
        
        // Observer может быть любой
        if ($role === ProjectOrganizationRole::OBSERVER) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Получить рекомендуемые модули на основе capabilities
     */
    public function getRecommendedModules(): array
    {
        $modules = [];
        
        foreach ($this->capabilities as $capabilityValue) {
            $capability = OrganizationCapability::tryFrom($capabilityValue);
            
            if ($capability) {
                $modules = array_merge($modules, $capability->recommendedModules());
            }
        }
        
        return \App\Helpers\ModuleHelper::formatModules($modules);
    }
    
    /**
     * Рассчитать заполненность профиля (0-100%)
     */
    public function calculateCompleteness(): int
    {
        $score = 0;
        
        if (!empty($this->capabilities)) {
            $score += 30;
        }
        
        if ($this->primaryBusinessType) {
            $score += 20;
        }
        
        if (!empty($this->specializations)) {
            $score += 20;
        }
        
        if (!empty($this->certifications)) {
            $score += 30;
        }
        
        return $score;
    }
    
    public function getOrganizationId(): int
    {
        return $this->organizationId;
    }
    
    public function getCapabilities(): array
    {
        return $this->capabilities;
    }
    
    /**
     * Получить primary business type как enum
     */
    public function getPrimaryBusinessType(): ?OrganizationCapability
    {
        if (!$this->primaryBusinessType) {
            return null;
        }
        
        return OrganizationCapability::tryFrom($this->primaryBusinessType);
    }
    
    public function getSpecializations(): array
    {
        return $this->specializations;
    }
    
    public function getCertifications(): array
    {
        return $this->certifications;
    }
    
    public function getProfileCompleteness(): int
    {
        return $this->profileCompleteness;
    }
    
    public function isOnboardingCompleted(): bool
    {
        return $this->onboardingCompleted;
    }
    
    public function getOnboardingCompletedAt(): ?\DateTime
    {
        return $this->onboardingCompletedAt;
    }
    
    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'capabilities' => $this->capabilities,
            'primary_business_type' => $this->primaryBusinessType,
            'specializations' => $this->specializations,
            'certifications' => $this->certifications,
            'profile_completeness' => $this->profileCompleteness,
            'onboarding_completed' => $this->onboardingCompleted,
            'onboarding_completed_at' => $this->onboardingCompletedAt?->format('Y-m-d H:i:s'),
        ];
    }
    
    /**
     * Создать из массива
     */
    public static function fromArray(array $data): self
    {
        $onboardingCompletedAt = null;
        if (!empty($data['onboarding_completed_at'])) {
            $onboardingCompletedAt = $data['onboarding_completed_at'] instanceof \DateTime 
                ? $data['onboarding_completed_at'] 
                : new \DateTime($data['onboarding_completed_at']);
        }
        
        return new self(
            organizationId: $data['organization_id'] ?? 0,
            capabilities: $data['capabilities'] ?? [],
            primaryBusinessType: $data['primary_business_type'] ?? null,
            specializations: $data['specializations'] ?? [],
            certifications: $data['certifications'] ?? [],
            profileCompleteness: $data['profile_completeness'] ?? 0,
            onboardingCompleted: $data['onboarding_completed'] ?? false,
            onboardingCompletedAt: $onboardingCompletedAt,
        );
    }
}

