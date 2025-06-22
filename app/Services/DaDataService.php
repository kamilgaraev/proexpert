<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DaDataService
{
    private string $apiKey;
    private string $secretKey;
    private string $baseUrl;
    private string $cleanUrl;

    public function __construct()
    {
        $this->apiKey = config('services.dadata.api_key');
        $this->secretKey = config('services.dadata.secret_key');
        $this->baseUrl = config('services.dadata.base_url');
        $this->cleanUrl = config('services.dadata.clean_url');
    }

    public function verifyOrganizationByInn(string $inn): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Token ' . $this->apiKey,
                'X-Secret' => $this->secretKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl . 'findById/party', [
                'query' => $inn
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                if (empty($data['suggestions'])) {
                    return [
                        'success' => false,
                        'message' => 'Организация с указанным ИНН не найдена',
                        'data' => null
                    ];
                }

                $organization = $data['suggestions'][0];
                
                return [
                    'success' => true,
                    'message' => 'Организация найдена',
                    'data' => [
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
                    ]
                ];
            }

            return [
                'success' => false,
                'message' => 'Ошибка при запросе к DaData API',
                'data' => null
            ];

        } catch (\Exception $e) {
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
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Token ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl . 'suggest/party', [
                'query' => $query,
                'count' => 10
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                return [
                    'success' => true,
                    'message' => 'Результаты поиска получены',
                    'data' => $data['suggestions'] ?? []
                ];
            }

            return [
                'success' => false,
                'message' => 'Ошибка при поиске организаций',
                'data' => []
            ];

        } catch (\Exception $e) {
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