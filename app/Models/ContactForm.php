<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactForm extends Model
{
    use HasFactory;

    public const STATUS_NEW = 'new';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    public const CHANNEL_PUBLIC_FORM = 'public_form';
    public const CHANNEL_CUSTOMER_PORTAL = 'customer_portal';
    public const CHANNEL_MANUAL = 'manual';

    protected $fillable = [
        'organization_id',
        'assigned_system_admin_id',
        'name',
        'email',
        'phone',
        'company',
        'company_role',
        'company_size',
        'subject',
        'message',
        'consent_to_personal_data',
        'consent_version',
        'page_source',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'status',
        'priority',
        'channel',
        'internal_notes',
        'last_activity_at',
        'escalated_at',
        'escalated_by_system_admin_id',
        'telegram_data',
        'is_processed',
        'processed_at',
    ];

    protected $casts = [
        'consent_to_personal_data' => 'boolean',
        'telegram_data' => 'array',
        'internal_notes' => 'array',
        'is_processed' => 'boolean',
        'processed_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'escalated_at' => 'datetime',
    ];

    public function markAsProcessed(): void
    {
        $this->update([
            'is_processed' => true,
            'processed_at' => now(),
            'status' => self::STATUS_PROCESSING,
            'last_activity_at' => now(),
        ]);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function assignedSystemAdmin(): BelongsTo
    {
        return $this->belongsTo(SystemAdmin::class, 'assigned_system_admin_id');
    }

    public function escalatedBySystemAdmin(): BelongsTo
    {
        return $this->belongsTo(SystemAdmin::class, 'escalated_by_system_admin_id');
    }

    public function scopeUnprocessed($query)
    {
        return $query->where('is_processed', false);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}
