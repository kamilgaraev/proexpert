<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\PresaleEstimates\Models;

use App\BusinessModules\Features\Budgeting\Models\BudgetArticle;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PresaleEstimateLineItem extends PresaleEstimateModel
{
    protected $fillable = [
        'organization_id',
        'presale_estimate_id',
        'presale_estimate_version_id',
        'presale_estimate_section_id',
        'budget_article_id',
        'responsibility_center_id',
        'planned_month',
        'line_type',
        'title',
        'description',
        'unit',
        'quantity',
        'unit_cost',
        'discount_amount',
        'vat_rate',
        'subtotal_amount',
        'total_amount',
        'sort_order',
        'metadata',
    ];

    protected $casts = [
        'planned_month' => 'date',
        'quantity' => 'decimal:4',
        'unit_cost' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'subtotal_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'sort_order' => 'integer',
        'metadata' => 'array',
    ];

    protected $attributes = [
        'line_type' => 'work',
        'quantity' => 1,
        'unit_cost' => 0,
        'discount_amount' => 0,
        'subtotal_amount' => 0,
        'total_amount' => 0,
        'metadata' => '{}',
    ];

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(PresaleEstimate::class, 'presale_estimate_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(PresaleEstimateVersion::class, 'presale_estimate_version_id');
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(PresaleEstimateSection::class, 'presale_estimate_section_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(BudgetArticle::class, 'budget_article_id');
    }

    public function responsibilityCenter(): BelongsTo
    {
        return $this->belongsTo(ResponsibilityCenter::class, 'responsibility_center_id');
    }
}
