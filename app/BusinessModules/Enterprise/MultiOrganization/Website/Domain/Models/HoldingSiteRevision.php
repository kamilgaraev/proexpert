<?php

declare(strict_types=1);

namespace App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HoldingSiteRevision extends Model
{
    protected $table = 'holding_site_revisions';

    protected $fillable = [
        'holding_site_id',
        'kind',
        'label',
        'payload',
        'created_by_user_id',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(HoldingSite::class, 'holding_site_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
