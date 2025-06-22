<?php

namespace App\Services;

use App\Models\Organization;
use App\Services\DaDataService;
use Illuminate\Support\Facades\Log;

class OrganizationVerificationService
{
    private DaDataService $daDataService;

    public function __construct(DaDataService $daDataService)
    {
        $this->daDataService = $daDataService;
    }

    public function verifyOrganization(Organization $organization): array
    {
        $verificationResults = [
            'inn_verification' => null,
            'address_verification' => null,
            'overall_status' => 'pending',
            'verification_score' => 0,
            'errors' => [],
            'warnings' => [],
        ];

        if ($organization->tax_number) {
            $innResult = $this->verifyInn($organization);
            $verificationResults['inn_verification'] = $innResult;
            
            if ($innResult['success']) {
                $verificationResults['verification_score'] += 70;
                
                $this->updateOrganizationWithDaDataInfo($organization, $innResult['data']);
            } else {
                $verificationResults['errors'][] = $innResult['message'];
            }
        } else {
            $verificationResults['warnings'][] = 'ИНН не указан для проверки';
        }

        if ($organization->address) {
            $addressResult = $this->verifyAddress($organization);
            $verificationResults['address_verification'] = $addressResult;
            
            if ($addressResult['success']) {
                $verificationResults['verification_score'] += 30;
            } else {
                $verificationResults['warnings'][] = $addressResult['message'];
            }
        } else {
            $verificationResults['warnings'][] = 'Адрес не указан для проверки';
        }

        $verificationResults['overall_status'] = $this->determineOverallStatus($verificationResults['verification_score']);

        $this->updateOrganizationVerificationStatus($organization, $verificationResults);

        return $verificationResults;
    }

    private function verifyInn(Organization $organization): array
    {
        return $this->daDataService->verifyOrganizationByInn($organization->tax_number);
    }

    private function verifyAddress(Organization $organization): array
    {
        return $this->daDataService->cleanAddress($organization->address);
    }

    private function updateOrganizationWithDaDataInfo(Organization $organization, array $daDataInfo): void
    {
        $updates = [];

        if (!$organization->legal_name && !empty($daDataInfo['legal_name'])) {
            $updates['legal_name'] = $daDataInfo['legal_name'];
        }

        if (!$organization->registration_number && !empty($daDataInfo['ogrn'])) {
            $updates['registration_number'] = $daDataInfo['ogrn'];
        }

        if (!empty($updates)) {
            $organization->update($updates);
            Log::info('Organization updated with DaData info', [
                'organization_id' => $organization->id,
                'updates' => $updates
            ]);
        }
    }

    private function determineOverallStatus(int $score): string
    {
        if ($score >= 90) {
            return 'verified';
        } elseif ($score >= 70) {
            return 'partially_verified';
        } elseif ($score >= 50) {
            return 'needs_review';
        } else {
            return 'failed';
        }
    }

    private function updateOrganizationVerificationStatus(Organization $organization, array $verificationResults): void
    {
        $isVerified = $verificationResults['overall_status'] === 'verified';
        
        $organization->update([
            'is_verified' => $isVerified,
            'verified_at' => $isVerified ? now() : null,
            'verification_status' => $verificationResults['overall_status'],
            'verification_data' => json_encode([
                'score' => $verificationResults['verification_score'],
                'inn_verification' => $verificationResults['inn_verification'],
                'address_verification' => $verificationResults['address_verification'],
                'errors' => $verificationResults['errors'],
                'warnings' => $verificationResults['warnings'],
                'verified_at' => now()->toISOString(),
            ]),
            'verification_notes' => $this->generateVerificationNotes($verificationResults),
        ]);

        Log::info('Organization verification status updated', [
            'organization_id' => $organization->id,
            'status' => $verificationResults['overall_status'],
            'score' => $verificationResults['verification_score'],
            'is_verified' => $isVerified,
        ]);
    }

    private function generateVerificationNotes(array $verificationResults): string
    {
        $notes = [];
        
        $notes[] = "Результат верификации: {$verificationResults['verification_score']}/100 баллов";
        
        if (!empty($verificationResults['errors'])) {
            $notes[] = "Ошибки: " . implode('; ', $verificationResults['errors']);
        }
        
        if (!empty($verificationResults['warnings'])) {
            $notes[] = "Предупреждения: " . implode('; ', $verificationResults['warnings']);
        }
        
        return implode('. ', $notes);
    }

    public function getVerificationStatusText(string $status): string
    {
        return match($status) {
            'verified' => 'Верифицирована',
            'partially_verified' => 'Частично верифицирована', 
            'needs_review' => 'Требует проверки',
            'failed' => 'Верификация не пройдена',
            'pending' => 'Ожидает верификации',
            default => 'Неизвестный статус'
        };
    }

    public function canAutoVerify(Organization $organization): bool
    {
        return !empty($organization->tax_number) && !empty($organization->address);
    }

    public function requestVerification(Organization $organization): array
    {
        if (!$this->canAutoVerify($organization)) {
            return [
                'success' => false,
                'message' => 'Для автоматической верификации необходимо указать ИНН и адрес организации',
                'data' => null
            ];
        }

        try {
            $results = $this->verifyOrganization($organization);
            
            return [
                'success' => true,
                'message' => 'Верификация завершена',
                'data' => $results
            ];
        } catch (\Exception $e) {
            Log::error('Organization verification error', [
                'organization_id' => $organization->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'message' => 'Ошибка при верификации организации',
                'data' => null
            ];
        }
    }
} 