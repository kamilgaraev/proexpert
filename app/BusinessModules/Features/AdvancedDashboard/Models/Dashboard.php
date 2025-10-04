<?php

namespace App\BusinessModules\Features\AdvancedDashboard\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User;
use App\Models\Organization;

class Dashboard extends Model
{
    use SoftDeletes;

    protected $table = 'dashboards';

    protected $fillable = [
        'user_id',
        'organization_id',
        'name',
        'description',
        'slug',
        'layout',
        'widgets',
        'filters',
        'is_default',
        'is_shared',
        'template',
        'shared_with',
        'visibility',
        'refresh_interval',
        'enable_realtime',
        'views_count',
        'last_viewed_at',
        'metadata',
    ];

    protected $casts = [
        'layout' => 'array',
        'widgets' => 'array',
        'filters' => 'array',
        'shared_with' => 'array',
        'metadata' => 'array',
        'is_default' => 'boolean',
        'is_shared' => 'boolean',
        'enable_realtime' => 'boolean',
        'views_count' => 'integer',
        'refresh_interval' => 'integer',
        'last_viewed_at' => 'datetime',
    ];

    protected $attributes = [
        'refresh_interval' => 300,
        'enable_realtime' => false,
        'is_default' => false,
        'is_shared' => false,
        'visibility' => 'private',
        'views_count' => 0,
    ];

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(DashboardAlert::class);
    }

    public function scheduledReports(): HasMany
    {
        return $this->hasMany(ScheduledReport::class);
    }

    // Scopes

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForOrganization($query, int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeShared($query)
    {
        return $query->where('is_shared', true);
    }

    public function scopeByTemplate($query, string $template)
    {
        return $query->where('template', $template);
    }

    public function scopeVisible($query, int $userId, int $organizationId)
    {
        return $query->where(function ($q) use ($userId, $organizationId) {
            $q->where('user_id', $userId)
              ->orWhere(function ($q2) use ($userId, $organizationId) {
                  $q2->where('is_shared', true)
                     ->where('organization_id', $organizationId)
                     ->where(function ($q3) use ($userId) {
                         $q3->whereJsonContains('shared_with', $userId)
                            ->orWhere('visibility', 'organization')
                            ->orWhere('visibility', 'team');
                     });
              });
        });
    }

    // Methods

    public function incrementViews(): void
    {
        $this->increment('views_count');
        $this->update(['last_viewed_at' => now()]);
    }

    public function isOwnedBy(int $userId): bool
    {
        return $this->user_id === $userId;
    }

    public function isSharedWith(int $userId): bool
    {
        if (!$this->is_shared) {
            return false;
        }

        if ($this->visibility === 'organization') {
            return true;
        }

        $sharedWith = $this->shared_with ?? [];
        return in_array($userId, $sharedWith);
    }

    public function canBeAccessedBy(int $userId, int $organizationId): bool
    {
        // Владелец всегда имеет доступ
        if ($this->isOwnedBy($userId)) {
            return true;
        }

        // Проверяем, что дашборд из той же организации
        if ($this->organization_id !== $organizationId) {
            return false;
        }

        // Проверяем права доступа
        return $this->isSharedWith($userId);
    }

    public function makeDefault(): void
    {
        // Сбрасываем is_default у других дашбордов пользователя
        static::where('user_id', $this->user_id)
            ->where('organization_id', $this->organization_id)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        $this->update(['is_default' => true]);
    }

    public function duplicate(string $newName = null): self
    {
        $newDashboard = $this->replicate();
        $newDashboard->name = $newName ?? $this->name . ' (копия)';
        $newDashboard->slug = null;
        $newDashboard->is_default = false;
        $newDashboard->views_count = 0;
        $newDashboard->last_viewed_at = null;
        $newDashboard->save();

        return $newDashboard;
    }
}

