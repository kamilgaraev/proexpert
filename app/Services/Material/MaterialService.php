<?php

namespace App\Services\Material;

use App\Repositories\Interfaces\MaterialRepositoryInterface;
use App\Repositories\Interfaces\MeasurementUnitRepositoryInterface;
use App\Services\Logging\LoggingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Collection;
use App\Exceptions\BusinessLogicException;
use Illuminate\Http\Request;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\Api\V1\Admin\MeasurementUnitResource;
use App\Models\Material;
use App\Models\MeasurementUnit;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Illuminate\Support\Facades\DB;
use function trans_message;

class MaterialService
{
    protected MaterialRepositoryInterface $materialRepository;
    protected MeasurementUnitRepositoryInterface $measurementUnitRepository;
    protected LoggingService $logging;

    public function __construct(
        MaterialRepositoryInterface $materialRepository,
        MeasurementUnitRepositoryInterface $measurementUnitRepository,
        LoggingService $logging
    ) {
        $this->materialRepository = $materialRepository;
        $this->measurementUnitRepository = $measurementUnitRepository;
        $this->logging = $logging;
    }

    /**
     * Helper РґР»СЏ РїРѕР»СѓС‡РµРЅРёСЏ ID РѕСЂРіР°РЅРёР·Р°С†РёРё РёР· Р·Р°РїСЂРѕСЃР°.
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
            throw new BusinessLogicException('РљРѕРЅС‚РµРєСЃС‚ РѕСЂРіР°РЅРёР·Р°С†РёРё РЅРµ РѕРїСЂРµРґРµР»РµРЅ.', 500);
        }
        return (int)$organizationId;
    }

    public function getAllActive(Request $request): Collection
    {
        $user = $request->user();
        if (!$user || !$user->current_organization_id) {
            // Р•СЃР»Рё getCurrentOrgId РЅРµ РјРѕР¶РµС‚ РїРѕР»СѓС‡РёС‚СЊ ID РёР· $request (РЅР°РїСЂРёРјРµСЂ, $request->user() РїСѓСЃС‚)
            // С‚Рѕ РѕРЅ РІС‹Р±СЂРѕСЃРёС‚ BusinessLogicException. Р­С‚Рѕ Р±РѕР»РµРµ РїРѕСЃР»РµРґРѕРІР°С‚РµР»СЊРЅРѕ.
            // throw new BusinessLogicException('РќРµ СѓРґР°Р»РѕСЃСЊ РѕРїСЂРµРґРµР»РёС‚СЊ РѕСЂРіР°РЅРёР·Р°С†РёСЋ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ.');
        }
        $organizationId = $this->getCurrentOrgId($request);
        return $this->materialRepository->getActiveMaterials($organizationId);
    }

    public function createMaterial(array $data, Request $request): \App\Models\Material
    {
        $organizationId = $this->getCurrentOrgId($request);
        $user = $request->user();
        $data['organization_id'] = $organizationId;

        // BUSINESS: РќР°С‡Р°Р»Рѕ СЃРѕР·РґР°РЅРёСЏ РјР°С‚РµСЂРёР°Р»Р° - РІР°Р¶РЅР°СЏ РјРµС‚СЂРёРєР° СЃРєР»Р°РґР°
        $this->logging->business('material.creation.started', [
            'material_name' => $data['name'] ?? null,
            'material_category' => $data['category'] ?? null,
            'unit_id' => $data['measurement_unit_id'] ?? null,
            'organization_id' => $organizationId,
            'created_by' => $user?->id,
            'created_by_email' => $user?->email
        ]);

        // РџСЂРѕРІРµСЂСЏРµРј measurement_unit_id, РµСЃР»Рё СЂРµРїРѕР·РёС‚РѕСЂРёР№ РґРѕСЃС‚СѓРїРµРЅ
        if (isset($data['measurement_unit_id'])) {
            if (!$this->measurementUnitBelongsToOrganization((int) $data['measurement_unit_id'], $organizationId)) {
                // TECHNICAL: РћС€РёР±РєР° РІР°Р»РёРґР°С†РёРё РµРґРёРЅРёС†С‹ РёР·РјРµСЂРµРЅРёСЏ
                $this->logging->technical('material.creation.validation.failed', [
                    'material_name' => $data['name'] ?? null,
                    'invalid_unit_id' => $data['measurement_unit_id'],
                    'organization_id' => $organizationId,
                    'attempted_by' => $user?->id,
                    'error' => 'Measurement unit not found'
                ], 'error');
                throw new BusinessLogicException('РЈРєР°Р·Р°РЅРЅР°СЏ РµРґРёРЅРёС†Р° РёР·РјРµСЂРµРЅРёСЏ РЅРµ РЅР°Р№РґРµРЅР°', 400);
            }
        }

        $material = $this->materialRepository->create($data);

        // AUDIT: РЎРѕР·РґР°РЅРёРµ РјР°С‚РµСЂРёР°Р»Р° - РІР°Р¶РЅРѕ РґР»СЏ РѕС‚СЃР»РµР¶РёРІР°РЅРёСЏ СЃРєР»Р°РґР°
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

        // BUSINESS: РЈСЃРїРµС€РЅРѕРµ СЃРѕР·РґР°РЅРёРµ РјР°С‚РµСЂРёР°Р»Р° - РјРµС‚СЂРёРєР° СЂРѕСЃС‚Р° РєР°С‚Р°Р»РѕРіР°
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
        // РџСЂРѕРІРµСЂРєР° РїСЂРёРЅР°РґР»РµР¶РЅРѕСЃС‚Рё Рє РѕСЂРіР°РЅРёР·Р°С†РёРё С‡РµСЂРµР· findMaterialById
        $material = $this->findMaterialById($id, $request);
        if (!$material) {
             throw new BusinessLogicException('РњР°С‚РµСЂРёР°Р» РЅРµ РЅР°Р№РґРµРЅ РёР»Рё РЅРµ РїСЂРёРЅР°РґР»РµР¶РёС‚ РІР°С€РµР№ РѕСЂРіР°РЅРёР·Р°С†РёРё.', 404);
        }
        
        // РџСЂРѕРІРµСЂСЏРµРј measurement_unit_id, РµСЃР»Рё РѕРЅ РїРµСЂРµРґР°РЅ Рё СЂРµРїРѕР·РёС‚РѕСЂРёР№ РґРѕСЃС‚СѓРїРµРЅ
        if (isset($data['measurement_unit_id'])) {
            if (!$this->measurementUnitBelongsToOrganization((int) $data['measurement_unit_id'], $this->getCurrentOrgId($request))) {
                throw new BusinessLogicException('РЈРєР°Р·Р°РЅРЅР°СЏ РµРґРёРЅРёС†Р° РёР·РјРµСЂРµРЅРёСЏ РЅРµ РЅР°Р№РґРµРЅР°', 400);
            }
        }

        // РЈР±РµРґРёРјСЃСЏ, С‡С‚Рѕ organization_id РЅРµ РјРµРЅСЏРµС‚СЃСЏ
        unset($data['organization_id']);
        
        return $this->materialRepository->update($id, $data);
    }

    private function measurementUnitBelongsToOrganization(int $measurementUnitId, int $organizationId): bool
    {
        return MeasurementUnit::query()
            ->where('id', $measurementUnitId)
            ->whereNull('deleted_at')
            ->where(function ($query) use ($organizationId): void {
                $query->where('organization_id', $organizationId)
                    ->orWhere('is_system', true);
            })
            ->exists();
    }

    public function deleteMaterial(int $id, Request $request): bool
    {
        $material = $this->findMaterialById($id, $request);

        if (!$material) {
            throw new BusinessLogicException(trans_message('catalog.errors.material_not_found'), 404);
        }

        if ($this->hasMaterialUsage($material)) {
            throw new BusinessLogicException(trans_message('catalog.errors.material_in_use'), 422);
        }

        return $this->materialRepository->delete($id);
    }

    protected function hasMaterialUsage(Material $material): bool
    {
        return $material->workTypes()->exists()
            || $material->completedWorks()->exists();
    }

    /**
     * РџРѕР»СѓС‡РёС‚СЊ РїР°РіРёРЅРёСЂРѕРІР°РЅРЅС‹Р№ СЃРїРёСЃРѕРє РјР°С‚РµСЂРёР°Р»РѕРІ РґР»СЏ С‚РµРєСѓС‰РµР№ РѕСЂРіР°РЅРёР·Р°С†РёРё.
     */
    public function getMaterialsPaginated(Request $request, int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $organizationId = $this->getCurrentOrgId($request);
        $search = $request->query('search', $request->query('q'));
        
        $filters = [
            'search' => $search,
            'name' => $request->query('name'),
            'category' => $request->query('category'),
            'measurement_unit_id' => $request->query('measurement_unit_id'),
            'is_active' => $request->query('is_active'), // РџСЂРёРЅРёРјР°РµРј 'true', 'false', '1', '0' РёР»Рё null
        ];
        if (isset($filters['is_active'])) {
            $filters['is_active'] = filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        } else {
             unset($filters['is_active']); 
        }
        $filters = array_filter($filters, fn($value) => !is_null($value) && $value !== '');

        $sortBy = $request->query('sort_by', 'name');
        $sortDirection = $request->query('sort_direction', 'asc');

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
     * РџРѕР»СѓС‡РёС‚СЊ Р±Р°Р»Р°РЅСЃС‹ РјР°С‚РµСЂРёР°Р»РѕРІ РґР»СЏ СѓРєР°Р·Р°РЅРЅРѕРіРѕ РїСЂРѕРµРєС‚Р°.
     *
     * @param int $organizationId
     * @param int $projectId
     * @return \Illuminate\Support\Collection
     */
    public function getMaterialBalancesForProject(int $organizationId, int $projectId): \Illuminate\Support\Collection
    {
        // РџРµСЂРµРєР»СЋС‡РµРЅРѕ РЅР° warehouse_balances - РїРѕРєР°Р·С‹РІР°РµРј Р°РіСЂРµРіРёСЂРѕРІР°РЅРЅС‹Рµ РґР°РЅРЅС‹Рµ СЃРѕ РІСЃРµС… СЃРєР»Р°РґРѕРІ РѕСЂРіР°РЅРёР·Р°С†РёРё
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

        // Р’РѕР·РІСЂР°С‰Р°РµРј РґР°РЅРЅС‹Рµ РёР· warehouse_balances (СѓР¶Рµ РїРѕР»СѓС‡РµРЅС‹ РІС‹С€Рµ)
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

    public function getMaterialBalancesByMaterial(int $materialId, int $perPage = 15, ?int $projectId = null, string $sortBy = 'created_at', string $sortDirection = 'desc'): array
    {
        try {
            $material = $this->materialRepository->find($materialId);
            if (!$material) {
                throw new BusinessLogicException('РњР°С‚РµСЂРёР°Р» РЅРµ РЅР°Р№РґРµРЅ.', 404);
            }

            // РџРµСЂРµРєР»СЋС‡РµРЅРѕ РЅР° warehouse_balances
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
                    'wb.unit_price',
                    'wb.last_movement_at as last_update_date',
                    'mu.short_name as unit',
                    DB::raw('wb.available_quantity as free_quantity')
                ]);

            // РЈРґР°Р»РµРЅР° С„РёР»СЊС‚СЂР°С†РёСЏ РїРѕ project_id, С‚Р°Рє РєР°Рє С‚РµРїРµСЂСЊ РёСЃРїРѕР»СЊР·СѓРµРј СЃРєР»Р°РґС‹

            $allowedSortBy = ['warehouse_name', 'available_quantity', 'reserved_quantity', 'free_quantity', 'unit_price', 'last_update_date'];
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
            throw new BusinessLogicException('РћС€РёР±РєР° РїСЂРё РїРѕР»СѓС‡РµРЅРёРё РѕСЃС‚Р°С‚РєРѕРІ РјР°С‚РµСЂРёР°Р»Р°.', 500);
        }
    }

    public function getMeasurementUnits(Request $request): \Illuminate\Http\Resources\Json\AnonymousResourceCollection | array
    {
        try {
            $organizationId = $this->getCurrentOrgId($request);
            $units = $this->measurementUnitRepository->getByOrganization($organizationId);
            
            // РџСЂРµРґРїРѕР»Р°РіР°РµС‚СЃСЏ, С‡С‚Рѕ Сѓ РІР°СЃ РµСЃС‚СЊ РёР»Рё Р±СѓРґРµС‚ СЂРµСЃСѓСЂСЃ MeasurementUnitResource
            // Р•СЃР»Рё РµРіРѕ РЅРµС‚, РјРѕР¶РЅРѕ РїСЂРѕСЃС‚Рѕ РІРµСЂРЅСѓС‚СЊ $units->toArray() РёР»Рё $units
            if (class_exists(MeasurementUnitResource::class)) {
                return MeasurementUnitResource::collection($units);
            }
            Log::info('MeasurementUnitResource not found, returning raw collection/array for measurement units.');
            return $units->toArray(); // РёР»Рё return $units; РµСЃР»Рё С…РѕС‚РёС‚Рµ РІРµСЂРЅСѓС‚СЊ РєРѕР»Р»РµРєС†РёСЋ Eloquent
        } catch (BusinessLogicException $e) { // РЎРЅР°С‡Р°Р»Р° Р»РѕРІРёРј BusinessLogicException, РµСЃР»Рё getCurrentOrgId РµРµ Р±СЂРѕСЃРёС‚
            Log::warning('BusinessLogicException in MaterialService@getMeasurementUnits: ' . $e->getMessage());
            return ['message' => $e->getMessage(), 'success' => false]; 
        } catch (\Throwable $e) {
            Log::error('Error in MaterialService@getMeasurementUnits: ' . $e->getMessage());
            return ['message' => 'РќРµ СѓРґР°Р»РѕСЃСЊ РїРѕР»СѓС‡РёС‚СЊ СЃРїРёСЃРѕРє РµРґРёРЅРёС† РёР·РјРµСЂРµРЅРёСЏ.', 'success' => false];
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
        
        // BUSINESS: РќР°С‡Р°Р»Рѕ РёРјРїРѕСЂС‚Р° РјР°С‚РµСЂРёР°Р»РѕРІ - РєСЂРёС‚РёС‡РµСЃРєР°СЏ С„СѓРЅРєС†РёСЏ СѓРїСЂР°РІР»РµРЅРёСЏ СЃРєР»Р°РґРѕРј
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
                throw new BusinessLogicException('РќРµРїРѕРґРґРµСЂР¶РёРІР°РµРјС‹Р№ С„РѕСЂРјР°С‚ С„Р°Р№Р»Р°', 400);
            }
        } catch (\Throwable $e) {
            Log::error('РћС€РёР±РєР° С‡С‚РµРЅРёСЏ С„Р°Р№Р»Р° РёРјРїРѕСЂС‚Р° РјР°С‚РµСЂРёР°Р»РѕРІ', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'РћС€РёР±РєР° С‡С‚РµРЅРёСЏ С„Р°Р№Р»Р°: ' . $e->getMessage(),
                'imported' => 0,
                'updated' => 0,
                'errors' => [$e->getMessage()]
            ];
        }
        if (empty($rows) || count($rows) < 2) {
            return [
                'success' => false,
                'message' => 'Р¤Р°Р№Р» РїСѓСЃС‚ РёР»Рё РЅРµ СЃРѕРґРµСЂР¶РёС‚ РґР°РЅРЅС‹С…',
                'imported' => 0,
                'updated' => 0,
                'errors' => ['Р¤Р°Р№Р» РїСѓСЃС‚ РёР»Рё РЅРµ СЃРѕРґРµСЂР¶РёС‚ РґР°РЅРЅС‹С…']
            ];
        }
        // РљРѕСЂСЂРµРєС‚РЅРѕ РѕРїСЂРµРґРµР»СЏРµРј РїРµСЂРІСѓСЋ СЃС‚СЂРѕРєСѓ (Р·Р°РіРѕР»РѕРІРєРё) РґР»СЏ xlsx/csv
        $firstRowKey = array_key_first($rows);
        $rawHeaders = array_values($rows[$firstRowKey]);
        if (count(array_filter($rawHeaders, fn($h) => !is_string($h) || trim($h) === '')) > 0) {
            return [
                'success' => false,
                'message' => 'РќРµРєРѕСЂСЂРµРєС‚РЅС‹Р№ С„РѕСЂРјР°С‚ Р·Р°РіРѕР»РѕРІРєРѕРІ С„Р°Р№Р»Р°',
                'imported' => 0,
                'updated' => 0,
                'errors' => ['РќРµРєРѕСЂСЂРµРєС‚РЅС‹Р№ С„РѕСЂРјР°С‚ Р·Р°РіРѕР»РѕРІРєРѕРІ С„Р°Р№Р»Р° (РїСЂРѕРІРµСЂСЊС‚Рµ РїРµСЂРІСѓСЋ СЃС‚СЂРѕРєСѓ)']
            ];
        }
        $headers = array_map(fn($h) => trim(mb_strtolower($h)), $rawHeaders);
        $required = ['name', 'measurement_unit'];
        $missing = array_diff($required, $headers);
        if (!empty($missing)) {
            return [
                'success' => false,
                'message' => 'Р’ С„Р°Р№Р»Рµ РѕС‚СЃСѓС‚СЃС‚РІСѓСЋС‚ РѕР±СЏР·Р°С‚РµР»СЊРЅС‹Рµ РєРѕР»РѕРЅРєРё',
                'imported' => 0,
                'updated' => 0,
                'errors' => ['РћС‚СЃСѓС‚СЃС‚РІСѓСЋС‚ РѕР±СЏР·Р°С‚РµР»СЊРЅС‹Рµ РєРѕР»РѕРЅРєРё: ' . implode(', ', $missing)]
            ];
        }
        unset($rows[$firstRowKey]);
        // orgId РґРѕР»Р¶РµРЅ Р±С‹С‚СЊ РїРµСЂРµРґР°РЅ СЏРІРЅРѕ С‡РµСЂРµР· options (РєРѕРЅС‚СЂРѕР»Р»РµСЂ РѕР±СЏР·Р°РЅ СЌС‚Рѕ РґРµР»Р°С‚СЊ)
        if (!$orgId) {
            return [
                'success' => false,
                'message' => 'РќРµ СѓРґР°Р»РѕСЃСЊ РѕРїСЂРµРґРµР»РёС‚СЊ РѕСЂРіР°РЅРёР·Р°С†РёСЋ',
                'imported' => 0,
                'updated' => 0,
                'errors' => ['РќРµ СѓРґР°Р»РѕСЃСЊ РѕРїСЂРµРґРµР»РёС‚СЊ РѕСЂРіР°РЅРёР·Р°С†РёСЋ']
            ];
        }
        $unitCache = [];
        DB::beginTransaction();
        try {
            foreach ($rows as $i => $row) {
                $row = is_array($row) ? array_values($row) : $row;
                $data = array_combine($headers, array_map('trim', $row));
                $line = $i + 2;
                // Р’Р°Р»РёРґР°С†РёСЏ
                if (empty($data['name'])) {
                    $errors[] = "РЎС‚СЂРѕРєР° $line: РЅРµ СѓРєР°Р·Р°РЅРѕ РёРјСЏ РјР°С‚РµСЂРёР°Р»Р°";
                    continue;
                }
                // Р•РґРёРЅРёС†Р° РёР·РјРµСЂРµРЅРёСЏ
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
                    $errors[] = "РЎС‚СЂРѕРєР° $line: РЅРµ РЅР°Р№РґРµРЅР° РµРґРёРЅРёС†Р° РёР·РјРµСЂРµРЅРёСЏ";
                    continue;
                }
                // РџРѕРёСЃРє СЃСѓС‰РµСЃС‚РІСѓСЋС‰РµРіРѕ РјР°С‚РµСЂРёР°Р»Р°
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
                // РџРѕРґРіРѕС‚РѕРІРєР° РґР°РЅРЅС‹С…
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
                    $errors[] = "РЎС‚СЂРѕРєР° $line: " . $e->getMessage();
                    Log::error('[MaterialImport] РћС€РёР±РєР° РїСЂРё СЃРѕР·РґР°РЅРёРё/РѕР±РЅРѕРІР»РµРЅРёРё', ['line' => $line, 'data' => $materialData, 'error' => $e->getMessage()]);
                }
            }
            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            
            // TECHNICAL: РљСЂРёС‚РёС‡РµСЃРєР°СЏ РѕС€РёР±РєР° РїСЂРё РёРјРїРѕСЂС‚Рµ РјР°С‚РµСЂРёР°Р»РѕРІ
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
            
            Log::error('РљСЂРёС‚РёС‡РµСЃРєР°СЏ РѕС€РёР±РєР° РїСЂРё РёРјРїРѕСЂС‚Рµ РјР°С‚РµСЂРёР°Р»РѕРІ', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'РљСЂРёС‚РёС‡РµСЃРєР°СЏ РѕС€РёР±РєР°: ' . $e->getMessage(),
                'imported' => $imported,
                'updated' => $updated,
                'errors' => $errors
            ];
        }
        
        $isSuccess = empty($errors);
        $totalProcessed = $imported + $updated;
        
        // BUSINESS: Р—Р°РІРµСЂС€РµРЅРёРµ РёРјРїРѕСЂС‚Р° РјР°С‚РµСЂРёР°Р»РѕРІ - РєР»СЋС‡РµРІР°СЏ РјРµС‚СЂРёРєР°
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
        
        // AUDIT: РњР°СЃСЃРѕРІРѕРµ РёР·РјРµРЅРµРЅРёРµ РєР°С‚Р°Р»РѕРіР° РјР°С‚РµСЂРёР°Р»РѕРІ - РІР°Р¶РЅРѕ РґР»СЏ compliance
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
            'message' => $isSuccess ? 'РРјРїРѕСЂС‚ Р·Р°РІРµСЂС€С‘РЅ СѓСЃРїРµС€РЅРѕ' : 'РРјРїРѕСЂС‚ Р·Р°РІРµСЂС€С‘РЅ СЃ РѕС€РёР±РєР°РјРё',
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
            'Р¦РµРјРµРЅС‚ Рњ500', 'CEM500', 'РєРі', 'РћСЃРЅРѕРІРЅРѕР№ СЃС‚СЂРѕРёС‚РµР»СЊРЅС‹Р№ РјР°С‚РµСЂРёР°Р»', 'РЎС‚СЂРѕРёС‚РµР»СЊРЅС‹Рµ РјР°С‚РµСЂРёР°Р»С‹', '4500.50',
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
