<?php

declare(strict_types=1);

namespace App\Models\Credits;

use Illuminate\Database\Eloquent\Model;

/** Allocation changes who pays a measured cost; the physical usage stays on its original reservation. */
final class AICreditProviderCostAllocation extends Model
{
    protected $table = 'ai_credit_provider_cost_allocations';

    protected $primaryKey = 'source_provider_usage_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['cost_micro_rub' => 'integer', 'source_pin' => 'array'];
}
