<?php

namespace App\Services;

use App\Services\Logging\LoggingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DaDataService
{
    private string $apiKey;
    private string $secretKey;
    private string $baseUrl;
    private string $cleanUrl;
    private LoggingService $logging;

    public function __construct(LoggingService $logging)
    {
        $this->logging = $logging;
        $this->apiKey = config('services.dadata.api_key');
        $this->secretKey = config('services.dadata.secret_key');
        $this->baseUrl = config('services.dadata.base_url');
        $this->cleanUrl = config('services.dadata.clean_url');
        
        // Проверяем конфигурацию при инициализации
        if (empty($this->apiKey) || empty($this->secretKey)) {
            $this->logging->technical('dadata.configuration.missing', [
                'has_api_key' => !empty($this->apiKey),
                'has_secret_key' => !empty($this->secretKey),
                'has_base_url' => !empty($this->baseUrl),
                'has_clean_url' => !empty($this->cleanUrl)
            ], 'warning');
        }
    }

    public function verifyOrganizationByInn(string $inn): array
    {
        $startTime = microtime(true);
        
        $this->logging->business('dadata.organization.verification.started', [
            'inn' => $inn,
            'inn_length' => strlen($inn)
        ]);

        try {
            // SECURITY: Логируем попытку проверки ИНН
            $this->logging->security('dadata.inn.verification.attempt', [
                'inn' => $inn,
                'inn_length' => strlen($inn),
                'is_valid_format' => preg_match('/^\d{10,12}$/', $inn)
            ]);
            
            $httpStart = microtime(true);
            $response = Http::withHeaders([
                'Authorization' => 'Token ' . $this->apiKey,
                'X-Secret' => $this->secretKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl . 'findById/party', [
                'query' => $inn
            ]);
            $httpDuration = (microtime(true) - $httpStart) * 1000;

            $this->logging->technical('dadata.api.request.completed', [
                'endpoint' => 'findById/party',
                'inn' => $inn,
                'http_status' => $response->status(),
                'response_duration_ms' => $httpDuration,
                'response_size' => strlen($response->body())
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $duration = (microtime(true) - $startTime) * 1000;
                
                if (empty($data['suggestions'])) {
                    $this->logging->business('dadata.organization.not_found', [
                        'inn' => $inn,
                        'duration_ms' => $duration,
                        'suggestions_count' => 0
                    ], 'warning');
                    
                    return [
                        'success' => false,
                        'message' => 'Организация с указанным ИНН не найдена',
                        'data' => null
                    ];
                }

                $organization = $data['suggestions'][0];
                $organizationData = [
                    'inn' => $organization['data']['inn'] ?? null,
                    'ogrn' => $organization['data']['ogrn'] ?? null,
                    'name' => $organization['data']['name']['full_with_opf'] ?? null,
                    'short_name' => $organization['data']['name']['short_with_opf'] ?? null,
                    'legal_name' => $organization['data']['name']['full'] ?? null,
                    'status' => $organization['data']['state']['status'] ?? null,
                    'address' => $organization['data']['address']['unrestricted_value'] ?? null,
                    'management' => $organization['data']['management']['name'] ?? null,
                    'activity_kind' => $organization['data']['okved'] ?? null,
                    'registration_date' => $organization['data']['state']['registration_date'] ?? null,
                    'liquidation_date' => $organization['data']['state']['liquidation_date'] ?? null,
                ];
                
                $this->logging->business('dadata.organization.found', [
                    'inn' => $inn,
                    'organization_name' => $organizationData['name'],
                    'organization_status' => $organizationData['status'],
                    'has_liquidation_date' => !empty($organizationData['liquidation_date']),
                    'suggestions_count' => count($data['suggestions']),
                    'duration_ms' => $duration
                ]);
                
                // SECURITY: Логируем успешную верификацию
                $this->logging->security('dadata.organization.verified', [
                    'inn' => $inn,
                    'organization_name' => $organizationData['name'],
                    'organization_status' => $organizationData['status'],
                    'is_active' => $organizationData['status'] === 'ACTIVE'
                ]);
                
                return [
                    'success' => true,
                    'message' => 'Организация найдена',
                    'data' => $organizationData
                ];
            }

            $duration = (microtime(true) - $startTime) * 1000;
            
            $this->logging->technical('dadata.api.request.failed', [
                'endpoint' => 'findById/party',
                'inn' => $inn,
                'http_status' => $response->status(),
                'response_body' => $response->body(),
                'duration_ms' => $duration
            ], 'error');

            return [
                'success' => false,
                'message' => 'Ошибка при запросе к DaData API',
                'data' => null
            ];

        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            
            $this->logging->technical('dadata.api.exception', [
                'endpoint' => 'findById/party',
                'inn' => $inn,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'duration_ms' => $duration
            ], 'error');
            
            Log::error('DaData API Error: ' . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Ошибка при проверке организации',
                'data' => null
            ];
        }
    }

    public function cleanAddress(string $address): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Token ' . $this->apiKey,
                'X-Secret' => $this->secretKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->cleanUrl . 'address', [
                [$address]
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                if (empty($data)) {
                    return [
                        'success' => false,
                        'message' => 'Не удалось обработать адрес',
                        'data' => null
                    ];
                }

                $addressData = $data[0];
                
                return [
                    'success' => true,
                    'message' => 'Адрес обработан успешно',
                    'data' => [
                        'source' => $addressData['source'] ?? null,
                        'result' => $addressData['result'] ?? null,
                        'postal_code' => $addressData['postal_code'] ?? null,
                        'country' => $addressData['country'] ?? null,
                        'region' => $addressData['region'] ?? null,
                        'city' => $addressData['city'] ?? null,
                        'street' => $addressData['street'] ?? null,
                        'house' => $addressData['house'] ?? null,
                        'qc' => $addressData['qc'] ?? null,
                        'qc_complete' => $addressData['qc_complete'] ?? null,
                        'unparsed_parts' => $addressData['unparsed_parts'] ?? null,
                    ]
                ];
            }

            return [
                'success' => false,
                'message' => 'Ошибка при обработке адреса',
                'data' => null
            ];

        } catch (\Exception $e) {
            Log::error('DaData Address Clean Error: ' . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Ошибка при обработке адреса',
                'data' => null
            ];
        }
    }

    public function suggestOrganization(string $query): array
    {
        $startTime = microtime(true);
        
        $this->logging->business('dadata.organization.suggest.started', [
            'query' => $query,
            'query_length' => strlen($query)
        ]);

        try {
            $httpStart = microtime(true);
            $response = Http::withHeaders([
                'Authorization' => 'Token ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl . 'suggest/party', [
                'query' => $query,
                'count' => 10
            ]);
            $httpDuration = (microtime(true) - $httpStart) * 1000;

            $this->logging->technical('dadata.api.request.completed', [
                'endpoint' => 'suggest/party',
                'query' => $query,
                'http_status' => $response->status(),
                'response_duration_ms' => $httpDuration,
                'response_size' => strlen($response->body())
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $suggestions = $data['suggestions'] ?? [];
                $duration = (microtime(true) - $startTime) * 1000;
                
                $this->logging->business('dadata.organization.suggest.completed', [
                    'query' => $query,
                    'suggestions_count' => count($suggestions),
                    'duration_ms' => $duration,
                    'has_results' => count($suggestions) > 0
                ]);
                
                if ($duration > 3000) {
                    $this->logging->technical('dadata.api.suggest.slow', [
                        'endpoint' => 'suggest/party',
                        'query' => $query,
                        'duration_ms' => $duration,
                        'suggestions_count' => count($suggestions)
                    ], 'warning');
                }
                
                return [
                    'success' => true,
                    'message' => 'Результаты поиска получены',
                    'data' => $suggestions
                ];
            }

            $duration = (microtime(true) - $startTime) * 1000;
            
            $this->logging->technical('dadata.api.request.failed', [
                'endpoint' => 'suggest/party',
                'query' => $query,
                'http_status' => $response->status(),
                'response_body' => $response->body(),
                'duration_ms' => $duration
            ], 'error');

            return [
                'success' => false,
                'message' => 'Ошибка при поиске организаций',
                'data' => []
            ];

        } catch (\Exception $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            
            $this->logging->technical('dadata.api.exception', [
                'endpoint' => 'suggest/party',
                'query' => $query,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'duration_ms' => $duration
            ], 'error');
            
            Log::error('DaData Suggest Error: ' . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Ошибка при поиске организаций',
                'data' => []
            ];
        }
    }

    public function suggestAddress(string $query): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Token ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl . 'suggest/address', [
                'query' => $query,
                'count' => 10
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                return [
                    'success' => true,
                    'message' => 'Результаты поиска адресов получены',
                    'data' => $data['suggestions'] ?? []
                ];
            }

            return [
                'success' => false,
                'message' => 'Ошибка при поиске адресов',
                'data' => []
            ];

        } catch (\Exception $e) {
            Log::error('DaData Address Suggest Error: ' . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Ошибка при поиске адресов',
                'data' => []
            ];
        }
    }
} 