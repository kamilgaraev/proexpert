<?php

namespace App\Services\Security;

use App\Models\Organization;
use App\Models\Contractor;
use App\Models\OrganizationAccessRestriction;
use App\Services\OrganizationVerificationService;
use Illuminate\Support\Facades\Log;

class ContractorAutoVerificationService
{
    public function __construct(
        private OrganizationVerificationService $verificationService
    ) {}

    public function verifyAndSetAccess(Organization $organization): array
    {
        Log::info('[ContractorAutoVerification] Starting verification', [
            'organization_id' => $organization->id,
            'tax_number' => $organization->tax_number
        ]);

        $verificationResult = $this->verificationService->verifyOrganization($organization);
        $score = $verificationResult['verification_score'] ?? 0;
        
        $accessLevel = $this->determineAccessLevel($score);
        
        if ($accessLevel['needs_restriction']) {
            $this->applyRestrictions($organization, $accessLevel, $score);
        }

        Log::info('[ContractorAutoVerification] Verification completed', [
            'organization_id' => $organization->id,
            'score' => $score,
            'access_level' => $accessLevel['level']
        ]);

        return [
            'verification_score' => $score,
            'access_level' => $accessLevel['level'],
            'restrictions_applied' => $accessLevel['needs_restriction'],
            'verification_data' => $verificationResult
        ];
    }

    private function determineAccessLevel(int $score): array
    {
        if ($score >= 90) {
            return [
                'level' => 'trusted',
                'needs_restriction' => true,
                'allowed_actions' => [
                    'view_contracts',
                    'view_projects',
                    'create_acts',
                    'upload_documents',
                    'edit_works',
                    'view_reports',
                ],
                'blocked_actions' => ['request_payments'],
                'expires_in_hours' => 24,
                'reason' => 'Новая организация с высоким рейтингом верификации. Полный доступ через 24 часа.',
            ];
        }

        if ($score >= 70) {
            return [
                'level' => 'standard',
                'needs_restriction' => true,
                'allowed_actions' => [
                    'view_contracts',
                    'view_projects',
                    'create_acts',
                    'upload_documents',
                    'edit_works',
                ],
                'blocked_actions' => ['request_payments', 'bulk_export'],
                'expires_in_hours' => 72,
                'reason' => 'Новая организация со средним рейтингом верификации. Полный доступ через 3 дня.',
            ];
        }

        return [
            'level' => 'restricted',
            'needs_restriction' => true,
            'allowed_actions' => ['view_contracts', 'view_projects'],
            'blocked_actions' => [
                'create_acts',
                'upload_documents',
                'request_payments',
                'edit_works',
                'bulk_export'
            ],
            'expires_in_hours' => null,
            'reason' => 'Новая организация с низким рейтингом верификации. Требуется подтверждение от заказчика.',
        ];
    }

    private function applyRestrictions(Organization $organization, array $accessLevel, int $score): void
    {
        $expiresAt = $accessLevel['expires_in_hours'] 
            ? now()->addHours($accessLevel['expires_in_hours'])
            : null;

        OrganizationAccessRestriction::create([
            'organization_id' => $organization->id,
            'restriction_type' => 'new_contractor_verification',
            'access_level' => $accessLevel['level'],
            'allowed_actions' => $accessLevel['allowed_actions'],
            'blocked_actions' => $accessLevel['blocked_actions'],
            'reason' => $accessLevel['reason'],
            'expires_at' => $expiresAt,
            'can_be_lifted_early' => true,
            'lift_conditions' => [
                'customer_confirmation_required' => $score < 70,
                'time_based' => $score >= 70,
                'reputation_threshold' => 80,
            ],
            'metadata' => [
                'verification_score' => $score,
                'applied_at' => now()->toDateTimeString(),
            ],
        ]);

        Log::info('[ContractorAutoVerification] Restrictions applied', [
            'organization_id' => $organization->id,
            'access_level' => $accessLevel['level'],
            'expires_at' => $expiresAt?->toDateTimeString()
        ]);
    }

    public function canPerformAction(Organization $organization, string $action): bool
    {
        $activeRestriction = OrganizationAccessRestriction::where('organization_id', $organization->id)
            ->active()
            ->first();

        if (!$activeRestriction) {
            return true;
        }

        return $activeRestriction->canPerformAction($action);
    }

    public function getActiveRestrictions(Organization $organization): ?OrganizationAccessRestriction
    {
        return OrganizationAccessRestriction::where('organization_id', $organization->id)
            ->active()
            ->first();
    }
}

