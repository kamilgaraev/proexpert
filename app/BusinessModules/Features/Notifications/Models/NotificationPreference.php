<?php

namespace App\BusinessModules\Features\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;
use App\Models\Organization;

class NotificationPreference extends Model
{
    protected $fillable = [
        'user_id',
        'organization_id',
        'notification_type',
        'enabled_channels',
        'quiet_hours_start',
        'quiet_hours_end',
        'frequency_limit',
        'metadata',
    ];

    protected $casts = [
        'enabled_channels' => 'array',
        'frequency_limit' => 'array',
        'metadata' => 'array',
        'quiet_hours_start' => 'datetime:H:i',
        'quiet_hours_end' => 'datetime:H:i',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForOrganization($query, ?int $organizationId)
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('notification_type', $type);
    }

    public function isChannelEnabled(string $channel): bool
    {
        return in_array($channel, $this->enabled_channels ?? []);
    }

    public function isInQuietHours(\Carbon\Carbon $time = null): bool
    {
        $time = $time ?? now();
        
        if (!$this->quiet_hours_start || !$this->quiet_hours_end) {
            return false;
        }

        $start = \Carbon\Carbon::parse($this->quiet_hours_start);
        $end = \Carbon\Carbon::parse($this->quiet_hours_end);
        
        return $time->between($start, $end);
    }
}

