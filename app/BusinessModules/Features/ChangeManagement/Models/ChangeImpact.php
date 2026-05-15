<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ChangeImpact extends Model
{
    protected $table = 'change_management_impacts';

    protected $fillable = [
        'organization_id',
        'change_request_id',
        'cost_delta',
        'schedule_delta_days',
        'requires_contract_change',
        'requires_estimate_revision',
        'requires_procurement_update',
        'requires_customer_approval',
        'affected_schedule_task_ids',
        'affected_estimate_item_ids',
        'affected_contract_ids',
        'summary',
    ];

    protected $casts = [
        'cost_delta' => 'decimal:2',
        'schedule_delta_days' => 'integer',
        'requires_contract_change' => 'boolean',
        'requires_estimate_revision' => 'boolean',
        'requires_procurement_update' => 'boolean',
        'requires_customer_approval' => 'boolean',
        'affected_schedule_task_ids' => 'array',
        'affected_estimate_item_ids' => 'array',
        'affected_contract_ids' => 'array',
    ];

    public function changeRequest(): BelongsTo
    {
        return $this->belongsTo(ChangeRequest::class, 'change_request_id');
    }
}
