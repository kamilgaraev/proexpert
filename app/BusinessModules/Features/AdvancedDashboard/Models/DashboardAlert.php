<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User;
use App\Models\Organization;

class DashboardAlert extends Model
{
    use SoftDeletes;

    protected $table = 'dashboard_alerts';

    protected $fillable = [
        'dashboard_id',
        'user_id',
        'organization_id',
        'name',
        'description',
        'alert_type',
        'target_entity',
        'target_entity_id',
        'conditions',
        'comparison_operator',
        'threshold_value',
        'threshold_unit',
        'notification_channels',
        'recipients',
        'cooldown_minutes',
        'is_active',
        'is_triggered',
        'last_triggered_at',
        'last_checked_at',
        'trigger_count',
        'priority',
        'metadata',
    ];

    protected $casts = [
        'conditions' => 'array',
        'notification_channels' => 'array',
        'recipients' => 'array',
        'metadata' => 'array',
        'is_active' => 'boolean',
        'is_triggered' => 'boolean',
        'cooldown_minutes' => 'integer',
        'trigger_count' => 'integer',
        'threshold_value' => 'decimal:2',
        'last_triggered_at' => 'datetime',
        'last_checked_at' => 'datetime',
    ];

    protected $attributes = [
        'is_active' => true,
        'is_triggered' => false,
        'cooldown_minutes' => 60,
        'trigger_count' => 0,
        'priority' => 'medium',
    ];

    // Relationships

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeTriggered($query)
    {
        return $query->where('is_triggered', true);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('alert_type', $type);
    }

    public function scopeByPriority($query, string $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeNeedingCheck($query)
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('last_checked_at')
                  ->orWhereRaw('last_checked_at < NOW() - INTERVAL \'1 hour\'');
            });
    }

    public function scopeOutOfCooldown($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('last_triggered_at')
              ->orWhereRaw('last_triggered_at < NOW() - INTERVAL cooldown_minutes || \' minutes\'');
        });
    }

    // Methods

    public function trigger(): void
    {
        $this->update([
            'is_triggered' => true,
            'last_triggered_at' => now(),
            'trigger_count' => $this->trigger_count + 1,
        ]);
    }

    public function reset(): void
    {
        $this->update([
            'is_triggered' => false,
            'last_checked_at' => now(),
        ]);
    }

    public function updateCheckTime(): void
    {
        $this->update(['last_checked_at' => now()]);
    }

    public function isInCooldown(): bool
    {
        if (!$this->last_triggered_at) {
            return false;
        }

        $cooldownEnds = $this->last_triggered_at->addMinutes($this->cooldown_minutes);
        return now()->lt($cooldownEnds);
    }

    public function canTrigger(): bool
    {
        return $this->is_active && !$this->isInCooldown();
    }

    public function shouldCheck(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if (!$this->last_checked_at) {
            return true;
        }

        // Проверяем каждый час
        return now()->diffInHours($this->last_checked_at) >= 1;
    }

    public function getTargetEntity()
    {
        if (!$this->target_entity || !$this->target_entity_id) {
            return null;
        }

        // Динамически получаем модель по типу сущности
        $modelMap = [
            'project' => \App\Models\Project::class,
            'contract' => \App\Models\Contract::class,
            'material' => \App\Models\Material::class,
            'user' => \App\Models\User::class,
        ];

        $modelClass = $modelMap[$this->target_entity] ?? null;

        if (!$modelClass) {
            return null;
        }

        return $modelClass::find($this->target_entity_id);
    }
}

