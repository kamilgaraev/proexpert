<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Models\WorkType;

class Project extends Model
{
    use HasFactory, SoftDeletes;
    
    /**
     * Boot метод - события модели
     */
    protected static function boot()
    {
        parent::boot();
        
        // При создании проекта автоматически добавляем owner в project_organization
        static::created(function ($project) {
            if ($project->organization_id) {
                \Illuminate\Support\Facades\DB::table('project_organization')->insert([
                    'project_id' => $project->id,
                    'organization_id' => $project->organization_id,
                    'role' => 'owner',
                    'role_new' => 'owner',
                    'is_active' => true,
                    'invited_at' => $project->created_at,
                    'accepted_at' => $project->created_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    protected $fillable = [
        'organization_id',
        'name',
        'address',
        'latitude',
        'longitude',
        'geocoded_at',
        'description',
        'customer',
        'designer',
        'budget_amount',
        'site_area_m2',
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
        'budget_amount' => 'decimal:2',
        'site_area_m2' => 'decimal:2',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'geocoded_at' => 'datetime',
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
     * Получить организации, участвующие в проекте (с Custom Pivot).
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'project_organization')
            ->using(ProjectOrganization::class)
            ->withPivot([
                'role', 
                'role_new',
                'permissions', 
                'is_active', 
                'added_by_user_id', 
                'invited_at', 
                'accepted_at', 
                'metadata'
            ])
            ->withTimestamps();
    }
    
    /**
     * Получить активных участников проекта.
     */
    public function activeParticipants()
    {
        return $this->organizations()->wherePivot('is_active', true);
    }
    
    /**
     * Получить роль организации в проекте.
     */
    public function getOrganizationRole(int $organizationId): ?\App\Enums\ProjectOrganizationRole
    {
        // Проверить: это owner проекта?
        if ($this->organization_id === $organizationId) {
            return \App\Enums\ProjectOrganizationRole::OWNER;
        }
        
        // Проверить в participants
        $pivot = $this->organizations()
            ->wherePivot('organization_id', $organizationId)
            ->wherePivot('is_active', true)
            ->first()?->pivot;
        
        if (!$pivot) {
            return null;
        }
        
        return $pivot->role;
    }
    
    /**
     * Проверить участие организации в проекте.
     */
    public function hasOrganization(int $organizationId, ?\App\Enums\ProjectOrganizationRole $role = null): bool
    {
        // Owner проекта всегда участник
        if ($this->organization_id === $organizationId) {
            return $role === null || $role === \App\Enums\ProjectOrganizationRole::OWNER;
        }
        
        $query = $this->organizations()
            ->wherePivot('organization_id', $organizationId)
            ->wherePivot('is_active', true);
        
        if ($role) {
            $query->wherePivot('role_new', $role->value);
        }
        
        return $query->exists();
    }
    
    /**
     * Получить pivot для организации.
     */
    public function getOrganizationPivot(int $organizationId): ?ProjectOrganization
    {
        return $this->organizations()
            ->wherePivot('organization_id', $organizationId)
            ->wherePivot('is_active', true)
            ->first()?->pivot;
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

    /**
     * Получить используемые в проекте виды работ через CompletedWork.
     */
    public function workTypes()
    {
        return $this->belongsToMany(WorkType::class, 'completed_works', 'project_id', 'work_type_id')
            ->select('work_types.id', 'work_types.name', 'work_types.code', 'work_types.measurement_unit_id')
            ->distinct();
    }
}
