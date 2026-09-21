<?php

declare(strict_types=1);

namespace App\Models;

use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MaterialConsumptionFact extends Model
{
    public const KIND_CONSUMPTION = 'consumption';

    public const KIND_RETURN = 'return';

    protected $fillable = [
        'organization_id',
        'project_id',
        'completed_work_id',
        'material_id',
        'rate_id',
        'kind',
        'quantity',
        'unit_id',
        'converted_quantity',
        'conversion_basis',
        'warehouse_movement_id',
        'batch_number',
        'quality_document_id',
        'site_remainder_quantity',
        'occurred_on',
        'deviation_reason',
        'agreed_by_user_id',
        'agreed_at',
        'operation_key',
        'payload_hash',
        'created_by_user_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:6',
        'converted_quantity' => 'decimal:6',
        'conversion_basis' => 'array',
        'site_remainder_quantity' => 'decimal:6',
        'occurred_on' => 'date',
        'agreed_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function completedWork(): BelongsTo
    {
        return $this->belongsTo(CompletedWork::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function rate(): BelongsTo
    {
        return $this->belongsTo(MaterialConsumptionRate::class, 'rate_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(MeasurementUnit::class, 'unit_id');
    }

    public function warehouseMovement(): BelongsTo
    {
        return $this->belongsTo(WarehouseMovement::class, 'warehouse_movement_id');
    }

    public function qualityDocument(): BelongsTo
    {
        return $this->belongsTo(ExecutiveDocument::class, 'quality_document_id');
    }

    public function isReturn(): bool
    {
        return $this->kind === self::KIND_RETURN;
    }
}
