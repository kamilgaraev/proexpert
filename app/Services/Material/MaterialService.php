<?php

namespace App\Services\Material;

use App\Repositories\Interfaces\MaterialRepositoryInterface;
use App\Repositories\Interfaces\MeasurementUnitRepositoryInterface;
use App\Repositories\Interfaces\Log\MaterialUsageLogRepositoryInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Collection;
use App\Exceptions\BusinessLogicException;
use Illuminate\Http\Request;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\Api\V1\Admin\MeasurementUnitResource;
use App\Models\Material;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Illuminate\Support\Facades\DB;

class MaterialService
{
    protected MaterialRepositoryInterface $materialRepository;
    protected MeasurementUnitRepositoryInterface $measurementUnitRepository;
    protected MaterialUsageLogRepositoryInterface $materialUsageLogRepository;

    public function __construct(
        MaterialRepositoryInterface $materialRepository,
        MeasurementUnitRepositoryInterface $measurementUnitRepository,
        MaterialUsageLogRepositoryInterface $materialUsageLogRepository
    ) {
        $this->materialRepository = $materialRepository;
        $this->measurementUnitRepository = $measurementUnitRepository;
        $this->materialUsageLogRepository = $materialUsageLogRepository;
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
        $data['organization_id'] = $organizationId;

        // Проверяем measurement_unit_id, если репозиторий доступен
        if (isset($data['measurement_unit_id'])) {
            if (!$this->measurementUnitRepository->find($data['measurement_unit_id'])) {
                throw new BusinessLogicException('Указанная единица измерения не найдена', 400);
            }
        }
        // TODO: Возможно, добавить проверку доступности ед.изм для организации

        return $this->materialRepository->create($data);
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
        // Получаем все логи прихода и расхода для данного проекта
        // Предполагаем, что в MaterialUsageLogRepository есть метод для получения всех логов по фильтрам
        // или мы используем getPaginatedLogs с очень большим perPage, что не идеально, но для начала.
        // Идеально: $allLogs = $this->materialUsageLogRepository->getAllLogsForProject($projectId, $organizationId);
        
        // Временное решение: используем пагинатор с большим числом записей
        // ВАЖНО: это неэффективно для большого количества логов. Нужен специальный метод в репозитории.
        $logsPaginator = $this->materialUsageLogRepository->getPaginatedLogs(
            $organizationId, 
            100000, // Очень большое число для получения всех записей
            ['project_id' => $projectId],
            'usage_date', 
            'asc'
        );
        $allLogs = collect($logsPaginator->items());

        $balances = [];

        foreach ($allLogs as $log) {
            if (!isset($balances[$log->material_id])) {
                $balances[$log->material_id] = [
                    'material_id' => $log->material_id,
                    'material_name' => $log->material?->name, // Предполагается, что связь material загружена или доступна
                    'measurement_unit_id' => $log->material?->measurementUnit?->id,
                    'measurement_unit_symbol' => $log->material?->measurementUnit?->short_name,
                    'current_balance' => 0,
                ];
            }

            if ($log->operation_type === 'receipt') {
                $balances[$log->material_id]['current_balance'] += $log->quantity;
            } elseif ($log->operation_type === 'write_off') {
                $balances[$log->material_id]['current_balance'] -= $log->quantity;
            }
        }

        // Оставляем только материалы с положительным балансом или все, в зависимости от требований
        // return collect(array_values($balances))->filter(fn($item) => $item['current_balance'] > 0);
        return collect(array_values($balances));
    }

    // ЗАГЛУШКИ ДЛЯ НЕДОСТАЮЩИХ МЕТОДОВ
    public function getMaterialBalancesByMaterial(int $materialId, int $perPage = 15, ?int $projectId = null, string $sortBy = 'created_at', string $sortDirection = 'desc'): array
    {
        Log::warning('Method MaterialService@getMaterialBalancesByMaterial called but not implemented.', ['material_id' => $materialId]);
        return [
            'data' => [],
            'links' => [],
            'meta' => [],
            'message' => 'Functionality to get material balances is not yet implemented.'
        ];
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
                Log::debug('[MaterialImport] Обработка строки', ['line' => $line, 'data' => $data]);
                // Валидация
                if (empty($data['name'])) {
                    $errors[] = "Строка $line: не указано имя материала";
                    Log::debug('[MaterialImport] Пропуск: не указано имя', ['line' => $line]);
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
                    Log::debug('[MaterialImport] Пропуск: не найдена единица измерения', ['line' => $line, 'unit' => $data['measurement_unit'] ?? null]);
                    continue;
                }
                // Поиск существующего материала
                $material = null;
                if (!empty($data['external_code'])) {
                    $material = $this->materialRepository->findByExternalCode($data['external_code'], $orgId);
                    Log::debug('[MaterialImport] Поиск по external_code', ['line' => $line, 'external_code' => $data['external_code'], 'found' => (bool)$material]);
                }
                if (!$material && !empty($data['code'])) {
                    $material = $this->materialRepository->findByNameAndOrganization($data['code'], $orgId);
                    Log::debug('[MaterialImport] Поиск по code', ['line' => $line, 'code' => $data['code'], 'found' => (bool)$material]);
                }
                if (!$material && !empty($data['name'])) {
                    $material = $this->materialRepository->findByNameAndOrganization($data['name'], $orgId);
                    Log::debug('[MaterialImport] Поиск по name', ['line' => $line, 'name' => $data['name'], 'found' => (bool)$material]);
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
                        Log::debug('[MaterialImport] Dry-run, материал не создаётся/не обновляется', ['line' => $line, 'data' => $materialData]);
                        continue;
                    }
                    if ($material) {
                        $material->update($materialData);
                        $updated++;
                        Log::debug('[MaterialImport] Материал обновлён', ['line' => $line, 'id' => $material->id, 'data' => $materialData]);
                    } else {
                        $created = $this->materialRepository->create($materialData);
                        $imported++;
                        Log::debug('[MaterialImport] Материал создан', ['line' => $line, 'id' => $created->id ?? null, 'data' => $materialData]);
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
            Log::error('Критическая ошибка при импорте материалов', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'Критическая ошибка: ' . $e->getMessage(),
                'imported' => $imported,
                'updated' => $updated,
                'errors' => $errors
            ];
        }
        return [
            'success' => empty($errors),
            'message' => empty($errors) ? 'Импорт завершён успешно' : 'Импорт завершён с ошибками',
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