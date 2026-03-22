<?php

declare(strict_types=1);

namespace App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HoldingSiteCollaborator extends Model
{
    public const ROLE_OWNER = 'owner';
    public const ROLE_EDITOR = 'editor';
    public const ROLE_PUBLISHER = 'publisher';
    public const ROLE_VIEWER = 'viewer';

    public const ROLES = [
        self::ROLE_OWNER,
        self::ROLE_EDITOR,
        self::ROLE_PUBLISHER,
        self::ROLE_VIEWER,
    ];

    protected $table = 'holding_site_collaborators';

    protected $fillable = [
        'holding_site_id',
        'user_id',
        'role',
        'invited_by_user_id',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(HoldingSite::class, 'holding_site_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }
}
