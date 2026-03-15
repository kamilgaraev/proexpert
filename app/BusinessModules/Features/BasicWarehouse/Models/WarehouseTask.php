<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Models;

use App\Models\Material;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseTask extends Model
{
    use HasFactory;

    public const TYPE_RECEIPT = 'receipt';
    public const TYPE_PLACEMENT = 'placement';
    public const TYPE_TRANSFER = 'transfer';
    public const TYPE_PICKING = 'picking';
    public const TYPE_CYCLE_COUNT = 'cycle_count';
    public const TYPE_ISSUE = 'issue';
    public const TYPE_RETURN = 'return';
    public const TYPE_RELABEL = 'relabel';
    public const TYPE_INSPECTION = 'inspection';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_CRITICAL = 'critical';

    protected $fillable = [
        'organization_id',
        'warehouse_id',
        'zone_id',
        'cell_id',
        'logistic_unit_id',
        'material_id',
        'project_id',
        'inventory_act_id',
        'movement_id',
        'assigned_to_id',
        'created_by_id',
        'completed_by_id',
        'task_number',
        'title',
        'task_type',
        'status',
        'priority',
        'planned_quantity',
        'completed_quantity',
        'due_at',
        'started_at',
        'completed_at',
        'source_document_type',
        'source_document_id',
        'metadata',
        'notes',
    ];

    protected $casts = [
        'planned_quantity' => 'decimal:3',
        'completed_quantity' => 'decimal:3',
        'due_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(OrganizationWarehouse::class, 'warehouse_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(WarehouseZone::class, 'zone_id');
    }

    public function cell(): BelongsTo
    {
        return $this->belongsTo(WarehouseStorageCell::class, 'cell_id');
    }

    public function logisticUnit(): BelongsTo
    {
        return $this->belongsTo(WarehouseLogisticUnit::class, 'logistic_unit_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'material_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function inventoryAct(): BelongsTo
    {
        return $this->belongsTo(InventoryAct::class, 'inventory_act_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(WarehouseMovement::class, 'movement_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_id');
    }
}
