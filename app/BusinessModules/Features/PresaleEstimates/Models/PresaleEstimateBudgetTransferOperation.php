<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\PresaleEstimates\Models;

use App\BusinessModules\Features\Budgeting\Models\BudgetVersion;
use App\Models\Contract;
use App\Models\Project;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PresaleEstimateBudgetTransferOperation extends PresaleEstimateModel
{
    protected $fillable = [
        'organization_id',
        'source_type',
        'source_id',
        'presale_estimate_id',
        'presale_estimate_version_id',
        'project_id',
        'contract_id',
        'budget_version_id',
        'idempotency_key',
        'payload_hash',
        'preview_hash',
        'status',
        'result_snapshot',
        'error_code',
        'error_message',
        'created_by_user_id',
        'completed_at',
    ];

    protected $casts = [
        'result_snapshot' => 'array',
        'completed_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'started',
        'result_snapshot' => '{}',
    ];

    public function presaleEstimate(): BelongsTo
    {
        return $this->belongsTo(PresaleEstimate::class, 'presale_estimate_id');
    }

    public function presaleEstimateVersion(): BelongsTo
    {
        return $this->belongsTo(PresaleEstimateVersion::class, 'presale_estimate_version_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function budgetVersion(): BelongsTo
    {
        return $this->belongsTo(BudgetVersion::class, 'budget_version_id');
    }
}
