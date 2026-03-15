<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseIdentifier extends Model
{
    use HasFactory;

    public const TYPE_QR = 'qr';
    public const TYPE_BARCODE = 'barcode';
    public const TYPE_DATAMATRIX = 'datamatrix';
    public const TYPE_RFID = 'rfid';
    public const TYPE_NFC = 'nfc';
    public const TYPE_INTERNAL = 'internal';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUS_LOST = 'lost';
    public const STATUS_DAMAGED = 'damaged';

    protected $fillable = [
        'organization_id',
        'warehouse_id',
        'identifier_type',
        'code',
        'entity_type',
        'entity_id',
        'label',
        'status',
        'is_primary',
        'assigned_at',
        'last_scanned_at',
        'metadata',
        'notes',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'assigned_at' => 'datetime',
        'last_scanned_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(OrganizationWarehouse::class, 'warehouse_id');
    }
}
