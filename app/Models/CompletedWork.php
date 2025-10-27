<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompletedWork extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'project_id',
        'contract_id',
        'work_type_id',
        'user_id',
        'contractor_id',
        'quantity',
        'price',
        'total_amount',
        'completion_date',
        'notes',
        'status',
        'additional_info',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'price' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'completion_date' => 'date',
        'additional_info' => 'array',
    ];

    /**
     * Получить организацию, которой принадлежит запись.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Получить проект, к которому относится запись.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Получить вид выполненной работы.
     */
    public function workType(): BelongsTo
    {
        return $this->belongsTo(WorkType::class);
    }

    /**
     * Получить пользователя, создавшего запись о выполненной работе.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Получить подрядчика, выполнившего работу.
     */
    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    /**
     * Алиас для исполнителя (пользователь-прораб).
     * Некоторые контроллеры обращаются к relation "executor".
     */
    public function executor(): BelongsTo
    {
        // Используем ту же связь, что и user()
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Получить договор, к которому относится выполненная работа.
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * Получить файлы, прикрепленные к выполненной работе.
     */
    public function files()
    {
        return $this->morphMany(File::class, 'fileable');
    }

    /**
     * Получить материалы, использованные в данной выполненной работе.
     */
    public function materials()
    {
        return $this->belongsToMany(Material::class, 'completed_work_materials')
            ->using(CompletedWorkMaterial::class)
            ->withPivot(['quantity', 'unit_price', 'total_amount', 'notes'])
            ->withTimestamps();
    }

    /**
     * Акты выполненных работ в которые включена данная работа
     */
    public function performanceActs()
    {
        return $this->belongsToMany(ContractPerformanceAct::class, 'performance_act_completed_works', 'completed_work_id', 'performance_act_id')
            ->using(PerformanceActCompletedWork::class)
            ->withPivot(['included_quantity', 'included_amount', 'notes'])
            ->withTimestamps();
    }

    public function resolveRouteBinding($value, $field = null)
    {
        $completedWork = static::where($this->getRouteKeyName(), $value)->firstOrFail();
        
        $user = request()->user();
        if ($user && $user->current_organization_id) {
            if ($completedWork->organization_id !== $user->current_organization_id) {
                abort(403, 'У вас нет доступа к этой выполненной работе');
            }
        }
        
        return $completedWork;
    }
}
