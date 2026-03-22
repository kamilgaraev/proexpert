<?php

declare(strict_types=1);

namespace App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HoldingSiteLead extends Model
{
    protected $table = 'holding_site_leads';

    protected $fillable = [
        'holding_site_id',
        'holding_site_page_id',
        'block_key',
        'section_key',
        'locale_code',
        'contact_name',
        'company_name',
        'email',
        'phone',
        'message',
        'form_payload',
        'metadata',
        'utm_params',
        'source_page',
        'source_url',
        'status',
        'ip_address',
        'user_agent',
        'submitted_at',
    ];

    protected $casts = [
        'form_payload' => 'array',
        'metadata' => 'array',
        'utm_params' => 'array',
        'submitted_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(HoldingSite::class, 'holding_site_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(HoldingSitePage::class, 'holding_site_page_id');
    }
}
