<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectMaterialDeliveryEvent extends Model
{
    protected $fillable = [
        'project_material_delivery_id',
        'user_id',
        'event_type',
        'from_status',
        'to_status',
        'quantity',
        'notes',
        'metadata',
        'occurred_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(ProjectMaterialDelivery::class, 'project_material_delivery_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
