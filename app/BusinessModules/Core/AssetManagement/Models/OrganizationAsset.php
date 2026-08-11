<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\AssetManagement\Models;

use App\BusinessModules\Core\AssetManagement\Enums\AssetAccountingMode;
use App\BusinessModules\Core\AssetManagement\Enums\AssetLifecycleStatus;
use App\BusinessModules\Core\AssetManagement\Enums\AssetTechnicalStatus;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\Models\Machinery;
use App\Models\Material;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

final class OrganizationAsset extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'material_id',
        'machinery_id',
        'name',
        'inventory_number',
        'serial_number',
        'qr_code',
        'accounting_mode',
        'ownership_type',
        'lifecycle_status',
        'technical_status',
        'current_warehouse_id',
        'current_project_id',
        'responsible_user_id',
        'metadata',
    ];

    protected $casts = [
        'accounting_mode' => AssetAccountingMode::class,
        'lifecycle_status' => AssetLifecycleStatus::class,
        'technical_status' => AssetTechnicalStatus::class,
        'metadata' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function machinery(): BelongsTo
    {
        return $this->belongsTo(Machinery::class);
    }

    public function currentWarehouse(): BelongsTo
    {
        return $this->belongsTo(OrganizationWarehouse::class, 'current_warehouse_id');
    }

    public function currentProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'current_project_id');
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function operationProfile(): HasOne
    {
        return $this->hasOne(AssetOperationProfile::class, 'organization_asset_id');
    }

    public function custodyEvents(): HasMany
    {
        return $this->hasMany(AssetCustodyEvent::class, 'organization_asset_id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
