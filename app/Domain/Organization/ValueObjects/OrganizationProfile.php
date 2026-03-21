<?php

declare(strict_types=1);

namespace App\Domain\Organization\ValueObjects;

use App\Enums\OrganizationCapability;
use App\Enums\ProjectOrganizationRole;
use App\Support\Organization\OrganizationWorkspaceProfileCatalog;

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

    public function hasCapability(OrganizationCapability $capability): bool
    {
        return in_array($capability->value, $this->capabilities, true);
    }

    public function canPerformRole(ProjectOrganizationRole $role): bool
    {
        if (empty($this->capabilities)) {
            return true;
        }

        return in_array($role->value, $this->getAllowedProjectRoles(), true);
    }

    public function getRecommendedModules(): array
    {
        return OrganizationWorkspaceProfileCatalog::recommendedModules(
            $this->capabilities,
            $this->primaryBusinessType
        );
    }

    public function getWorkspaceProfile(): array
    {
        return OrganizationWorkspaceProfileCatalog::buildWorkspaceProfile(
            $this->capabilities,
            $this->primaryBusinessType
        );
    }

    public function getAllowedProjectRoles(): array
    {
        return OrganizationWorkspaceProfileCatalog::allowedProjectRoles($this->capabilities);
    }

    public function calculateCompleteness(): int
    {
        $score = 0;

        if (!empty($this->capabilities)) {
            $score += 30;
        }

        if ($this->getPrimaryBusinessType() !== null) {
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

    public function getPrimaryBusinessType(): ?OrganizationCapability
    {
        return OrganizationWorkspaceProfileCatalog::resolvePrimaryProfile(
            $this->capabilities,
            $this->primaryBusinessType
        );
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
            'primary_business_type' => $this->getPrimaryBusinessType()?->value,
            'specializations' => $this->specializations,
            'certifications' => $this->certifications,
            'profile_completeness' => $this->profileCompleteness,
            'onboarding_completed' => $this->onboardingCompleted,
            'onboarding_completed_at' => $this->onboardingCompletedAt?->format('Y-m-d H:i:s'),
            'recommended_modules' => $this->getRecommendedModules(),
            'workspace_profile' => $this->getWorkspaceProfile(),
        ];
    }

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
