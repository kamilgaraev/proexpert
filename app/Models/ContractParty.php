<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Contract\ContractPartyRoleEnum;
use App\Enums\Contract\ContractPartySideEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractParty extends Model
{
    use HasFactory;

    protected $fillable = [
        'contract_id',
        'side',
        'role',
        'counterparty_id',
        'linked_organization_id',
        'name',
        'legal_name',
        'inn',
        'kpp',
        'ogrn',
        'legal_address',
        'email',
        'phone',
        'snapshot',
    ];

    protected $casts = [
        'side' => ContractPartySideEnum::class,
        'role' => ContractPartyRoleEnum::class,
        'snapshot' => 'array',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class);
    }

    public function linkedOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'linked_organization_id');
    }
}
