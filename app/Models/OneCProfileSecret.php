<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class OneCProfileSecret extends Model
{
    protected $table = 'one_c_profile_secrets';

    protected $hidden = [
        'secret_value_encrypted',
        'username_encrypted',
        'password_encrypted',
    ];

    protected $fillable = [
        'organization_id',
        'one_c_integration_profile_id',
        'type',
        'label',
        'secret_value_encrypted',
        'username_encrypted',
        'password_encrypted',
        'fingerprint',
        'status',
        'last_used_at',
        'rotated_at',
        'revoked_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'secret_value_encrypted' => 'encrypted',
        'username_encrypted' => 'encrypted',
        'password_encrypted' => 'encrypted',
        'last_used_at' => 'datetime',
        'rotated_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(OneCIntegrationProfile::class, 'one_c_integration_profile_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('status', 'active')
            ->whereNull('revoked_at');
    }

    public function secretValue(): ?string
    {
        $value = $this->secret_value_encrypted;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function username(): ?string
    {
        $value = $this->username_encrypted;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function password(): ?string
    {
        $value = $this->password_encrypted;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
