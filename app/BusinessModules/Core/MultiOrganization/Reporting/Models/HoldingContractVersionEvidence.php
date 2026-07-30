<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\MultiOrganization\Reporting\Models;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\Models\Contract;
use App\Models\ContractAllocationHistory;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class HoldingContractVersionEvidence extends Model
{
    public $timestamps = false;

    protected $table = 'holding_contract_version_evidence';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'allocation_history_id' => 'integer',
            'contract_id' => 'integer',
            'organization_id' => 'integer',
            'contractor_id' => 'integer',
            'counterparty_organization_id' => 'integer',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    public static function record(
        ContractAllocationHistory $history,
        Contract $contract,
    ): self {
        $payload = [
            'allocation_history_id' => (int) $history->getKey(),
            'contract_id' => (int) $contract->getKey(),
            'organization_id' => (int) $contract->organization_id,
            'total_amount' => (string) $contract->total_amount,
            'contractor_id' => $contract->contractor_id === null
                ? null
                : (int) $contract->contractor_id,
            'counterparty_organization_id' => $contract->contractor?->source_organization_id === null
                ? null
                : (int) $contract->contractor->source_organization_id,
            'recorded_at' => $history->created_at ?? now(),
        ];
        $sourceHash = hash('sha256', CanonicalJson::encode($payload));
        $record = self::query()->firstOrCreate(
            ['allocation_history_id' => $payload['allocation_history_id']],
            [...$payload, 'source_hash' => $sourceHash],
        );
        if (! hash_equals((string) $record->source_hash, $sourceHash)) {
            throw new InvalidArgumentException('holding_contract_version_evidence_conflict');
        }

        return $record;
    }
}
