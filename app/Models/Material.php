<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'consumption_rates',
        'accounting_data',
        'use_in_accounting_reports',
        'accounting_account',
    ];

    protected $casts = [
        'default_price' => 'decimal:2',
        'additional_properties' => 'array',
        'is_active' => 'boolean',
        'consumption_rates' => 'array',
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
     * Получить норму списания для указанного вида работ.
     *
     * @param int|string $workTypeId ID вида работ
     * @return float|null Норма списания или null, если не задана
     */
    public function getConsumptionRateForWorkType($workTypeId)
    {
        $rates = $this->consumption_rates;
        if (is_array($rates) && isset($rates[$workTypeId])) {
            return (float)$rates[$workTypeId];
        }
        return null;
    }

    /**
     * Установить норму списания для указанного вида работ.
     *
     * @param int|string $workTypeId ID вида работ
     * @param float $rate Норма списания
     * @return $this
     */
    public function setConsumptionRateForWorkType($workTypeId, $rate)
    {
        $rates = is_array($this->consumption_rates) ? $this->consumption_rates : [];
        $rates[$workTypeId] = (float)$rate;
        $this->consumption_rates = $rates;
        return $this;
    }

    /**
     * Получить все нормы списания с информацией о видах работ.
     *
     * @return array Массив с информацией о нормах списания
     */
    public function getConsumptionRatesWithWorkTypes()
    {
        $result = [];
        $rates = is_array($this->consumption_rates) ? $this->consumption_rates : [];
        
        if (!empty($rates)) {
            $workTypes = WorkType::whereIn('id', array_keys($rates))->get()->keyBy('id');
            
            foreach ($rates as $workTypeId => $rate) {
                $workType = $workTypes[$workTypeId] ?? null;
                $result[] = [
                    'work_type_id' => $workTypeId,
                    'work_type_name' => $workType ? $workType->name : 'Неизвестный вид работы',
                    'rate' => (float)$rate,
                    'unit' => $workType ? $workType->measurement_unit : null
                ];
            }
        }
        
        return $result;
    }
}
