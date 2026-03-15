<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\VideoMonitoring\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoCameraEvent extends Model
{
    public $timestamps = false;

    protected $table = 'video_camera_events';

    protected $fillable = [
        'camera_id',
        'organization_id',
        'project_id',
        'event_type',
        'severity',
        'message',
        'payload',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function camera(): BelongsTo
    {
        return $this->belongsTo(VideoCamera::class, 'camera_id');
    }
}
