<?php

namespace App\Services\Organization;

use App\Models\Organization;
use App\Enums\OrganizationCapability;
use App\Domain\Organization\ValueObjects\OrganizationProfile;
use App\Events\OrganizationProfileUpdated;
use App\Events\OrganizationOnboardingCompleted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class OrganizationProfileService
{
    public function updateCapabilities(Organization $organization, array $capabilities): Organization
    {
        $oldCapabilities = $organization->capabilities ?? [];
        
        $validCapabilities = array_filter($capabilities, function ($capability) {
            return OrganizationCapability::tryFrom($capability) !== null;
        });

        $organization->update([
            'capabilities' => $validCapabilities,
        ]);

        $this->calculateProfileCompleteness($organization);

        Log::info('Organization capabilities updated', [
            'organization_id' => $organization->id,
            'capabilities' => $validCapabilities,
        ]);
        
        // Dispatch event
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
        $organization->update([
            'primary_business_type' => $businessType,
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
        return in_array($capability->value, $capabilities);
    }

    public function addCapability(Organization $organization, OrganizationCapability $capability): Organization
    {
        $capabilities = $organization->capabilities ?? [];
        
        if (!in_array($capability->value, $capabilities)) {
            $capabilities[] = $capability->value;
            
            $organization->update([
                'capabilities' => $capabilities,
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
        $capabilities = $organization->capabilities ?? [];
        $capabilities = array_values(array_filter($capabilities, fn($cap) => $cap !== $capability->value));

        $organization->update([
            'capabilities' => $capabilities,
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
        $completeness = 0;

        if (!empty($organization->capabilities)) {
            $completeness += 30;
        }

        if (!empty($organization->primary_business_type)) {
            $completeness += 30;
        }

        if (!empty($organization->specializations)) {
            $completeness += 20;
        }

        if (!empty($organization->certifications)) {
            $completeness += 20;
        }

        $organization->update([
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
        
        // Dispatch event
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
        $capabilities = array_map(
            fn($cap) => OrganizationCapability::tryFrom($cap),
            $organization->capabilities ?? []
        );

        $capabilities = array_filter($capabilities);

        $errors = [];

        switch ($role) {
            case \App\Enums\ProjectOrganizationRole::CUSTOMER:
                break;

            case \App\Enums\ProjectOrganizationRole::GENERAL_CONTRACTOR:
                if (!in_array(OrganizationCapability::GENERAL_CONTRACTING, $capabilities)) {
                    $errors[] = 'Организация не имеет capability "general_contracting"';
                }
                break;

            case \App\Enums\ProjectOrganizationRole::CONTRACTOR:
                if (!in_array(OrganizationCapability::GENERAL_CONTRACTING, $capabilities) &&
                    !in_array(OrganizationCapability::SUBCONTRACTING, $capabilities)) {
                    $errors[] = 'Организация не имеет capability "general_contracting" или "subcontracting"';
                }
                break;
                
            case \App\Enums\ProjectOrganizationRole::SUBCONTRACTOR:
                if (!in_array(OrganizationCapability::SUBCONTRACTING, $capabilities)) {
                    $errors[] = 'Организация не имеет capability "subcontracting"';
                }
                break;

            case \App\Enums\ProjectOrganizationRole::CONSTRUCTION_SUPERVISION:
                if (!in_array(OrganizationCapability::CONSTRUCTION_SUPERVISION, $capabilities)) {
                    $errors[] = 'Организация не имеет capability "construction_supervision"';
                }
                break;

            case \App\Enums\ProjectOrganizationRole::DESIGNER:
                if (!in_array(OrganizationCapability::DESIGN, $capabilities)) {
                    $errors[] = 'Организация не имеет capability "design"';
                }
                break;

            case \App\Enums\ProjectOrganizationRole::OBSERVER:
                break;
        }

        return new \App\Domain\Common\ValidationResult(
            isValid: count($errors) === 0,
            errors: $errors
        );
    }
}

