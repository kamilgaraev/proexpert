<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContactForm extends Model
{
    use HasFactory;

    const STATUS_NEW = 'new';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
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
        'telegram_data',
        'is_processed',
        'processed_at',
    ];

    protected $casts = [
        'consent_to_personal_data' => 'boolean',
        'telegram_data' => 'array',
        'is_processed' => 'boolean',
        'processed_at' => 'datetime',
    ];

    public function markAsProcessed(): void
    {
        $this->update([
            'is_processed' => true,
            'processed_at' => now(),
            'status' => self::STATUS_PROCESSING,
        ]);
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
