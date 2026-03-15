<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseScanEvent extends Model
{
    use HasFactory;

    public const SOURCE_ADMIN = 'admin';
    public const SOURCE_MOBILE = 'mobile';
    public const SOURCE_TSD = 'tsd';
    public const SOURCE_RFID_GATE = 'rfid_gate';
    public const SOURCE_API = 'api';

    public const RESULT_RESOLVED = 'resolved';
    public const RESULT_NOT_FOUND = 'not_found';
    public const RESULT_ERROR = 'error';

    protected $fillable = [
        'organization_id',
        'warehouse_id',
        'identifier_id',
        'logistic_unit_id',
        'scanned_by_id',
        'code',
        'source',
        'result',
        'entity_type',
        'entity_id',
        'scan_context',
        'metadata',
        'notes',
        'scanned_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'scanned_at' => 'datetime',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(OrganizationWarehouse::class, 'warehouse_id');
    }

    public function identifier(): BelongsTo
    {
        return $this->belongsTo(WarehouseIdentifier::class, 'identifier_id');
    }

    public function logisticUnit(): BelongsTo
    {
        return $this->belongsTo(WarehouseLogisticUnit::class, 'logistic_unit_id');
    }
}
