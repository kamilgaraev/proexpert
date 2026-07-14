<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class OrganizationPackageTrialUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'package_slug',
        'started_at',
        'ends_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Package trial usage history is immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Package trial usage history is immutable.');
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
