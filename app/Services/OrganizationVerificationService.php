<?php

namespace App\Services;

use App\Models\Organization;
use App\Services\DaDataService;
use App\Services\Logging\LoggingService;
use Illuminate\Support\Facades\Log;

class OrganizationVerificationService
{
    private DaDataService $daDataService;
    private LoggingService $logging;

    public function __construct(DaDataService $daDataService, LoggingService $logging)
    {
        $this->daDataService = $daDataService;
        $this->logging = $logging;
    }

    public function verifyOrganization(Organization $organization): array
    {
        $startTime = microtime(true);
        
        $this->logging->business('organization.verification.started', [
            'organization_id' => $organization->id,
            'organization_name' => $organization->name,
            'has_tax_number' => !empty($organization->tax_number),
            'has_address' => !empty($organization->address),
            'current_verification_status' => $organization->verification_status
        ]);

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
                
                $this->logging->business('organization.verification.inn.success', [
                    'organization_id' => $organization->id,
                    'tax_number' => $organization->tax_number,
                    'found_organization' => $innResult['data']['name'] ?? 'unknown',
                    'organization_status' => $innResult['data']['status'] ?? 'unknown'
                ]);
                
                $this->updateOrganizationWithDaDataInfo($organization, $innResult['data']);
            } else {
                $this->logging->business('organization.verification.inn.failed', [
                    'organization_id' => $organization->id,
                    'tax_number' => $organization->tax_number,
                    'error_message' => $innResult['message']
                ], 'warning');
                
                $verificationResults['errors'][] = $innResult['message'];
            }
        } else {
            $verificationResults['warnings'][] = 'ИНН не указан для проверки';
            
            $this->logging->business('organization.verification.inn.missing', [
                'organization_id' => $organization->id
            ], 'warning');
        }

        if ($organization->address) {
            $addressResult = $this->verifyAddress($organization);
            $verificationResults['address_verification'] = $addressResult;
            
            if ($addressResult['success']) {
                $verificationResults['verification_score'] += 30;
                
                $this->logging->business('organization.verification.address.success', [
                    'organization_id' => $organization->id,
                    'address' => $organization->address
                ]);
            } else {
                $this->logging->business('organization.verification.address.failed', [
                    'organization_id' => $organization->id,
                    'address' => $organization->address,
                    'error_message' => $addressResult['message']
                ], 'warning');
                
                $verificationResults['warnings'][] = $addressResult['message'];
            }
        } else {
            $verificationResults['warnings'][] = 'Адрес не указан для проверки';
            
            $this->logging->business('organization.verification.address.missing', [
                'organization_id' => $organization->id
            ], 'warning');
        }

        $verificationResults['overall_status'] = $this->determineOverallStatus($verificationResults['verification_score']);

        $this->updateOrganizationVerificationStatus($organization, $verificationResults);

        $duration = (microtime(true) - $startTime) * 1000;
        
        $this->logging->business('organization.verification.completed', [
            'organization_id' => $organization->id,
            'verification_score' => $verificationResults['verification_score'],
            'overall_status' => $verificationResults['overall_status'],
            'errors_count' => count($verificationResults['errors']),
            'warnings_count' => count($verificationResults['warnings']),
            'duration_ms' => $duration
        ]);
        
        $this->logging->audit('organization.verification.completed', [
            'organization_id' => $organization->id,
            'organization_name' => $organization->name,
            'verification_score' => $verificationResults['verification_score'],
            'overall_status' => $verificationResults['overall_status'],
            'performed_by' => auth()->id() ?? 'system'
        ]);
        
        // SECURITY: Логируем изменение статуса верификации
        $this->logging->security('organization.verification.status_changed', [
            'organization_id' => $organization->id,
            'new_status' => $verificationResults['overall_status'],
            'verification_score' => $verificationResults['verification_score'],
            'is_fully_verified' => $verificationResults['overall_status'] === 'verified'
        ]);

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
            
            $this->logging->business('organization.updated_from_dadata', [
                'organization_id' => $organization->id,
                'organization_name' => $organization->name,
                'updates' => $updates,
                'dadata_source' => 'inn_verification'
            ]);
            
            $this->logging->audit('organization.data.updated', [
                'organization_id' => $organization->id,
                'updated_fields' => array_keys($updates),
                'data_source' => 'dadata',
                'performed_by' => auth()->id() ?? 'system'
            ]);
            
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
     * Рассчитать базовый рейтинг организации на основе заполненных полей
     */
    public function calculateBasicScore(Organization $organization): int
    {
        $score = 0;
        
        // ИНН - 70 баллов
        if (!empty($organization->tax_number) && $this->isValidInn($organization->tax_number)) {
            $score += 70;
        }
        
        // Адрес - 30 баллов  
        if (!empty($organization->address)) {
            $score += 30;
        }
        
        // Полное наименование - 15 баллов
        if (!empty($organization->legal_name)) {
            $score += 15;
        }
        
        // ОГРН - 15 баллов
        if (!empty($organization->registration_number) && $this->isValidOgrn($organization->registration_number)) {
            $score += 15;
        }
        
        // Город - 10 баллов
        if (!empty($organization->city)) {
            $score += 10;
        }
        
        // Почтовый индекс - 10 баллов
        if (!empty($organization->postal_code) && preg_match('/^\d{6}$/', $organization->postal_code)) {
            $score += 10;
        }
        
        return min($score, 100); // Максимум 100 баллов
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
                'weight' => 15,
                'required' => false
            ];
        }

        if (empty($organization->registration_number)) {
            $missingFields[] = [
                'field' => 'registration_number',
                'name' => 'ОГРН',
                'description' => 'Основной государственный регистрационный номер',
                'weight' => 15,
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
                    'weight' => 15
                ];
            }
        }



        if (empty($organization->city)) {
            $missingFields[] = [
                'field' => 'city',
                'name' => 'Город',
                'description' => 'Город регистрации организации',
                'weight' => 10,
                'required' => false
            ];
        }

        if (empty($organization->postal_code)) {
            $missingFields[] = [
                'field' => 'postal_code',
                'name' => 'Почтовый индекс',
                'description' => 'Почтовый индекс (6 цифр)',
                'weight' => 10,
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
                    'weight' => 10
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
        } else if ($organization->canBeVerified()) {
            // Если все основные поля заполнены, но верификация не проводилась
            $verificationIssues[] = [
                'type' => 'info',
                'message' => 'Запустите автоматическую верификацию для проверки данных через государственные реестры',
                'severity' => 'medium'
            ];
        }

        // Используем базовый рейтинг если верификация еще не проводилась
        $currentScore = $organization->verification_score > 0 
            ? $organization->verification_score 
            : $this->calculateBasicScore($organization);
            
        // Определяем статус на основе текущего рейтинга
        $currentStatus = $organization->verification_status ?: $this->determineOverallStatus($currentScore);
        $statusText = $this->getVerificationStatusText($currentStatus);

        return [
            'current_score' => $currentScore,
            'max_score' => 100,
            'status' => $currentStatus,
            'status_text' => $statusText,
            'missing_fields' => $missingFields,
            'field_issues' => $issues,
            'verification_issues' => $verificationIssues,
            'can_auto_verify' => $organization->canBeVerified(),
            'potential_score_increase' => array_sum(array_column($missingFields, 'weight')) + array_sum(array_column($issues, 'weight')),
            'needs_verification' => empty($organization->verification_data) && $organization->canBeVerified(),
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

    /**
     * Получить пользовательское сообщение о состоянии верификации
     */
    public function getUserFriendlyMessage(Organization $organization): array
    {
        $recommendations = $this->getVerificationRecommendations($organization);
        $score = $recommendations['current_score'];
        $missingFields = $recommendations['missing_fields'];
        $issues = $recommendations['field_issues'];
        $needsVerification = $recommendations['needs_verification'];

        if ($score === 100 && empty($issues) && !$needsVerification) {
            return [
                'type' => 'success',
                'title' => 'Организация полностью верифицирована',
                'message' => 'Все данные заполнены корректно и проверены через государственные реестры.',
                'action' => null
            ];
        }

        if ($score >= 100 && $needsVerification) {
            return [
                'type' => 'warning',
                'title' => 'Требуется верификация',
                'message' => 'Все основные данные заполнены. Запустите автоматическую верификацию для проверки через государственные реестры.',
                'action' => 'verify'
            ];
        }

        if (!empty($issues)) {
            $issuesList = array_map(fn($issue) => "• {$issue['name']}: {$issue['description']}", $issues);
            return [
                'type' => 'error',
                'title' => 'Обнаружены ошибки в данных',
                'message' => "Исправьте следующие поля:\n" . implode("\n", $issuesList),
                'action' => 'edit'
            ];
        }

        if (!empty($missingFields)) {
            $requiredFields = array_filter($missingFields, fn($field) => $field['required']);
            $optionalFields = array_filter($missingFields, fn($field) => !$field['required']);

            if (!empty($requiredFields)) {
                $fieldsList = array_map(fn($field) => "• {$field['name']}", $requiredFields);
                return [
                    'type' => 'warning',
                    'title' => 'Заполните обязательные поля',
                    'message' => "Для верификации необходимо заполнить:\n" . implode("\n", $fieldsList),
                    'action' => 'edit'
                ];
            }

            if (!empty($optionalFields)) {
                $fieldsList = array_map(fn($field) => "• {$field['name']} (+{$field['weight']} баллов)", $optionalFields);
                return [
                    'type' => 'info',
                    'title' => 'Можно улучшить рейтинг',
                    'message' => "Заполните дополнительные поля для повышения рейтинга:\n" . implode("\n", $fieldsList),
                    'action' => 'edit'
                ];
            }
        }

        return [
            'type' => 'info',
            'title' => 'Статус верификации',
            'message' => "Текущий рейтинг: {$score}/100 баллов",
            'action' => null
        ];
    }
} 