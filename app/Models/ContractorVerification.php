<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ContractorVerification extends Model
{
    protected $fillable = [
        'contractor_id',
        'registered_organization_id',
        'customer_organization_id',
        'status',
        'verification_token',
        'verification_score',
        'verification_data',
        'verified_at',
        'expires_at',
        'confirmed_by_user_id',
        'confirmed_at',
        'rejection_reason',
    ];

    protected $casts = [
        'verification_data' => 'array',
        'verified_at' => 'datetime',
        'expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (empty($model->verification_token)) {
                $model->verification_token = Str::random(64);
            }
        });
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function registeredOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'registered_organization_id');
    }

    public function customerOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'customer_organization_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending_customer_confirmation';
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending_customer_confirmation');
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }
}

