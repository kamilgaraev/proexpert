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

    /**
     * Получить детальную информацию о том, что нужно исправить для улучшения верификации
     */
    public function getVerificationRecommendations(Organization $organization): array
    {
        $recommendations = [];
        $missingFields = [];
        $issues = [];
        
        // Проверяем обязательные поля
        if (empty($organization->tax_number)) {
            $missingFields[] = [
                'field' => 'tax_number',
                'name' => 'ИНН',
                'description' => 'Идентификационный номер налогоплательщика',
                'weight' => 70,
                'required' => true
            ];
        } else {
            // Проверяем корректность ИНН
            if (!$this->isValidInn($organization->tax_number)) {
                $issues[] = [
                    'field' => 'tax_number',
                    'name' => 'ИНН',
                    'description' => 'ИНН должен содержать только цифры (10 или 12 знаков)',
                    'current_value' => $organization->tax_number,
                    'weight' => 70
                ];
            }
        }

        if (empty($organization->address)) {
            $missingFields[] = [
                'field' => 'address',
                'name' => 'Адрес',
                'description' => 'Юридический адрес организации',
                'weight' => 30,
                'required' => true
            ];
        }

        // Проверяем дополнительные поля для улучшения верификации
        if (empty($organization->legal_name)) {
            $missingFields[] = [
                'field' => 'legal_name',
                'name' => 'Полное наименование',
                'description' => 'Полное юридическое наименование организации',
                'weight' => 10,
                'required' => false
            ];
        }

        if (empty($organization->registration_number)) {
            $missingFields[] = [
                'field' => 'registration_number',
                'name' => 'ОГРН',
                'description' => 'Основной государственный регистрационный номер',
                'weight' => 10,
                'required' => false
            ];
        } else {
            // Проверяем корректность ОГРН
            if (!$this->isValidOgrn($organization->registration_number)) {
                $issues[] = [
                    'field' => 'registration_number',
                    'name' => 'ОГРН',
                    'description' => 'ОГРН должен содержать только цифры (13 или 15 знаков)',
                    'current_value' => $organization->registration_number,
                    'weight' => 10
                ];
            }
        }

        if (empty($organization->phone)) {
            $missingFields[] = [
                'field' => 'phone',
                'name' => 'Телефон',
                'description' => 'Контактный телефон организации',
                'weight' => 5,
                'required' => false
            ];
        }

        if (empty($organization->email)) {
            $missingFields[] = [
                'field' => 'email',
                'name' => 'Email',
                'description' => 'Контактный email организации',
                'weight' => 5,
                'required' => false
            ];
        }

        if (empty($organization->city)) {
            $missingFields[] = [
                'field' => 'city',
                'name' => 'Город',
                'description' => 'Город регистрации организации',
                'weight' => 5,
                'required' => false
            ];
        }

        if (empty($organization->postal_code)) {
            $missingFields[] = [
                'field' => 'postal_code',
                'name' => 'Почтовый индекс',
                'description' => 'Почтовый индекс (6 цифр)',
                'weight' => 5,
                'required' => false
            ];
        } else {
            // Проверяем корректность почтового индекса
            if (!preg_match('/^\d{6}$/', $organization->postal_code)) {
                $issues[] = [
                    'field' => 'postal_code',
                    'name' => 'Почтовый индекс',
                    'description' => 'Почтовый индекс должен содержать ровно 6 цифр',
                    'current_value' => $organization->postal_code,
                    'weight' => 5
                ];
            }
        }

        // Анализируем данные верификации если они есть
        $verificationIssues = [];
        if ($organization->verification_data && is_array($organization->verification_data)) {
            if (!empty($organization->verification_data['errors'])) {
                foreach ($organization->verification_data['errors'] as $error) {
                    $verificationIssues[] = [
                        'type' => 'error',
                        'message' => $error,
                        'severity' => 'high'
                    ];
                }
            }
            
            if (!empty($organization->verification_data['warnings'])) {
                foreach ($organization->verification_data['warnings'] as $warning) {
                    $verificationIssues[] = [
                        'type' => 'warning',
                        'message' => $warning,
                        'severity' => 'medium'
                    ];
                }
            }
        }

        return [
            'current_score' => $organization->verification_score,
            'max_score' => 100,
            'status' => $organization->verification_status,
            'status_text' => $organization->verification_status_text,
            'missing_fields' => $missingFields,
            'field_issues' => $issues,
            'verification_issues' => $verificationIssues,
            'can_auto_verify' => $organization->canBeVerified(),
            'potential_score_increase' => array_sum(array_column($missingFields, 'weight')) + array_sum(array_column($issues, 'weight')),
        ];
    }

    private function isValidInn(string $inn): bool
    {
        return preg_match('/^\d{10}$|^\d{12}$/', $inn);
    }

    private function isValidOgrn(string $ogrn): bool
    {
        return preg_match('/^\d{13}$|^\d{15}$/', $ogrn);
    }
} 