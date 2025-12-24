<?php

namespace App\Models;

use App\Enums\ContractorType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Contractor extends Model
{
    use HasFactory, SoftDeletes;

    // Для обратной совместимости со старым кодом
    const TYPE_MANUAL = 'manual';
    const TYPE_INVITED_ORGANIZATION = 'invited_organization';
    const TYPE_HOLDING_MEMBER = 'holding_member';
    const TYPE_SELF_EXECUTION = 'self_execution';

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
        'contractor_type' => ContractorType::class,
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
        return $query->where('contractor_type', ContractorType::MANUAL->value);
    }

    public function scopeInvitedOrganizations($query)
    {
        return $query->where('contractor_type', ContractorType::INVITED_ORGANIZATION->value);
    }

    public function scopeHoldingMembers($query)
    {
        return $query->where('contractor_type', ContractorType::HOLDING_MEMBER->value);
    }

    public function scopeSelfExecution($query)
    {
        return $query->where('contractor_type', ContractorType::SELF_EXECUTION->value);
    }

    public function scopeNeedingSync($query)
    {
        return $query->whereIn('contractor_type', [
                        ContractorType::INVITED_ORGANIZATION->value,
                        ContractorType::HOLDING_MEMBER->value,
                    ])
                    ->where(function($q) {
                        $q->whereNull('last_sync_at')
                          ->orWhere('last_sync_at', '<', now()->subHours(24));
                    });
    }

    public function scopeByType($query, ContractorType|string $type)
    {
        $value = $type instanceof ContractorType ? $type->value : $type;
        return $query->where('contractor_type', $value);
    }

    public function isInvitedOrganization(): bool
    {
        return $this->contractor_type === ContractorType::INVITED_ORGANIZATION;
    }

    public function isManual(): bool
    {
        return $this->contractor_type === ContractorType::MANUAL;
    }

    public function isHoldingMember(): bool
    {
        return $this->contractor_type === ContractorType::HOLDING_MEMBER;
    }

    /**
     * Проверяет, является ли подрядчик записью самоподряда (собственные силы)
     */
    public function isSelfExecution(): bool
    {
        return $this->contractor_type === ContractorType::SELF_EXECUTION;
    }

    /**
     * Проверяет, является ли подрядчик частью холдинга (головная или дочерняя организация)
     */
    public function isPartOfHolding(): bool
    {
        return $this->contractor_type === ContractorType::HOLDING_MEMBER;
    }

    /**
     * Проверяет, можно ли редактировать данные подрядчика
     */
    public function isEditable(): bool
    {
        return $this->contractor_type?->isEditable() ?? true;
    }

    /**
     * Проверяет, можно ли удалить подрядчика
     */
    public function isDeletable(): bool
    {
        return $this->contractor_type?->isDeletable() ?? true;
    }

    public function needsSync(): bool
    {
        // Проверяем, требуется ли автосинхронизация для этого типа
        if (!$this->contractor_type?->needsAutoSync()) {
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
        // Синхронизация работает для invited_organization и holding_member
        if (!$this->contractor_type?->needsAutoSync() || !$this->sourceOrganization) {
            return false;
        }

        $sourceOrg = $this->sourceOrganization;
        $syncSettings = $this->sync_settings ?? [];
        
        $fieldsToSync = $syncSettings['sync_fields'] ?? [
            'name', 'phone', 'email', 'legal_address', 'inn', 'kpp'
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

            \Illuminate\Support\Facades\Log::info('Contractor synced from source organization', [
                'contractor_id' => $this->id,
                'source_org_id' => $this->source_organization_id,
                'target_org_id' => $this->organization_id,
                'fields_synced' => $fieldsToSync,
            ]);
        }

        return $updated;
    }

    public static function syncFromParentOrganization(int $childOrgId, int $parentOrgId): array
    {
        $parentContractors = static::where('organization_id', $parentOrgId)
            ->whereNull('source_organization_id')
            ->get();

        $synced = 0;
        $created = 0;
        $errors = [];

        foreach ($parentContractors as $parentContractor) {
            try {
                $existing = static::where('organization_id', $childOrgId)
                    ->where('source_organization_id', $parentOrgId)
                    ->where('inn', $parentContractor->inn)
                    ->first();

                if ($existing) {
                    if ($existing->syncFromSourceOrganization()) {
                        $synced++;
                    }
                } else {
                    static::create([
                        'organization_id' => $childOrgId,
                        'source_organization_id' => $parentOrgId,
                        'contractor_type' => ContractorType::INVITED_ORGANIZATION,
                        'name' => $parentContractor->name,
                        'phone' => $parentContractor->phone,
                        'email' => $parentContractor->email,
                        'legal_address' => $parentContractor->legal_address,
                        'inn' => $parentContractor->inn,
                        'kpp' => $parentContractor->kpp,
                        'bank_details' => $parentContractor->bank_details,
                        'connected_at' => now(),
                        'last_sync_at' => now(),
                        'sync_settings' => ['sync_fields' => ['name', 'phone', 'email', 'legal_address', 'inn', 'kpp']],
                    ]);
                    $created++;
                }
            } catch (\Exception $e) {
                $errors[] = [
                    'contractor_id' => $parentContractor->id,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'synced' => $synced,
            'created' => $created,
            'total' => $parentContractors->count(),
            'errors' => $errors,
        ];
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

    /**
     * Получить или создать подрядчика самоподряда для организации
     * 
     * @param int $organizationId ID организации
     * @return Contractor
     */
    public static function getOrCreateSelfExecution(int $organizationId): Contractor
    {
        $contractor = static::where('organization_id', $organizationId)
            ->where('contractor_type', ContractorType::SELF_EXECUTION->value)
            ->first();

        if ($contractor) {
            return $contractor;
        }

        // Получаем данные организации для заполнения полей
        $organization = Organization::find($organizationId);

        if (!$organization) {
            throw new \Exception("Организация с ID {$organizationId} не найдена");
        }

        return static::create([
            'organization_id' => $organizationId,
            'source_organization_id' => $organizationId,
            'name' => 'Собственные силы',
            'contact_person' => null,
            'phone' => $organization->phone,
            'email' => $organization->email,
            'legal_address' => $organization->address,
            'inn' => $organization->tax_number,
            'kpp' => null,
            'bank_details' => null,
            'notes' => 'Автоматически созданный подрядчик для учета работ собственными силами (хозяйственный способ)',
            'contractor_type' => ContractorType::SELF_EXECUTION,
            'connected_at' => now(),
            'last_sync_at' => now(),
        ]);
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