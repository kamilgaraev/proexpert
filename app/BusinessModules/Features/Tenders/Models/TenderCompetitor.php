<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Tenders\Models;

use App\BusinessModules\Features\Crm\Models\CrmCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TenderCompetitor extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'tender_id',
        'crm_company_id',
        'name',
        'inn',
        'kpp',
        'bid_amount',
        'score',
        'rank',
        'is_winner',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'bid_amount' => 'decimal:2',
        'score' => 'decimal:2',
        'is_winner' => 'boolean',
        'metadata' => 'array',
    ];

    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class);
    }

    public function crmCompany(): BelongsTo
    {
        return $this->belongsTo(CrmCompany::class, 'crm_company_id');
    }
}
