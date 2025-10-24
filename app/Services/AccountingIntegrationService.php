<?php

namespace App\Services;

use App\Models\User;
use App\Models\Project;
use App\Models\Material;
use App\Models\CostCategory;
use App\Models\AdvanceAccountTransaction;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Exception;
use Carbon\Carbon;

class AccountingIntegrationService
{
    protected $apiEndpoint;
    protected $apiToken;

    /**
     * Конструктор сервиса.
     * 
     * @param string $apiEndpoint
     * @param string $apiToken
     */
    public function __construct($apiEndpoint = null, $apiToken = null)
    {
        $this->apiEndpoint = $apiEndpoint ?? config('accounting.api_endpoint');
        $this->apiToken = $apiToken ?? config('accounting.api_token');
    }

    /**
     * Импортировать пользователей из бухгалтерской системы.
     * 
     * @param int $organizationId
     * @return array
     */
    public function importUsers($organizationId)
    {
        try {
            // Получаем данные сотрудников из бухгалтерской системы
            $response = $this->makeApiRequest('GET', 'employees', [
                'organization_id' => $organizationId
            ]);

            $stats = [
                'total' => count($response['employees'] ?? []),
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => 0,
            ];

            // Обрабатываем полученные данные
            foreach ($response['employees'] ?? [] as $employeeData) {
                try {
                    // Ищем пользователя по внешнему коду или табельному номеру
                    $user = User::where('external_code', $employeeData['external_code'])
                        ->orWhere('employee_id', $employeeData['employee_id'])
                        ->first();

                    if ($user) {
                        // Обновляем существующего пользователя
                        $user->external_code = $employeeData['external_code'];
                        $user->employee_id = $employeeData['employee_id'];
                        $user->accounting_account = $employeeData['accounting_account'] ?? null;
                        $user->accounting_data = $employeeData['additional_data'] ?? null;
                        $user->save();

                        $stats['updated']++;
                    } else {
                        // Пользователя нет в системе, пропускаем
                        $stats['skipped']++;
                    }
                } catch (Exception $e) {
                    Log::error('Error processing employee data: ' . $e->getMessage(), [
                        'employee' => $employeeData,
                        'exception' => $e
                    ]);
                    $stats['errors']++;
                }
            }

            return [
                'success' => true,
                'message' => 'Импорт пользователей завершен',
                'stats' => $stats
            ];
        } catch (Exception $e) {
            Log::error('Failed to import users from accounting system: ' . $e->getMessage(), [
                'exception' => $e
            ]);

            return [
                'success' => false,
                'message' => 'Ошибка при импорте пользователей: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Импортировать проекты из бухгалтерской системы.
     * 
     * @param int $organizationId
     * @return array
     */
    public function importProjects($organizationId)
    {
        try {
            // Получаем данные проектов из бухгалтерской системы
            $response = $this->makeApiRequest('GET', 'projects', [
                'organization_id' => $organizationId
            ]);

            $stats = [
                'total' => count($response['projects'] ?? []),
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => 0,
            ];

            // Импортируем категории затрат (если есть)
            if (!empty($response['cost_categories'])) {
                $this->importCostCategories($organizationId, $response['cost_categories']);
            }

            // Обрабатываем полученные данные проектов
            foreach ($response['projects'] ?? [] as $projectData) {
                try {
                    // Ищем проект по внешнему коду
                    $project = Project::where('external_code', $projectData['external_code'])
                        ->where('organization_id', $organizationId)
                        ->first();

                    if ($project) {
                        // Обновляем существующий проект
                        $project->name = $projectData['name'];
                        $project->cost_category_id = $this->findOrCreateCostCategory(
                            $organizationId, 
                            $projectData['cost_category_code'] ?? null
                        );
                        $project->accounting_data = $projectData['additional_data'] ?? null;
                        $project->use_in_accounting_reports = true;
                        $project->save();

                        $stats['updated']++;
                    } else {
                        // Создаем новый проект
                        $project = new Project([
                            'organization_id' => $organizationId,
                            'name' => $projectData['name'],
                            'external_code' => $projectData['external_code'],
                            'cost_category_id' => $this->findOrCreateCostCategory(
                                $organizationId, 
                                $projectData['cost_category_code'] ?? null
                            ),
                            'accounting_data' => $projectData['additional_data'] ?? null,
                            'status' => 'active',
                            'use_in_accounting_reports' => true,
                        ]);
                        $project->save();

                        $stats['created']++;
                    }
                } catch (Exception $e) {
                    Log::error('Error processing project data: ' . $e->getMessage(), [
                        'project' => $projectData,
                        'exception' => $e
                    ]);
                    $stats['errors']++;
                }
            }

            return [
                'success' => true,
                'message' => 'Импорт проектов завершен',
                'stats' => $stats
            ];
        } catch (Exception $e) {
            Log::error('Failed to import projects from accounting system: ' . $e->getMessage(), [
                'exception' => $e
            ]);

            return [
                'success' => false,
                'message' => 'Ошибка при импорте проектов: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Импортировать материалы из бухгалтерской системы.
     * 
     * @param int $organizationId
     * @return array
     */
    public function importMaterials($organizationId)
    {
        try {
            // Получаем данные материалов из бухгалтерской системы
            $response = $this->makeApiRequest('GET', 'materials', [
                'organization_id' => $organizationId
            ]);

            $stats = [
                'total' => count($response['materials'] ?? []),
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => 0,
            ];

            // Обрабатываем полученные данные материалов
            foreach ($response['materials'] ?? [] as $materialData) {
                try {
                    // Ищем материал по внешнему коду или коду СБИС
                    $material = Material::where(function($query) use ($materialData) {
                            $query->where('external_code', $materialData['external_code']);
                            if (!empty($materialData['sbis_nomenclature_code'])) {
                                $query->orWhere('sbis_nomenclature_code', $materialData['sbis_nomenclature_code']);
                            }
                        })
                        ->where('organization_id', $organizationId)
                        ->first();

                    if ($material) {
                        // Обновляем существующий материал
                        $material->name = $materialData['name'];
                        $material->external_code = $materialData['external_code'];
                        $material->sbis_nomenclature_code = $materialData['sbis_nomenclature_code'] ?? null;
                        $material->sbis_unit_code = $materialData['sbis_unit_code'] ?? null;
                        $material->accounting_account = $materialData['accounting_account'] ?? null;
                        $material->accounting_data = $materialData['additional_data'] ?? null;
                        $material->use_in_accounting_reports = true;
                        $material->save();

                        $stats['updated']++;
                    } else {
                        // Материала нет в системе, пропускаем
                        $stats['skipped']++;
                    }
                } catch (Exception $e) {
                    Log::error('Error processing material data: ' . $e->getMessage(), [
                        'material' => $materialData,
                        'exception' => $e
                    ]);
                    $stats['errors']++;
                }
            }

            return [
                'success' => true,
                'message' => 'Импорт материалов завершен',
                'stats' => $stats
            ];
        } catch (Exception $e) {
            Log::error('Failed to import materials from accounting system: ' . $e->getMessage(), [
                'exception' => $e
            ]);

            return [
                'success' => false,
                'message' => 'Ошибка при импорте материалов: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Экспортировать транзакции подотчетных средств в бухгалтерскую систему.
     * 
     * @param int $organizationId
     * @param string $startDate
     * @param string $endDate
     * @return array
     */
    public function exportTransactions($organizationId, $startDate = null, $endDate = null)
    {
        try {
            // Определяем период выгрузки
            $startDate = $startDate ? Carbon::parse($startDate)->startOfDay() : Carbon::now()->subDays(30)->startOfDay();
            $endDate = $endDate ? Carbon::parse($endDate)->endOfDay() : Carbon::now()->endOfDay();

            // Получаем транзакции для экспорта
            $transactions = AdvanceAccountTransaction::byOrganization($organizationId)
                ->whereBetween('created_at', [$startDate->toDateTimeString(), $endDate->toDateTimeString()])
                ->whereNull('external_code') // Только транзакции без внешнего кода (еще не экспортированные)
                ->where('reporting_status', AdvanceAccountTransaction::STATUS_APPROVED) // Только утвержденные
                ->with(['user', 'project', 'createdBy', 'approvedBy'])
                ->get();

            $stats = [
                'total' => $transactions->count(),
                'exported' => 0,
                'errors' => 0,
            ];

            // Форматируем данные для экспорта
            $exportData = [
                'organization_id' => $organizationId,
                'transactions' => []
            ];

            foreach ($transactions as $transaction) {
                try {
                    $exportData['transactions'][] = [
                        'id' => $transaction->id,
                        'user_id' => $transaction->user_id,
                        'user_external_code' => $transaction->user->external_code,
                        'project_id' => $transaction->project_id,
                        'project_external_code' => $transaction->project->external_code ?? null,
                        'type' => $transaction->type,
                        'amount' => (float) $transaction->amount,
                        'description' => $transaction->description,
                        'document_number' => $transaction->document_number,
                        'document_date' => $transaction->document_date ? $transaction->document_date->format('Y-m-d') : null,
                        'reporting_status' => $transaction->reporting_status,
                        'reported_at' => $transaction->reported_at ? $transaction->reported_at->format('Y-m-d H:i:s') : null,
                        'approved_at' => $transaction->approved_at ? $transaction->approved_at->format('Y-m-d H:i:s') : null,
                    ];
                } catch (Exception $e) {
                    Log::error('Error formatting transaction for export: ' . $e->getMessage(), [
                        'transaction_id' => $transaction->id,
                        'exception' => $e
                    ]);
                    $stats['errors']++;
                }
            }

            // Если нет транзакций для экспорта
            if (empty($exportData['transactions'])) {
                return [
                    'success' => true,
                    'message' => 'Нет новых транзакций для экспорта',
                    'stats' => $stats
                ];
            }

            // Отправляем данные в бухгалтерскую систему
            $response = $this->makeApiRequest('POST', 'export/transactions', $exportData);

            // Обновляем экспортированные транзакции
            if (!empty($response['exported_transactions'])) {
                foreach ($response['exported_transactions'] as $exportedTransaction) {
                    try {
                        $transaction = AdvanceAccountTransaction::find($exportedTransaction['id']);
                        if ($transaction) {
                            $transaction->external_code = $exportedTransaction['external_code'];
                            $transaction->save();
                            $stats['exported']++;
                        }
                    } catch (Exception $e) {
                        Log::error('Error updating exported transaction: ' . $e->getMessage(), [
                            'transaction_id' => $exportedTransaction['id'] ?? null,
                            'exception' => $e
                        ]);
                    }
                }
            }

            return [
                'success' => true,
                'message' => 'Экспорт транзакций завершен',
                'stats' => $stats
            ];
        } catch (Exception $e) {
            Log::error('Failed to export transactions to accounting system: ' . $e->getMessage(), [
                'exception' => $e
            ]);

            return [
                'success' => false,
                'message' => 'Ошибка при экспорте транзакций: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Импортировать категории затрат из бухгалтерской системы.
     * 
     * @param int $organizationId
     * @param array $categories
     * @return array
     */
    protected function importCostCategories($organizationId, $categories)
    {
        $stats = [
            'total' => count($categories),
            'created' => 0,
            'updated' => 0
        ];

        foreach ($categories as $categoryData) {
            try {
                $category = CostCategory::where('external_code', $categoryData['external_code'])
                    ->where('organization_id', $organizationId)
                    ->first();

                if ($category) {
                    // Обновляем существующую категорию
                    $category->name = $categoryData['name'];
                    $category->code = $categoryData['code'] ?? null;
                    $category->description = $categoryData['description'] ?? null;
                    $category->parent_id = $this->findParentCategoryId($organizationId, $categoryData['parent_code'] ?? null);
                    $category->additional_attributes = $categoryData['additional_data'] ?? null;
                    $category->save();

                    $stats['updated']++;
                } else {
                    // Создаем новую категорию
                    $category = new CostCategory([
                        'organization_id' => $organizationId,
                        'name' => $categoryData['name'],
                        'code' => $categoryData['code'] ?? null,
                        'external_code' => $categoryData['external_code'],
                        'description' => $categoryData['description'] ?? null,
                        'parent_id' => $this->findParentCategoryId($organizationId, $categoryData['parent_code'] ?? null),
                        'is_active' => true,
                        'additional_attributes' => $categoryData['additional_data'] ?? null,
                    ]);
                    $category->save();

                    $stats['created']++;
                }
            } catch (Exception $e) {
                Log::error('Error processing cost category: ' . $e->getMessage(), [
                    'category' => $categoryData,
                    'exception' => $e
                ]);
            }
        }

        return $stats;
    }

    /**
     * Найти или создать категорию затрат по коду.
     * 
     * @param int $organizationId
     * @param string|null $categoryCode
     * @return int|null
     */
    protected function findOrCreateCostCategory($organizationId, $categoryCode)
    {
        if (empty($categoryCode)) {
            return null;
        }

        $category = CostCategory::where('code', $categoryCode)
            ->where('organization_id', $organizationId)
            ->first();

        if ($category) {
            return $category->id;
        }

        // Создаем базовую категорию, если не найдена
        $category = new CostCategory([
            'organization_id' => $organizationId,
            'name' => 'Категория ' . $categoryCode,
            'code' => $categoryCode,
            'is_active' => true,
        ]);
        $category->save();

        return $category->id;
    }

    /**
     * Найти ID родительской категории по коду.
     * 
     * @param int $organizationId
     * @param string|null $parentCode
     * @return int|null
     */
    protected function findParentCategoryId($organizationId, $parentCode)
    {
        if (empty($parentCode)) {
            return null;
        }

        $parentCategory = CostCategory::where('code', $parentCode)
            ->where('organization_id', $organizationId)
            ->first();

        return $parentCategory ? $parentCategory->id : null;
    }

    /**
     * Выполнить запрос к API бухгалтерской системы.
     * 
     * @param string $method
     * @param string $endpoint
     * @param array $data
     * @return array
     * @throws Exception
     */
    protected function makeApiRequest($method, $endpoint, $data = [])
    {
        try {
            $url = $this->apiEndpoint . '/' . $endpoint;
            
            $response = Http::withToken($this->apiToken)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->{strtolower($method)}($url, $data);
            
            if ($response->successful()) {
                return $response->json();
            }
            
            throw new Exception('API request failed: ' . $response->status() . ' ' . $response->body());
        } catch (Exception $e) {
            Log::error('API request error: ' . $e->getMessage(), [
                'method' => $method,
                'endpoint' => $endpoint,
                'data' => $data,
                'exception' => $e
            ]);
            throw $e;
        }
    }
} 