<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConstructionJournal\JournalEntryStatusEnum;
use App\Traits\HasOnboardingDemo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CompletedWork extends Model
{
    use HasFactory;
    use SoftDeletes;
    use HasOnboardingDemo;

    public const ORIGIN_MANUAL = 'manual';
    public const ORIGIN_SCHEDULE = 'schedule';
    public const ORIGIN_JOURNAL = 'journal';

    public const PLANNING_PLANNED = 'planned';
    public const PLANNING_REQUIRES_SCHEDULE = 'requires_schedule';

    protected $fillable = [
        'organization_id',
        'project_id',
        'schedule_task_id',
        'estimate_item_id',
        'journal_entry_id',
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

    public function files()
    {
        return $this->morphMany(File::class, 'fileable');
    }

    public function materials()
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
            ->withPivot(['included_quantity', 'included_amount', 'notes'])
            ->withTimestamps();
    }

    public function scopeRequiresSchedule($query)
    {
        return $query->where('planning_status', self::PLANNING_REQUIRES_SCHEDULE);
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
