<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\VideoMonitoring\Models;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class VideoCamera extends Model
{
    use SoftDeletes;

    protected $table = 'video_cameras';

    protected $fillable = [
        'organization_id',
        'project_id',
        'created_by',
        'updated_by',
        'name',
        'zone',
        'source_type',
        'source_url',
        'playback_url',
        'username',
        'password',
        'host',
        'port',
        'stream_path',
        'transport_protocol',
        'status',
        'status_message',
        'last_checked_at',
        'last_online_at',
        'is_enabled',
        'settings',
    ];

    protected $hidden = [
        'password',
        'source_url',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'settings' => 'array',
            'last_checked_at' => 'datetime',
            'last_online_at' => 'datetime',
            'is_enabled' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(VideoCameraEvent::class, 'camera_id');
    }

    public function getMaskedSourceUrlAttribute(): ?string
    {
        if (!$this->source_url) {
            return null;
        }

        $parts = parse_url($this->source_url);

        if (!is_array($parts)) {
            return $this->source_url;
        }

        $scheme = $parts['scheme'] ?? 'rtsp';
        $host = $parts['host'] ?? 'camera';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';

        return sprintf('%s://%s%s%s', $scheme, $host, $port, $path);
    }
}
