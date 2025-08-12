<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDashboardSetting extends Model
{
    protected $table = 'user_dashboard_settings';

    protected $fillable = [
        'user_id',
        'organization_id',
        'version',
        'layout_mode',
        'items',
    ];

    protected $casts = [
        'version' => 'integer',
        'organization_id' => 'integer',
        'items' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}


