<?php

namespace App\Services\Material;

use App\Repositories\Interfaces\MaterialRepositoryInterface;
use App\Repositories\Interfaces\MeasurementUnitRepositoryInterface;
use App\Repositories\Interfaces\Log\MaterialUsageLogRepositoryInterface;
use App\Services\Logging\LoggingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Collection;
use App\Exceptions\BusinessLogicException;
use Illuminate\Http\Request;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\Api\V1\Admin\MeasurementUnitResource;
use App\Models\Material;
use App\Models\MaterialBalance;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Illuminate\Support\Facades\DB;

class MaterialService
{
    protected MaterialRepositoryInterface $materialRepository;
    protected MeasurementUnitRepositoryInterface $measurementUnitRepository;
    protected MaterialUsageLogRepositoryInterface $materialUsageLogRepository;
    protected LoggingService $logging;

    public function __construct(
        MaterialRepositoryInterface $materialRepository,
        MeasurementUnitRepositoryInterface $measurementUnitRepository,
        MaterialUsageLogRepositoryInterface $materialUsageLogRepository,
        LoggingService $logging
    ) {
        $this->materialRepository = $materialRepository;
        $this->measurementUnitRepository = $measurementUnitRepository;
        $this->materialUsageLogRepository = $materialUsageLogRepository;
        $this->logging = $logging;
    }

    /**
     * Helper для получения ID организации из запроса.
     */
    protected function getCurrentOrgId(Request $request): int
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user(); 
        $organizationId = $request->attributes->get('current_organization_id');
        if (!$organizationId && $user) {
            $organizationId = $user->current_organization_id;
        }
        if (!$organizationId) {
            Log::error('Failed to determine organization context in MaterialService', ['user_id' => $user?->id, 'request_attributes' => $request->attributes->all()]);
            throw new BusinessLogicException('Контекст организации не определен.', 500);
        }
        return (int)$organizationId;
    }

    public function getAllActive(Request $request): Collection
    {
        $user = $request->user();
        if (!$user || !$user->current_organization_id) {
            // Если getCurrentOrgId не может получить ID из $request (например, $request->user() пуст)
            // то он выбросит BusinessLogicException. Это более последовательно.
            // throw new BusinessLogicException('Не удалось определить организацию пользователя.');
        }
        $organizationId = $this->getCurrentOrgId($request);
        return $this->materialRepository->getActiveMaterials($organizationId);
    }

    public function createMaterial(array $data, Request $request): \App\Models\Material
    {
        $organizationId = $this->getCurrentOrgId($request);
        $user = $request->user();
        $data['organization_id'] = $organizationId;

        // BUSINESS: Начало создания материала - важная метрика склада
        $this->logging->business('material.creation.started', [
            'material_name' => $data['name'] ?? null,
            'material_category' => $data['category'] ?? null,
            'unit_id' => $data['measurement_unit_id'] ?? null,
            'organization_id' => $organizationId,
            'created_by' => $user?->id,
            'created_by_email' => $user?->email
        ]);

        // Проверяем measurement_unit_id, если репозиторий доступен
        if (isset($data['measurement_unit_id'])) {
            if (!$this->measurementUnitRepository->find($data['measurement_unit_id'])) {
                // TECHNICAL: Ошибка валидации единицы измерения
                $this->logging->technical('material.creation.validation.failed', [
                    'material_name' => $data['name'] ?? null,
                    'invalid_unit_id' => $data['measurement_unit_id'],
                    'organization_id' => $organizationId,
                    'attempted_by' => $user?->id,
                    'error' => 'Measurement unit not found'
                ], 'error');
                throw new BusinessLogicException('Указанная единица измерения не найдена', 400);
            }
        }

        $material = $this->materialRepository->create($data);

        // AUDIT: Создание материала - важно для отслеживания склада
        $this->logging->audit('material.created', [
            'material_id' => $material->id,
            'material_name' => $material->name,
            'material_code' => $material->code ?? null,
            'material_category' => $material->category,
            'unit_id' => $material->measurement_unit_id,
            'organization_id' => $organizationId,
            'created_by' => $user?->id,
            'created_by_email' => $user?->email,
            'creation_date' => $material->created_at?->toISOString()
        ]);

        // BUSINESS: Успешное создание материала - метрика роста каталога
        $this->logging->business('material.created', [
            'material_id' => $material->id,
            'material_name' => $material->name,
            'organization_id' => $organizationId,
            'created_by' => $user?->id
        ]);

        return $material;
    }

    public function findMaterialById(int $id, Request $request): ?\App\Models\Material
    {
        $organizationId = $this->getCurrentOrgId($request);
        $material = $this->materialRepository->find($id);
        if (!$material || $material->organization_id !== $organizationId) {
            return null;
        }
        return $material;
    }

    public function updateMaterial(int $id, array $data, Request $request): bool
    {
        // Проверка принадлежности к организации через findMaterialById
        $material = $this->findMaterialById($id, $request);
        if (!$material) {
             throw new BusinessLogicException('Материал не найден или не принадлежит вашей организации.', 404);
        }
        
        // Проверяем measurement_unit_id, если он передан и репозиторий доступен
        if (isset($data['measurement_unit_id'])) {
            if (!$this->measurementUnitRepository->find($data['measurement_unit_id'])) {
                throw new BusinessLogicException('Указанная единица измерения не найдена', 400);
            }
        }

        // Убедимся, что organization_id не меняется
        unset($data['organization_id']);
        
        return $this->materialRepository->update($id, $data);
    }

    public function deleteMaterial(int $id, Request $request): bool
    {
        // Проверка принадлежности к организации через findMaterialById
         $material = $this->findMaterialById($id, $request);
         if (!$material) {
             throw new BusinessLogicException('Материал не найден или не принадлежит вашей организации.', 404);
         }
        // TODO: Проверка, используется ли материал где-либо
        return $this->materialRepository->delete($id);
    }

    /**
     * Получить пагинированный список материалов для текущей организации.
     */
    public function getMaterialsPaginated(Request $request, int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $organizationId = $this->getCurrentOrgId($request);
        
        $filters = [
            'name' => $request->query('name'),
            'category' => $request->query('category'),
            'is_active' => $request->query('is_active'), // Принимаем 'true', 'false', '1', '0' или null
        ];
        if (isset($filters['is_active'])) {
            $filters['is_active'] = filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        } else {
             unset($filters['is_active']); 
        }
        $filters = array_filter($filters, fn($value) => !is_null($value) && $value !== '');

        $sortBy = $request->query('sort_by', 'name');
        $sortDirection = $request->query('sort_direction', 'asc');

        // TODO: Валидация sortBy и sortDirection
        $allowedSortBy = ['name', 'category', 'created_at', 'updated_at'];
        if (!in_array(strtolower($sortBy), $allowedSortBy)) {
            $sortBy = 'name';
        }
        if (!in_array(strtolower($sortDirection), ['asc', 'desc'])) {
            $sortDirection = 'asc';
        }

        return $this->materialRepository->getMaterialsForOrganizationPaginated(
            $organizationId,
            $perPage,
            $filters,
            $sortBy,
            $sortDirection
        );
    }

    /**
     * Получить балансы материалов для указанного проекта.
     *
     * @param int $organizationId
     * @param int $projectId
     * @return \Illuminate\Support\Collection
     */
    public function getMaterialBalancesForProject(int $organizationId, int $projectId): \Illuminate\Support\Collection
    {
        // Переключено на warehouse_balances - показываем агрегированные данные со всех складов организации
        $balances = DB::table('warehouse_balances')
            ->join('organization_warehouses', 'warehouse_balances.warehouse_id', '=', 'organization_warehouses.id')
            ->join('materials', 'warehouse_balances.material_id', '=', 'materials.id')
            ->leftJoin('measurement_units', 'materials.measurement_unit_id', '=', 'measurement_units.id')
            ->where('warehouse_balances.organization_id', $organizationId)
            ->where('organization_warehouses.is_active', true)
            ->select(
                'materials.id as material_id',
                'materials.name as material_name',
                'materials.measurement_unit_id',
                'measurement_units.short_name as measurement_unit_symbol',
                DB::raw('SUM(warehouse_balances.available_quantity) as current_balance')
            )
            ->groupBy('materials.id', 'materials.name', 'materials.measurement_unit_id', 'measurement_units.short_name')
            ->get();

        if ($balances->isNotEmpty()) {
            return $balances->map(function ($balance) {
                return [
                    'material_id' => $balance->material_id,
                    'material_name' => $balance->material_name,
                    'measurement_unit_id' => $balance->measurement_unit_id,
                    'measurement_unit_symbol' => $balance->measurement_unit_symbol,
                    'current_balance' => (float) $balance->current_balance,
                ];
            });
        }

        // Fallback: если MaterialBalance пустая, пересчитываем из логов (медленнее)
        // Это нужно для старых данных до внедрения observers
        $logsPaginator = $this->materialUsageLogRepository->getPaginatedLogs(
            $organizationId, 
            100000, 
            ['project_id' => $projectId],
            'usage_date', 
            'asc'
        );
        $allLogs = collect($logsPaginator->items());

        $calculatedBalances = [];

        foreach ($allLogs as $log) {
            if (!isset($calculatedBalances[$log->material_id])) {
                $calculatedBalances[$log->material_id] = [
                    'material_id' => $log->material_id,
                    'material_name' => $log->material?->name,
                    'measurement_unit_id' => $log->material?->measurementUnit?->id,
                    'measurement_unit_symbol' => $log->material?->measurementUnit?->short_name,
                    'current_balance' => 0,
                ];
            }

            if ($log->operation_type === 'receipt') {
                $calculatedBalances[$log->material_id]['current_balance'] += $log->quantity;
            } elseif ($log->operation_type === 'write_off') {
                $calculatedBalances[$log->material_id]['current_balance'] -= $log->quantity;
            }
        }

        return collect(array_values($calculatedBalances));
    }

    public function getMaterialBalancesByMaterial(int $materialId, int $perPage = 15, ?int $projectId = null, string $sortBy = 'created_at', string $sortDirection = 'desc'): array
    {
        try {
            $material = $this->materialRepository->find($materialId);
            if (!$material) {
                throw new BusinessLogicException('Материал не найден.', 404);
            }

            // Переключено на warehouse_balances
            $query = DB::table('warehouse_balances as wb')
                ->join('organization_warehouses as w', 'wb.warehouse_id', '=', 'w.id')
                ->leftJoin('materials as m', 'wb.material_id', '=', 'm.id')
                ->leftJoin('measurement_units as mu', 'm.measurement_unit_id', '=', 'mu.id')
                ->where('wb.material_id', $materialId)
                ->where('w.is_active', true)
                ->select([
                    'wb.id',
                    'wb.warehouse_id',
                    'w.name as warehouse_name',
                    'w.warehouse_type',
                    'wb.available_quantity',
                    'wb.reserved_quantity',
                    'wb.average_price',
                    'wb.last_movement_at as last_update_date',
                    'mu.short_name as unit',
                    DB::raw('wb.available_quantity as free_quantity')
                ]);

            // Удалена фильтрация по project_id, так как теперь используем склады

            $allowedSortBy = ['warehouse_name', 'available_quantity', 'reserved_quantity', 'free_quantity', 'average_price', 'last_update_date'];
            if (!in_array($sortBy, $allowedSortBy)) {
                $sortBy = 'last_update_date';
            }

            if (!in_array(strtolower($sortDirection), ['asc', 'desc'])) {
                $sortDirection = 'desc';
            }

            $query->orderBy($sortBy, $sortDirection);

            $paginatedResults = $query->paginate($perPage);

            return [
                'data' => collect($paginatedResults->items())->map(function ($item) {
                    return $item;
                })->toArray(),
                'links' => [
                    'first' => $paginatedResults->url(1),
                    'last' => $paginatedResults->url($paginatedResults->lastPage()),
                    'prev' => $paginatedResults->previousPageUrl(),
                    'next' => $paginatedResults->nextPageUrl()
                ],
                'meta' => [
                    'current_page' => $paginatedResults->currentPage(),
                    'last_page' => $paginatedResults->lastPage(),
                    'per_page' => $paginatedResults->perPage(),
                    'total' => $paginatedResults->total(),
                    'from' => $paginatedResults->firstItem(),
                    'to' => $paginatedResults->lastItem()
                ],
                'material_info' => [
                    'id' => $material->id,
                    'name' => $material->name,
                    'code' => $material->code,
                    'unit' => $material->measurementUnit?->short_name
                ]
            ];
        } catch (BusinessLogicException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Error getting material balances', [
                'material_id' => $materialId,
                'error' => $e->getMessage()
            ]);
            throw new BusinessLogicException('Ошибка при получении остатков материала.', 500);
        }
    }

    public function getMeasurementUnits(Request $request): \Illuminate\Http\Resources\Json\AnonymousResourceCollection | array
    {
        try {
            $organizationId = $this->getCurrentOrgId($request);
            $units = $this->measurementUnitRepository->getByOrganization($organizationId);
            
            // Предполагается, что у вас есть или будет ресурс MeasurementUnitResource
            // Если его нет, можно просто вернуть $units->toArray() или $units
            if (class_exists(MeasurementUnitResource::class)) {
                return MeasurementUnitResource::collection($units);
            }
            Log::info('MeasurementUnitResource not found, returning raw collection/array for measurement units.');
            return $units->toArray(); // или return $units; если хотите вернуть коллекцию Eloquent
        } catch (BusinessLogicException $e) { // Сначала ловим BusinessLogicException, если getCurrentOrgId ее бросит
            Log::warning('BusinessLogicException in MaterialService@getMeasurementUnits: ' . $e->getMessage());
            return ['message' => $e->getMessage(), 'success' => false]; 
        } catch (\Throwable $e) {
            Log::error('Error in MaterialService@getMeasurementUnits: ' . $e->getMessage());
            return ['message' => 'Не удалось получить список единиц измерения.', 'success' => false];
        }
    }

    public function importMaterialsFromFile(\Illuminate\Http\UploadedFile $file, string $format = 'simple', array $options = []): array
    {
        $dryRun = $options['dry_run'] ?? false;
        $orgId = $options['organization_id'] ?? null;
        $imported = 0;
        $updated = 0;
        $errors = [];
        $rows = [];
        $ext = strtolower($file->getClientOriginalExtension());
        
        // BUSINESS: Начало импорта материалов - критическая функция управления складом
        $this->logging->business('material.import.started', [
            'filename' => $file->getClientOriginalName(),
            'file_size_bytes' => $file->getSize(),
            'file_extension' => $ext,
            'format' => $format,
            'organization_id' => $orgId,
            'is_dry_run' => $dryRun,
            'user_id' => null
        ]);
        try {
            if (in_array($ext, ['xlsx', 'xls'])) {
                $spreadsheet = IOFactory::load($file->getPathname());
                $sheet = $spreadsheet->getActiveSheet();
                $rows = $sheet->toArray(null, true, true, true);
            } elseif ($ext === 'csv') {
                $rows = array_map('str_getcsv', file($file->getPathname()));
            } else {
                throw new BusinessLogicException('Неподдерживаемый формат файла', 400);
            }
        } catch (\Throwable $e) {
            Log::error('Ошибка чтения файла импорта материалов', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'Ошибка чтения файла: ' . $e->getMessage(),
                'imported' => 0,
                'updated' => 0,
                'errors' => [$e->getMessage()]
            ];
        }
        if (empty($rows) || count($rows) < 2) {
            return [
                'success' => false,
                'message' => 'Файл пуст или не содержит данных',
                'imported' => 0,
                'updated' => 0,
                'errors' => ['Файл пуст или не содержит данных']
            ];
        }
        // Корректно определяем первую строку (заголовки) для xlsx/csv
        $firstRowKey = array_key_first($rows);
        $rawHeaders = array_values($rows[$firstRowKey]);
        if (count(array_filter($rawHeaders, fn($h) => !is_string($h) || trim($h) === '')) > 0) {
            return [
                'success' => false,
                'message' => 'Некорректный формат заголовков файла',
                'imported' => 0,
                'updated' => 0,
                'errors' => ['Некорректный формат заголовков файла (проверьте первую строку)']
            ];
        }
        $headers = array_map(fn($h) => trim(mb_strtolower($h)), $rawHeaders);
        $required = ['name', 'measurement_unit'];
        $missing = array_diff($required, $headers);
        if (!empty($missing)) {
            return [
                'success' => false,
                'message' => 'В файле отсутствуют обязательные колонки',
                'imported' => 0,
                'updated' => 0,
                'errors' => ['Отсутствуют обязательные колонки: ' . implode(', ', $missing)]
            ];
        }
        unset($rows[$firstRowKey]);
        // orgId должен быть передан явно через options (контроллер обязан это делать)
        if (!$orgId) {
            return [
                'success' => false,
                'message' => 'Не удалось определить организацию',
                'imported' => 0,
                'updated' => 0,
                'errors' => ['Не удалось определить организацию']
            ];
        }
        $unitCache = [];
        DB::beginTransaction();
        try {
            foreach ($rows as $i => $row) {
                $row = is_array($row) ? array_values($row) : $row;
                $data = array_combine($headers, array_map('trim', $row));
                $line = $i + 2;
                // Валидация
                if (empty($data['name'])) {
                    $errors[] = "Строка $line: не указано имя материала";
                    continue;
                }
                // Единица измерения
                $unitId = null;
                if (!empty($data['measurement_unit_id'])) {
                    $unitId = (int)$data['measurement_unit_id'];
                } elseif (!empty($data['measurement_unit'])) {
                    $unitKey = mb_strtolower(trim($data['measurement_unit']));
                    if (!isset($unitCache[$unitKey])) {
                        $unit = $this->measurementUnitRepository->getByOrganization($orgId)
                            ->first(fn($u) => mb_strtolower($u->name) === $unitKey || mb_strtolower($u->short_name) === $unitKey);
                        if ($unit) {
                            $unitCache[$unitKey] = $unit->id;
                        }
                    }
                    $unitId = $unitCache[$unitKey] ?? null;
                }
                if (!$unitId) {
                    $errors[] = "Строка $line: не найдена единица измерения";
                    continue;
                }
                // Поиск существующего материала
                $material = null;
                if (!empty($data['external_code'])) {
                    $material = $this->materialRepository->findByExternalCode($data['external_code'], $orgId);
                }
                if (!$material && !empty($data['code'])) {
                    $material = $this->materialRepository->findByNameAndOrganization($data['code'], $orgId);
                }
                if (!$material && !empty($data['name'])) {
                    $material = $this->materialRepository->findByNameAndOrganization($data['name'], $orgId);
                }
                // Подготовка данных
                $materialData = [
                    'organization_id' => $orgId,
                    'name' => $data['name'],
                    'code' => $data['code'] ?? null,
                    'measurement_unit_id' => $unitId,
                    'description' => $data['description'] ?? null,
                    'category' => $data['category'] ?? null,
                    'default_price' => isset($data['default_price']) ? (float)str_replace(',', '.', $data['default_price']) : null,
                    'external_code' => $data['external_code'] ?? null,
                    'sbis_nomenclature_code' => $data['sbis_nomenclature_code'] ?? null,
                    'sbis_unit_code' => $data['sbis_unit_code'] ?? null,
                    'accounting_account' => $data['accounting_account'] ?? null,
                    'is_active' => isset($data['is_active']) ? filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : true,
                ];
                try {
                    if ($dryRun) {
                        continue;
                    }
                    if ($material) {
                        $material->update($materialData);
                        $updated++;
                    } else {
                        $created = $this->materialRepository->create($materialData);
                        $imported++;
                    }
                } catch (\Throwable $e) {
                    $errors[] = "Строка $line: " . $e->getMessage();
                    Log::error('[MaterialImport] Ошибка при создании/обновлении', ['line' => $line, 'data' => $materialData, 'error' => $e->getMessage()]);
                }
            }
            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            
            // TECHNICAL: Критическая ошибка при импорте материалов
            $this->logging->technical('material.import.critical_error', [
                'filename' => $file->getClientOriginalName(),
                'file_size_bytes' => $file->getSize(),
                'organization_id' => $orgId,
                'imported_count' => $imported,
                'updated_count' => $updated,
                'errors_count' => count($errors),
                'exception_class' => get_class($e),
                'exception_message' => $e->getMessage(),
                'user_id' => null
            ], 'critical');
            
            Log::error('Критическая ошибка при импорте материалов', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'Критическая ошибка: ' . $e->getMessage(),
                'imported' => $imported,
                'updated' => $updated,
                'errors' => $errors
            ];
        }
        
        $isSuccess = empty($errors);
        $totalProcessed = $imported + $updated;
        
        // BUSINESS: Завершение импорта материалов - ключевая метрика
        $this->logging->business('material.import.completed', [
            'filename' => $file->getClientOriginalName(),
            'organization_id' => $orgId,
            'total_processed' => $totalProcessed,
            'imported_count' => $imported,
            'updated_count' => $updated,
            'errors_count' => count($errors),
            'success_rate' => $totalProcessed > 0 ? round((($totalProcessed - count($errors)) / $totalProcessed) * 100, 2) : 0,
            'is_success' => $isSuccess,
            'is_dry_run' => $dryRun,
            'user_id' => null
        ], $isSuccess ? 'info' : 'warning');
        
        // AUDIT: Массовое изменение каталога материалов - важно для compliance
        if (!$dryRun && ($imported > 0 || $updated > 0)) {
            $this->logging->audit('material.bulk.import', [
                'filename' => $file->getClientOriginalName(),
                'organization_id' => $orgId,
                'materials_imported' => $imported,
                'materials_updated' => $updated,
                'performed_by' => null,
                'import_date' => now()->toISOString()
            ]);
        }
        
        return [
            'success' => $isSuccess,
            'message' => $isSuccess ? 'Импорт завершён успешно' : 'Импорт завершён с ошибками',
            'imported' => $imported,
            'updated' => $updated,
            'errors' => $errors
        ];
    }

    public function generateImportTemplate(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $headers = [
            'name', 'code', 'measurement_unit', 'description', 'category', 'default_price',
            'external_code', 'sbis_nomenclature_code', 'sbis_unit_code', 'accounting_account', 'is_active'
        ];
        $examples = [
            'Цемент М500', 'CEM500', 'кг', 'Основной строительный материал', 'Строительные материалы', '4500.50',
            'EXT-001', '123456', '796', '10.01', 'true'
        ];
        foreach ($headers as $i => $header) {
            $col = chr(65 + $i); // A, B, C ...
            $sheet->setCellValue($col . '1', $header);
            $sheet->setCellValue($col . '2', $examples[$i]);
        }
        $sheet->getStyle('A1:K1')->getFont()->setBold(true);
        $sheet->getStyle('A1:K2')->getAlignment()->setWrapText(true);
        foreach (range('A', 'K') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getRowDimension(1)->setRowHeight(28);
        $sheet->getRowDimension(2)->setRowHeight(22);
        return $spreadsheet;
    }
} 