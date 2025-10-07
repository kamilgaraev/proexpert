<?php

namespace App\BusinessModules\Features\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationAnalytics extends Model
{
    protected $fillable = [
        'notification_id',
        'channel',
        'status',
        'sent_at',
        'delivered_at',
        'opened_at',
        'clicked_at',
        'failed_at',
        'error_message',
        'retry_count',
        'tracking_id',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'opened_at' => 'datetime',
        'clicked_at' => 'datetime',
        'failed_at' => 'datetime',
        'retry_count' => 'integer',
    ];

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }

    public function scopeByChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeSuccessful($query)
    {
        return $query->whereIn('status', ['sent', 'delivered', 'opened', 'clicked']);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeOpened($query)
    {
        return $query->whereNotNull('opened_at');
    }

    public function scopeClicked($query)
    {
        return $query->whereNotNull('clicked_at');
    }

    public function updateStatus(string $status, ?\Carbon\Carbon $timestamp = null): void
    {
        $timestamp = $timestamp ?? now();
        $data = ['status' => $status];
        
        switch ($status) {
            case 'sent':
                $data['sent_at'] = $timestamp;
                break;
            case 'delivered':
                $data['delivered_at'] = $timestamp;
                break;
            case 'opened':
                $data['opened_at'] = $timestamp;
                break;
            case 'clicked':
                $data['clicked_at'] = $timestamp;
                break;
            case 'failed':
                $data['failed_at'] = $timestamp;
                break;
        }
        
        $this->update($data);
    }

    public function incrementRetry(): void
    {
        $this->increment('retry_count');
    }
}

