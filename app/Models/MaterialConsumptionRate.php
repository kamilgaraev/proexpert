<?php

declare(strict_types=1);

namespace App\Models;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class MaterialConsumptionRate extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const BASIS_GESN = 'gesn';

    public const BASIS_PROJECT = 'project';

    public const BASIS_TECH_CARD = 'tech_card';

    protected $fillable = [
        'organization_id',
        'project_id',
        'material_id',
        'work_type_id',
        'estimate_item_id',
        'work_unit_id',
        'material_unit_id',
        'quantity_per_work_unit',
        'version_number',
        'status',
        'basis_kind',
        'basis_text',
        'basis_document_id',
        'work_type_material_id',
        'effective_from',
        'effective_to',
        'idempotency_key',
        'payload_hash',
        'approved_at',
        'approved_by_user_id',
        'created_by_user_id',
    ];

    protected $casts = [
        'quantity_per_work_unit' => 'decimal:6',
        'version_number' => 'integer',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'approved_at' => 'datetime',
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

    public function workType(): BelongsTo
    {
        return $this->belongsTo(WorkType::class);
    }

    public function estimateItem(): BelongsTo
    {
        return $this->belongsTo(EstimateItem::class);
    }

    public function workUnit(): BelongsTo
    {
        return $this->belongsTo(MeasurementUnit::class, 'work_unit_id');
    }

    public function materialUnit(): BelongsTo
    {
        return $this->belongsTo(MeasurementUnit::class, 'material_unit_id');
    }

    public function basisDocument(): BelongsTo
    {
        return $this->belongsTo(ExecutiveDocument::class, 'basis_document_id');
    }

    public function facts(): HasMany
    {
        return $this->hasMany(MaterialConsumptionFact::class, 'rate_id');
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }
}
