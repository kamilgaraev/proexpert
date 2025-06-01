<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Material extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'code',
        'measurement_unit_id',
        'description',
        'category',
        'default_price',
        'additional_properties',
        'is_active',
        'external_code',
        'sbis_nomenclature_code',
        'sbis_unit_code',
        'accounting_data',
        'use_in_accounting_reports',
        'accounting_account',
    ];

    protected $casts = [
        'default_price' => 'decimal:2',
        'additional_properties' => 'array',
        'is_active' => 'boolean',
        'accounting_data' => 'array',
        'use_in_accounting_reports' => 'boolean',
    ];

    /**
     * Получить организацию, которой принадлежит материал.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Получить единицу измерения материала.
     */
    public function measurementUnit(): BelongsTo
    {
        return $this->belongsTo(MeasurementUnit::class);
    }

    /**
     * Получить приемки данного материала.
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(MaterialReceipt::class);
    }

    /**
     * Получить списания данного материала.
     */
    public function writeOffs(): HasMany
    {
        return $this->hasMany(MaterialWriteOff::class);
    }

    /**
     * Получить остатки данного материала по проектам.
     */
    public function balances(): HasMany
    {
        return $this->hasMany(MaterialBalance::class);
    }

    /**
     * Материалы с указанным внешним кодом.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $externalCode
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithExternalCode($query, $externalCode)
    {
        return $query->where('external_code', $externalCode);
    }

    /**
     * Материалы с указанным кодом номенклатуры СБИС.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $nomenclatureCode
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeWithSbisNomenclatureCode($query, $nomenclatureCode)
    {
        return $query->where('sbis_nomenclature_code', $nomenclatureCode);
    }

    /**
     * Материалы, которые используются в бухгалтерских отчетах.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeUsedInAccounting($query)
    {
        return $query->where('use_in_accounting_reports', true);
    }

    /**
     * Виды работ, для которых используется данный материал (с нормами по умолчанию).
     */
    public function workTypes(): BelongsToMany
    {
        return $this->belongsToMany(WorkType::class, 'work_type_materials')
            ->using(WorkTypeMaterial::class)
            ->withPivot(['organization_id', 'default_quantity', 'notes'])
            ->withTimestamps();
    }

    /**
     * Получить нормы списания по видам работ для данного материала.
     * Возвращает массив: [work_type_id, work_type_name, rate, notes]
     */
    public function getConsumptionRatesWithWorkTypes(): array
    {
        return $this->workTypes()->get()->map(function ($workType) {
            return [
                'work_type_id' => $workType->id,
                'work_type_name' => $workType->name,
                'rate' => $workType->pivot->default_quantity ?? null,
                'notes' => $workType->pivot->notes ?? null,
            ];
        })->toArray();
    }
}
