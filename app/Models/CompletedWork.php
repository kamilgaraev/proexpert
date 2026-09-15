<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConstructionJournal\JournalEntryStatusEnum;
use App\Traits\HasOnboardingDemo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CompletedWork extends Model
{
    use HasFactory;
    use HasOnboardingDemo;
    use SoftDeletes;

    public const ORIGIN_MANUAL = 'manual';

    public const ORIGIN_SCHEDULE = 'schedule';

    public const ORIGIN_JOURNAL = 'journal';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REJECTED = 'rejected';

    public const PLANNING_PLANNED = 'planned';

    public const PLANNING_REQUIRES_SCHEDULE = 'requires_schedule';

    protected $fillable = [
        'organization_id',
        'project_id',
        'schedule_task_id',
        'estimate_item_id',
        'journal_entry_id',
        'journal_work_volume_id',
        'journal_material_id',
        'journal_equipment_id',
        'journal_worker_id',
        'work_origin_type',
        'planning_status',
        'contract_id',
        'work_type_id',
        'user_id',
        'contractor_id',
        'quantity',
        'completed_quantity',
        'price',
        'total_amount',
        'completion_date',
        'notes',
        'description',
        'status',
        'additional_info',
        'is_onboarding_demo',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'completed_quantity' => 'decimal:4',
        'price' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'completion_date' => 'date',
        'additional_info' => 'array',
        'is_onboarding_demo' => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function workType(): BelongsTo
    {
        return $this->belongsTo(WorkType::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function scheduleTask(): BelongsTo
    {
        return $this->belongsTo(ScheduleTask::class);
    }

    public function estimateItem(): BelongsTo
    {
        return $this->belongsTo(EstimateItem::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(ConstructionJournalEntry::class, 'journal_entry_id');
    }

    public function journalWorkVolume(): BelongsTo
    {
        return $this->belongsTo(JournalWorkVolume::class, 'journal_work_volume_id');
    }

    public function journalMaterial(): BelongsTo
    {
        return $this->belongsTo(JournalMaterial::class, 'journal_material_id');
    }

    public function journalEquipment(): BelongsTo
    {
        return $this->belongsTo(JournalEquipment::class, 'journal_equipment_id');
    }

    public function journalWorker(): BelongsTo
    {
        return $this->belongsTo(JournalWorker::class, 'journal_worker_id');
    }

    public function files()
    {
        return $this->morphMany(File::class, 'fileable');
    }

    public function materials(): BelongsToMany
    {
        return $this->belongsToMany(Material::class, 'completed_work_materials')
            ->using(CompletedWorkMaterial::class)
            ->withPivot(['quantity', 'unit_price', 'total_amount', 'notes'])
            ->withTimestamps();
    }

    public function performanceActs()
    {
        return $this->belongsToMany(ContractPerformanceAct::class, 'performance_act_completed_works', 'completed_work_id', 'performance_act_id')
            ->using(PerformanceActCompletedWork::class)
            ->withPivot(['included_quantity', 'included_amount', 'currency', 'notes'])
            ->withTimestamps();
    }

    public function scopeRequiresSchedule($query)
    {
        return $query->where('planning_status', self::PLANNING_REQUIRES_SCHEDULE);
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', self::STATUS_CONFIRMED);
    }

    public function scopeOfficiallyCompleted($query)
    {
        return $query->whereNull('deleted_at')->where('status', self::STATUS_CONFIRMED);
    }

    public function effectiveCompletedQuantity(): float
    {
        $quantity = $this->completed_quantity ?? $this->quantity ?? 0;

        return max(0.0, (float) $quantity);
    }

    public function isResourceFact(): bool
    {
        $factKind = data_get($this->additional_info, 'fact_kind');

        return in_array($factKind, ['material', 'equipment', 'labor', 'worker'], true)
            || $this->journal_material_id !== null
            || $this->journal_equipment_id !== null
            || $this->journal_worker_id !== null;
    }

    public function isProductionFact(): bool
    {
        return ! $this->isResourceFact();
    }

    public function scopeEffectiveForSchedule($query)
    {
        return $query
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->where(function ($builder): void {
                $builder
                    ->where(function ($plainBuilder): void {
                        $plainBuilder
                            ->whereNull('journal_entry_id')
                            ->whereIn('status', ['draft', 'pending', 'in_review', 'confirmed']);
                    })
                    ->orWhere(function ($journalBuilder): void {
                        $journalBuilder
                            ->whereNotNull('journal_entry_id')
                            ->whereHas('journalEntry', function ($journalEntryQuery): void {
                                $journalEntryQuery->where('status', JournalEntryStatusEnum::APPROVED);
                            });
                    });
            });
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
