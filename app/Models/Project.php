<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Project extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'address',
        'description',
        'customer',
        'designer',
        'start_date',
        'end_date',
        'status',
        'additional_info',
        'is_archived',
        'is_head',
        'external_code',
        'cost_category_id',
        'accounting_data',
        'use_in_accounting_reports',
        'customer_organization',
        'customer_representative',
        'contract_number',
        'contract_date',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'additional_info' => 'array',
        'is_archived' => 'boolean',
        'is_head' => 'boolean',
        'accounting_data' => 'array',
        'use_in_accounting_reports' => 'boolean',
        'contract_date' => 'date',
    ];

    /**
     * Получить организацию, которой принадлежит проект.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Получить категорию затрат, к которой относится проект.
     */
    public function costCategory(): BelongsTo
    {
        return $this->belongsTo(CostCategory::class);
    }

    /**
     * Получить пользователей, назначенных на проект.
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'project_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Получить организации, участвующие в проекте.
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'project_organization')
            ->withPivot(['role', 'permissions'])
            ->withTimestamps();
    }

    /**
     * Получить приемки материалов по проекту.
     */
    public function materialReceipts(): HasMany
    {
        return $this->hasMany(MaterialReceipt::class);
    }

    /**
     * Получить списания материалов по проекту.
     */
    public function materialWriteOffs(): HasMany
    {
        return $this->hasMany(MaterialWriteOff::class);
    }

    /**
     * Получить выполненные работы по проекту.
     */
    public function completedWorks(): HasMany
    {
        return $this->hasMany(CompletedWork::class);
    }

    /**
     * Получить остатки материалов по проекту.
     */
    public function materialBalances(): HasMany
    {
        return $this->hasMany(MaterialBalance::class);
    }

    /**
     * Получить файлы, прикрепленные к проекту.
     */
    public function files()
    {
        return $this->morphMany(File::class, 'fileable');
    }

    /**
     * Получить материалы, связанные с проектом (по приемкам и списаниям).
     */
    public function materials()
    {
        // Если материалы привязаны к проекту через MaterialReceipt и MaterialWriteOff,
        // то можно использовать hasManyThrough или кастомный запрос. Для простоты — через MaterialReceipt.
        return $this->hasMany(MaterialReceipt::class)->select('material_id', 'project_id');
    }

    /**
     * Проекты, которые используются в бухгалтерских отчетах.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeUsedInAccounting($query)
    {
        return $query->where('use_in_accounting_reports', true);
    }

    /**
     * Проекты с указанным внешним кодом.
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
     * Проекты, относящиеся к указанной категории затрат.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int $costCategoryId
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByCostCategory($query, $costCategoryId)
    {
        return $query->where('cost_category_id', $costCategoryId);
    }

    /**
     * Получить контракты, связанные с проектом.
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }
}
