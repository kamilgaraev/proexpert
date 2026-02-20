<?php

namespace App\Models;

use App\Enums\EstimatePositionItemType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

class EstimateItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'estimate_id',
        'estimate_section_id',
        'catalog_item_id', // ID позиции из справочника
        'parent_work_id', // ⭐ ID родительской работы ГЭСН
        'normative_rate_id',
        'normative_rate_code',
        'item_type',
        'position_number',
        'name',
        'description',
        'work_type_id',
        'measurement_unit_id',
        'quantity',
        'quantity_coefficient',
        'quantity_total',
        'unit_price',
        'base_unit_price',
        'price_index',
        'current_unit_price',
        'price_coefficient',
        'direct_costs',
        'materials_cost',
        'machinery_cost',
        'labor_cost',
        'equipment_cost',
        'labor_hours',
        'machinery_hours',
        'base_materials_cost',
        'base_machinery_cost',
        'base_labor_cost',
        'materials_index',
        'machinery_index',
        'labor_index',
        'applied_coefficients',
        'coefficient_total',
        'resource_calculation',
        'custom_resources',
        'overhead_amount',
        'profit_amount',
        'total_amount',
        'current_total_amount',
        'justification',
        'notes',
        'is_manual',
        'is_not_accounted', // ⭐ Флаг "не учтенного" материала (буква Н)
        'metadata',
    ];

    protected $casts = [
        'item_type' => EstimatePositionItemType::class,
        'quantity' => 'decimal:4',
        'quantity_coefficient' => 'decimal:4',
        'quantity_total' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'base_unit_price' => 'decimal:2',
        'price_index' => 'decimal:4',
        'current_unit_price' => 'decimal:2',
        'price_coefficient' => 'decimal:4',
        'direct_costs' => 'decimal:2',
        'materials_cost' => 'decimal:2',
        'machinery_cost' => 'decimal:2',
        'labor_cost' => 'decimal:2',
        'equipment_cost' => 'decimal:2',
        'labor_hours' => 'decimal:4',
        'machinery_hours' => 'decimal:4',
        'base_materials_cost' => 'decimal:2',
        'base_machinery_cost' => 'decimal:2',
        'base_labor_cost' => 'decimal:2',
        'materials_index' => 'decimal:4',
        'machinery_index' => 'decimal:4',
        'labor_index' => 'decimal:4',
        'applied_coefficients' => 'array',
        'coefficient_total' => 'decimal:4',
        'resource_calculation' => 'array',
        'custom_resources' => 'array',
        'overhead_amount' => 'decimal:2',
        'profit_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'current_total_amount' => 'decimal:2',
        'is_manual' => 'boolean',
        'is_not_accounted' => 'boolean', // ⭐ Флаг "Н"
        'metadata' => 'array',
    ];

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(EstimateSection::class, 'estimate_section_id');
    }

    /**
     * ⭐ Родительская работа ГЭСН (для подпозиций: материалы, механизмы, labor)
     */
    public function parentWork(): BelongsTo
    {
        return $this->belongsTo(EstimateItem::class, 'parent_work_id');
    }

    /**
     * ⭐ Подпозиции (материалы, механизмы, labor) этой работы ГЭСН
     */
    public function childItems(): HasMany
    {
        return $this->hasMany(EstimateItem::class, 'parent_work_id');
    }

    public function workType(): BelongsTo
    {
        return $this->belongsTo(WorkType::class);
    }

    public function measurementUnit(): BelongsTo
    {
        return $this->belongsTo(MeasurementUnit::class);
    }

    public function normativeRate(): BelongsTo
    {
        return $this->belongsTo(NormativeRate::class, 'normative_rate_id');
    }

    public function catalogItem(): BelongsTo
    {
        return $this->belongsTo(EstimatePositionCatalog::class, 'catalog_item_id')->withTrashed();
    }

    public function resources(): HasMany
    {
        return $this->hasMany(EstimateItemResource::class);
    }

    public function works(): HasMany
    {
        return $this->hasMany(EstimateItemWork::class)->orderBy('sort_order');
    }

    public function totals(): HasMany
    {
        return $this->hasMany(EstimateItemTotal::class)->orderBy('sort_order');
    }

    /**
     * Фактические объемы работ из журнала работ
     */
    public function journalWorkVolumes(): HasMany
    {
        return $this->hasMany(JournalWorkVolume::class);
    }

    /**
     * Получить сумму фактических объемов из журнала работ
     */
    public function getActualVolume(): float
    {
        return (float) $this->journalWorkVolumes()
            ->whereHas('journalEntry', function ($query) {
                $query->where('status', \App\Enums\ConstructionJournal\JournalEntryStatusEnum::APPROVED);
            })
            ->sum('quantity');
    }

    /**
     * Получить процент выполнения позиции сметы
     */
    public function getCompletionPercentage(): float
    {
        if (!$this->quantity_total || $this->quantity_total == 0) {
            return 0;
        }

        $actualVolume = $this->getActualVolume();
        return min(100, ($actualVolume / $this->quantity_total) * 100);
    }

    public function scopeByEstimate($query, int $estimateId)
    {
        return $query->where('estimate_id', $estimateId);
    }

    public function scopeBySection($query, int $sectionId)
    {
        return $query->where('estimate_section_id', $sectionId);
    }

    public function scopeManual($query)
    {
        return $query->where('is_manual', true);
    }

    public function scopeFromCatalog($query)
    {
        return $query->where('is_manual', false);
    }

    public function scopeWorks($query)
    {
        return $query->where('item_type', 'work');
    }

    public function scopeMaterials($query)
    {
        return $query->where('item_type', 'material');
    }

    public function scopeEquipment($query)
    {
        return $query->where('item_type', 'equipment');
    }

    public function scopeLabor($query)
    {
        return $query->where('item_type', 'labor');
    }

    public function scopeSummary($query)
    {
        return $query->where('item_type', 'summary');
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('item_type', $type);
    }

    public function scopeFromNormative($query)
    {
        return $query->whereNotNull('normative_rate_id');
    }

    public function scopeWithNormativeRate($query)
    {
        return $query->with('normativeRate');
    }

    public function isWork(): bool
    {
        return $this->item_type === EstimatePositionItemType::WORK;
    }

    public function isMaterial(): bool
    {
        return $this->item_type === EstimatePositionItemType::MATERIAL;
    }

    public function isEquipment(): bool
    {
        return $this->item_type === EstimatePositionItemType::EQUIPMENT;
    }

    public function isLabor(): bool
    {
        return $this->item_type === EstimatePositionItemType::LABOR;
    }

    public function isSummary(): bool
    {
        return $this->item_type === EstimatePositionItemType::SUMMARY;
    }

    public function calculateTotal(): float
    {
        return $this->direct_costs + $this->overhead_amount + $this->profit_amount;
    }

    public function hasResources(): bool
    {
        return $this->resources()->exists();
    }

    public function isFromNormative(): bool
    {
        return !is_null($this->normative_rate_id);
    }

    public function hasAppliedCoefficients(): bool
    {
        return !empty($this->applied_coefficients);
    }

    public function getTotalCostBreakdown(): array
    {
        return [
            'materials' => (float) $this->materials_cost,
            'machinery' => (float) $this->machinery_cost,
            'labor' => (float) $this->labor_cost,
            'equipment' => (float) $this->equipment_cost,
            'overhead' => (float) $this->overhead_amount,
            'profit' => (float) $this->profit_amount,
            'total' => (float) $this->total_amount,
        ];
    }

    public function resolveRouteBinding($value, $field = null)
    {
        Log::info('[EstimateItem::resolveRouteBinding] Начало', [
            'value' => $value,
            'value_type' => gettype($value),
            'field' => $field,
            'route' => request()->route()?->getName(),
            'url' => request()->fullUrl(),
            'method' => request()->method(),
        ]);
        
        // Приводим значение к int для корректного поиска
        $id = (int) $value;
        
        Log::info('[EstimateItem::resolveRouteBinding] Поиск элемента', [
            'original_value' => $value,
            'converted_id' => $id,
        ]);
        
        // Сначала находим элемент (включая удаленные)
        $item = static::withTrashed()
            ->where('id', $id)
            ->first();
        
        Log::info('[EstimateItem::resolveRouteBinding] Результат поиска', [
            'id' => $id,
            'item_found' => $item !== null,
            'item_id' => $item?->id,
            'item_estimate_id' => $item?->estimate_id,
            'item_deleted_at' => $item?->deleted_at,
            'sql_query' => static::withTrashed()->where('id', $id)->toSql(),
        ]);
        
        if (!$item) {
            Log::warning('[EstimateItem::resolveRouteBinding] Элемент не найден', [
                'id' => $id,
            ]);
            abort(404, 'Позиция сметы не найдена');
        }
        
        // Загружаем связь estimate (включая удаленные)
        $item->load(['estimate' => function ($query) {
            $query->withTrashed();
        }]);
        
        Log::info('[EstimateItem::resolveRouteBinding] После загрузки estimate', [
            'item_id' => $item->id,
            'estimate_loaded' => $item->relationLoaded('estimate'),
            'estimate_exists' => $item->estimate !== null,
            'estimate_id' => $item->estimate?->id,
            'estimate_organization_id' => $item->estimate?->organization_id,
            'estimate_deleted_at' => $item->estimate?->deleted_at,
        ]);
        
        $user = request()->user();
        Log::info('[EstimateItem::resolveRouteBinding] Информация о пользователе', [
            'user_exists' => $user !== null,
            'user_id' => $user?->id,
            'current_organization_id' => $user?->current_organization_id,
        ]);
        
        if ($user && $user->current_organization_id) {
            // Если estimate не найден, возвращаем 404
            if (!$item->estimate) {
                Log::warning('[EstimateItem::resolveRouteBinding] Estimate не найден для элемента', [
                    'item_id' => $item->id,
                    'item_estimate_id' => $item->estimate_id,
                ]);
                abort(404, 'Смета для этой позиции не найдена');
            }
            
            // Проверяем организацию
            $itemOrgId = (int)$item->estimate->organization_id;
            $userOrgId = (int)$user->current_organization_id;
            
            Log::info('[EstimateItem::resolveRouteBinding] Проверка организации', [
                'item_id' => $item->id,
                'estimate_id' => $item->estimate->id,
                'item_organization_id' => $itemOrgId,
                'user_organization_id' => $userOrgId,
                'match' => $itemOrgId === $userOrgId,
            ]);
            
            if ($itemOrgId !== $userOrgId) {
                Log::warning('[EstimateItem::resolveRouteBinding] Организация не совпадает', [
                    'item_id' => $item->id,
                    'item_organization_id' => $itemOrgId,
                    'user_organization_id' => $userOrgId,
                ]);
                abort(403, 'У вас нет доступа к этой позиции сметы');
            }
        }
        
        Log::info('[EstimateItem::resolveRouteBinding] Успешное резолвинг', [
            'item_id' => $item->id,
            'estimate_id' => $item->estimate?->id,
        ]);
        
        return $item;
    }

    public function getEffectiveCoefficient(): float
    {
        return (float) ($this->coefficient_total ?? 1.0);
    }
}


