<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MdmChangeRequestEvent extends Model
{
    protected $fillable = [
        'organization_id',
        'change_request_id',
        'event_type',
        'actor_user_id',
        'before_status',
        'after_status',
        'comment',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function changeRequest(): BelongsTo
    {
        return $this->belongsTo(MdmChangeRequest::class, 'change_request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
