<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\LegalArchive\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LegalDocumentNotificationDelivery extends Model
{
    protected $fillable = [
        'document_id',
        'recipient_user_id',
        'delivery_key',
        'notification_id',
        'notification_type',
        'notification_payload',
        'status',
        'attempt_count',
        'lease_expires_at',
        'lease_token',
        'delivered_at',
    ];

    protected $casts = [
        'notification_payload' => 'array',
        'lease_expires_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalArchiveDocument::class, 'document_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
