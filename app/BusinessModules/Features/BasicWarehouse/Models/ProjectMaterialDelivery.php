<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Models;

use App\BusinessModules\Features\BasicWarehouse\Enums\ProjectMaterialDeliveryStatusEnum;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Models\JournalMaterial;
use App\Models\Material;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProjectMaterialDelivery extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'project_id',
        'material_id',
        'warehouse_id',
        'warehouse_project_allocation_id',
        'site_request_id',
        'purchase_request_id',
        'purchase_order_id',
        'outbound_movement_id',
        'inbound_movement_id',
        'source_type',
        'status',
        'requested_quantity',
        'reserved_quantity',
        'shipped_quantity',
        'accepted_quantity',
        'planned_delivery_date',
        'shipped_at',
        'delivered_at',
        'accepted_at',
        'responsible_user_id',
        'receiver_user_id',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'status' => ProjectMaterialDeliveryStatusEnum::class,
        'requested_quantity' => 'decimal:3',
        'reserved_quantity' => 'decimal:3',
        'shipped_quantity' => 'decimal:3',
        'accepted_quantity' => 'decimal:3',
        'planned_delivery_date' => 'date',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'accepted_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $attributes = [
        'source_type' => 'warehouse',
        'status' => 'reserved',
        'requested_quantity' => 0,
        'reserved_quantity' => 0,
        'shipped_quantity' => 0,
        'accepted_quantity' => 0,
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(OrganizationWarehouse::class, 'warehouse_id');
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(WarehouseProjectAllocation::class, 'warehouse_project_allocation_id');
    }

    public function siteRequest(): BelongsTo
    {
        return $this->belongsTo(SiteRequest::class);
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function outboundMovement(): BelongsTo
    {
        return $this->belongsTo(WarehouseMovement::class, 'outbound_movement_id');
    }

    public function inboundMovement(): BelongsTo
    {
        return $this->belongsTo(WarehouseMovement::class, 'inbound_movement_id');
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function receiverUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ProjectMaterialDeliveryEvent::class);
    }

    public function latestEvent(): HasOne
    {
        return $this->hasOne(ProjectMaterialDeliveryEvent::class)->latestOfMany('occurred_at');
    }

    public function journalMaterials(): HasMany
    {
        return $this->hasMany(JournalMaterial::class, 'project_material_delivery_id');
    }

    public function remainingQuantityToShip(): float
    {
        $expected = max(
            (float) $this->getAttribute('reserved_quantity'),
            (float) $this->getAttribute('requested_quantity')
        );

        return max(0.0, $expected - (float) $this->getAttribute('shipped_quantity'));
    }

    public function remainingQuantityToAccept(): float
    {
        return max(
            0.0,
            (float) $this->getAttribute('shipped_quantity') - (float) $this->getAttribute('accepted_quantity')
        );
    }

    public function canReceive(): bool
    {
        $status = $this->getAttribute('status');

        return $status instanceof ProjectMaterialDeliveryStatusEnum
            && $status->canBeReceived()
            && $this->remainingQuantityToAccept() > 0;
    }

    public function usedQuantity(): float
    {
        return (float) $this->journalMaterials()->sum('quantity');
    }

    public function availableQuantity(): float
    {
        return max(0.0, (float) $this->getAttribute('accepted_quantity') - $this->usedQuantity());
    }
}
