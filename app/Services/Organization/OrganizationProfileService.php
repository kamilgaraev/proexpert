<?php

declare(strict_types=1);

namespace App\Services\Organization;

use App\Domain\Organization\ValueObjects\OrganizationProfile;
use App\Enums\OrganizationCapability;
use App\Events\OrganizationOnboardingCompleted;
use App\Events\OrganizationProfileUpdated;
use App\Models\Organization;
use App\Support\Organization\OrganizationWorkspaceProfileCatalog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class OrganizationProfileService
{
    public function updateCapabilities(Organization $organization, array $capabilities): Organization
    {
        $oldCapabilities = $organization->capabilities ?? [];
        $validCapabilities = $this->normalizeCapabilities($capabilities);
        $normalizedPrimaryBusinessType = OrganizationWorkspaceProfileCatalog::normalizePrimaryProfile(
            $validCapabilities,
            $organization->primary_business_type
        );

        $organization->update([
            'capabilities' => $validCapabilities,
            'primary_business_type' => $normalizedPrimaryBusinessType,
        ]);

        $this->calculateProfileCompleteness($organization);

        Log::info('Organization capabilities updated', [
            'organization_id' => $organization->id,
            'capabilities' => $validCapabilities,
        ]);

        event(new OrganizationProfileUpdated(
            $organization,
            'capabilities',
            $oldCapabilities,
            $validCapabilities,
            Auth::user()
        ));

        return $organization->fresh();
    }

    public function updatePrimaryBusinessType(Organization $organization, string $businessType): Organization
    {
        $capabilities = $this->normalizeCapabilities($organization->capabilities ?? []);

        if ($capabilities !== [] && !in_array($businessType, $capabilities, true)) {
            throw new \InvalidArgumentException('Primary business type must be one of selected capabilities.');
        }

        $organization->update([
            'primary_business_type' => OrganizationWorkspaceProfileCatalog::normalizePrimaryProfile(
                $capabilities,
                $businessType
            ),
        ]);

        $this->calculateProfileCompleteness($organization);

        Log::info('Organization primary business type updated', [
            'organization_id' => $organization->id,
            'business_type' => $businessType,
        ]);

        return $organization->fresh();
    }

    public function updateSpecializations(Organization $organization, array $specializations): Organization
    {
        $organization->update([
            'specializations' => $specializations,
        ]);

        $this->calculateProfileCompleteness($organization);

        Log::info('Organization specializations updated', [
            'organization_id' => $organization->id,
            'specializations_count' => count($specializations),
        ]);

        return $organization->fresh();
    }

    public function updateCertifications(Organization $organization, array $certifications): Organization
    {
        $organization->update([
            'certifications' => $certifications,
        ]);

        $this->calculateProfileCompleteness($organization);

        Log::info('Organization certifications updated', [
            'organization_id' => $organization->id,
            'certifications_count' => count($certifications),
        ]);

        return $organization->fresh();
    }

    public function getProfile(Organization $organization): OrganizationProfile
    {
        return new OrganizationProfile(
            organizationId: $organization->id,
            capabilities: $organization->capabilities ?? [],
            primaryBusinessType: $organization->primary_business_type,
            specializations: $organization->specializations ?? [],
            certifications: $organization->certifications ?? [],
            profileCompleteness: $organization->profile_completeness ?? 0,
            onboardingCompleted: $organization->onboarding_completed ?? false,
            onboardingCompletedAt: $organization->onboarding_completed_at
        );
    }

    public function hasCapability(Organization $organization, OrganizationCapability $capability): bool
    {
        $capabilities = $organization->capabilities ?? [];

        return in_array($capability->value, $capabilities, true);
    }

    public function addCapability(Organization $organization, OrganizationCapability $capability): Organization
    {
        $capabilities = $this->normalizeCapabilities($organization->capabilities ?? []);

        if (!in_array($capability->value, $capabilities, true)) {
            $capabilities[] = $capability->value;

            $organization->update([
                'capabilities' => $capabilities,
                'primary_business_type' => OrganizationWorkspaceProfileCatalog::normalizePrimaryProfile(
                    $capabilities,
                    $organization->primary_business_type
                ),
            ]);

            $this->calculateProfileCompleteness($organization);

            Log::info('Organization capability added', [
                'organization_id' => $organization->id,
                'capability' => $capability->value,
            ]);
        }

        return $organization->fresh();
    }

    public function removeCapability(Organization $organization, OrganizationCapability $capability): Organization
    {
        $capabilities = $this->normalizeCapabilities($organization->capabilities ?? []);
        $capabilities = array_values(array_filter($capabilities, fn ($cap) => $cap !== $capability->value));

        $organization->update([
            'capabilities' => $capabilities,
            'primary_business_type' => OrganizationWorkspaceProfileCatalog::normalizePrimaryProfile(
                $capabilities,
                $organization->primary_business_type
            ),
        ]);

        $this->calculateProfileCompleteness($organization);

        Log::info('Organization capability removed', [
            'organization_id' => $organization->id,
            'capability' => $capability->value,
        ]);

        return $organization->fresh();
    }

    public function calculateProfileCompleteness(Organization $organization): int
    {
        $normalizedPrimaryBusinessType = OrganizationWorkspaceProfileCatalog::normalizePrimaryProfile(
            $organization->capabilities ?? [],
            $organization->primary_business_type
        );

        $completeness = 0;

        if (!empty($organization->capabilities)) {
            $completeness += 30;
        }

        if (!empty($normalizedPrimaryBusinessType)) {
            $completeness += 30;
        }

        if (!empty($organization->specializations)) {
            $completeness += 20;
        }

        if (!empty($organization->certifications)) {
            $completeness += 20;
        }

        $organization->update([
            'primary_business_type' => $normalizedPrimaryBusinessType,
            'profile_completeness' => min($completeness, 100),
        ]);

        return $completeness;
    }

    public function completeOnboarding(Organization $organization): Organization
    {
        if ($organization->onboarding_completed) {
            return $organization;
        }

        $organization->update([
            'onboarding_completed' => true,
            'onboarding_completed_at' => now(),
        ]);

        Log::info('Organization onboarding completed', [
            'organization_id' => $organization->id,
        ]);

        event(new OrganizationOnboardingCompleted($organization, Auth::user()));

        return $organization->fresh();
    }

    public function resetOnboarding(Organization $organization): Organization
    {
        $organization->update([
            'onboarding_completed' => false,
            'onboarding_completed_at' => null,
        ]);

        Log::info('Organization onboarding reset', [
            'organization_id' => $organization->id,
        ]);

        return $organization->fresh();
    }

    public function validateCapabilitiesForRole(
        Organization $organization,
        \App\Enums\ProjectOrganizationRole $role
    ): \App\Domain\Common\ValidationResult {
        $capabilityValues = $this->normalizeCapabilities($organization->capabilities ?? []);

        if ($capabilityValues === []) {
            $isFallbackRole = in_array($role, [
                \App\Enums\ProjectOrganizationRole::CUSTOMER,
                \App\Enums\ProjectOrganizationRole::OBSERVER,
            ], true);

            return new \App\Domain\Common\ValidationResult(
                isValid: $isFallbackRole,
                errors: $isFallbackRole
                    ? []
                    : ['Организация не настроила направления деятельности для выбранной роли проекта.']
            );
        }

        $allowedRoles = OrganizationWorkspaceProfileCatalog::allowedProjectRoles($capabilityValues);
        $isValid = in_array($role->value, $allowedRoles, true);

        return new \App\Domain\Common\ValidationResult(
            isValid: $isValid,
            errors: $isValid
                ? []
                : ['Организация не может выполнять роль "' . $role->value . '" с текущими capabilities.']
        );
    }

    private function normalizeCapabilities(array $capabilities): array
    {
        $normalized = [];

        foreach ($capabilities as $capability) {
            if (!is_string($capability) || OrganizationCapability::tryFrom($capability) === null) {
                continue;
            }

            if (!in_array($capability, $normalized, true)) {
                $normalized[] = $capability;
            }
        }

        return $normalized;
    }
}
