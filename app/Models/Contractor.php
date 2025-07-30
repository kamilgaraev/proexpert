<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Contractor extends Model
{
    use HasFactory, SoftDeletes;

    const TYPE_MANUAL = 'manual';
    const TYPE_INVITED_ORGANIZATION = 'invited_organization';

    protected $fillable = [
        'organization_id',
        'source_organization_id',
        'name',
        'contact_person',
        'phone',
        'email',
        'legal_address',
        'inn',
        'kpp',
        'bank_details',
        'notes',
        'contractor_type',
        'contractor_invitation_id',
        'connected_at',
        'sync_settings',
        'last_sync_at',
    ];

    protected $casts = [
        'connected_at' => 'datetime',
        'last_sync_at' => 'datetime',
        'sync_settings' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function sourceOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'source_organization_id');
    }

    public function contractorInvitation(): BelongsTo
    {
        return $this->belongsTo(ContractorInvitation::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function scopeManual($query)
    {
        return $query->where('contractor_type', self::TYPE_MANUAL);
    }

    public function scopeInvitedOrganizations($query)
    {
        return $query->where('contractor_type', self::TYPE_INVITED_ORGANIZATION);
    }

    public function scopeNeedingSync($query)
    {
        return $query->where('contractor_type', self::TYPE_INVITED_ORGANIZATION)
                    ->where(function($q) {
                        $q->whereNull('last_sync_at')
                          ->orWhere('last_sync_at', '<', now()->subHours(24));
                    });
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('contractor_type', $type);
    }

    public function isInvitedOrganization(): bool
    {
        return $this->contractor_type === self::TYPE_INVITED_ORGANIZATION;
    }

    public function isManual(): bool
    {
        return $this->contractor_type === self::TYPE_MANUAL;
    }

    public function needsSync(): bool
    {
        if (!$this->isInvitedOrganization()) {
            return false;
        }

        if (is_null($this->last_sync_at)) {
            return true;
        }

        $syncInterval = $this->sync_settings['sync_interval_hours'] ?? 24;
        return $this->last_sync_at->addHours($syncInterval)->isPast();
    }

    public function syncFromSourceOrganization(): bool
    {
        if (!$this->isInvitedOrganization() || !$this->sourceOrganization) {
            return false;
        }

        $sourceOrg = $this->sourceOrganization;
        $syncSettings = $this->sync_settings ?? [];
        
        $fieldsToSync = $syncSettings['sync_fields'] ?? [
            'name', 'phone', 'email', 'legal_address'
        ];

        $updated = false;
        foreach ($fieldsToSync as $field) {
            if (isset($sourceOrg->$field) && $this->$field !== $sourceOrg->$field) {
                $this->$field = $sourceOrg->$field;
                $updated = true;
            }
        }

        if ($updated) {
            $this->last_sync_at = now();
            $this->save();
        }

        return $updated;
    }

    public function getCacheKey(string $suffix = ''): string
    {
        return "contractor:{$this->id}" . ($suffix ? ":{$suffix}" : '');
    }

    public function clearCache(): void
    {
        Cache::forget($this->getCacheKey('details'));
        Cache::forget($this->getCacheKey('contracts'));
    }

    protected static function boot()
    {
        parent::boot();
        
        static::saved(function ($contractor) {
            $contractor->clearCache();
        });
        
        static::deleted(function ($contractor) {
            $contractor->clearCache();
        });
    }
} 