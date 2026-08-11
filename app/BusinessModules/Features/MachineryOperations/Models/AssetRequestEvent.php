<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\MachineryOperations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AssetRequestEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['organization_id', 'actor_user_id', 'event_type', 'payload', 'occurred_at'];

    protected $casts = ['payload' => 'array', 'occurred_at' => 'datetime'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(AssetRequest::class, 'asset_request_id');
    }
}
