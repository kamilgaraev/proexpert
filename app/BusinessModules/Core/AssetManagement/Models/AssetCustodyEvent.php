<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\AssetManagement\Models;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class AssetCustodyEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'organization_asset_id',
        'actor_user_id',
        'event_type',
        'from_warehouse_id',
        'from_project_id',
        'from_user_id',
        'to_warehouse_id',
        'to_project_id',
        'to_user_id',
        'metadata',
        'occurred_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::updating(static function (): never {
            throw new LogicException('Asset custody events are append-only.');
        });
        self::deleting(static function (): never {
            throw new LogicException('Asset custody events are append-only.');
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(OrganizationAsset::class, 'organization_asset_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(OrganizationWarehouse::class, 'from_warehouse_id');
    }

    public function fromProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'from_project_id');
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(OrganizationWarehouse::class, 'to_warehouse_id');
    }

    public function toProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'to_project_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }
}
