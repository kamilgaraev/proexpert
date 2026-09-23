<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Models;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ChangeManagementRfiHistory extends Model
{
    protected $table = 'change_management_rfi_history';

    protected $fillable = [
        'rfi_id',
        'actor_user_id',
        'actor_organization_id',
        'event',
        'from_status',
        'to_status',
        'message',
        'attachments',
        'metadata',
    ];

    protected $casts = [
        'attachments' => 'array',
        'metadata' => 'array',
    ];

    public function rfi(): BelongsTo
    {
        return $this->belongsTo(ChangeManagementRfi::class, 'rfi_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function actorOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'actor_organization_id');
    }
}
