<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\CommercialProposals\Models;

final class CommercialProposalTemplate extends CommercialProposalModel
{
    protected $fillable = [
        'organization_id',
        'code',
        'name',
        'description',
        'body_html',
        'settings',
        'version_hash',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'settings' => 'array',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'settings' => '{}',
        'is_default' => false,
        'is_active' => true,
    ];
}
