<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class CashGapOpeningBalance extends Model
{
    use HasUuids;
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'uuid',
        'organization_id',
        'balance_date',
        'currency',
        'amount',
        'status',
        'note',
        'created_by_user_id',
        'approved_by_user_id',
        'approved_at',
        'audit_trail',
        'metadata',
    ];

    protected $casts = [
        'balance_date' => 'date',
        'amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'audit_trail' => 'array',
        'metadata' => 'array',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }
}
