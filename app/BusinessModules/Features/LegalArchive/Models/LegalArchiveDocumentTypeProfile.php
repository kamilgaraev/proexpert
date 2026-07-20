<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\LegalArchive\Models;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class LegalArchiveDocumentTypeProfile extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id',
        'code',
        'base_code',
        'name',
        'schema',
        'required_fields',
        'required_file_roles',
        'requires_signature',
        'allowed_signature_kinds',
        'required_signature_kinds',
        'workflow_template_id',
        'retention_policy',
        'confidentiality_level',
        'is_active',
        'lock_version',
    ];

    protected $casts = [
        'schema' => 'array',
        'required_fields' => 'array',
        'required_file_roles' => 'array',
        'requires_signature' => 'boolean',
        'allowed_signature_kinds' => 'array',
        'required_signature_kinds' => 'array',
        'is_active' => 'boolean',
        'lock_version' => 'integer',
    ];

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
