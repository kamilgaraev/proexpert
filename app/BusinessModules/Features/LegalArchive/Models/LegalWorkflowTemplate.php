<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\LegalArchive\Models;

use App\Exceptions\ImmutableDataException;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LegalWorkflowTemplate extends Model
{
    protected $fillable = [
        'organization_id', 'code', 'version', 'name', 'definition_hash', 'created_by_user_id',
    ];

    protected $casts = ['version' => 'integer'];

    protected static function booted(): void
    {
        self::updating(static fn (self $template): never => throw new ImmutableDataException(self::class, 'update'));
        self::deleting(static fn (self $template): never => throw new ImmutableDataException(self::class, 'delete'));
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(LegalWorkflowTemplateStep::class, 'template_id')
            ->orderBy('sequence')->orderBy('step_key');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
