<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ChangeApproval extends Model
{
    protected $table = 'change_management_approvals';

    protected $fillable = [
        'organization_id',
        'change_request_id',
        'approved_by_user_id',
        'approval_type',
        'status',
        'comment',
        'decided_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public function changeRequest(): BelongsTo
    {
        return $this->belongsTo(ChangeRequest::class, 'change_request_id');
    }
}
