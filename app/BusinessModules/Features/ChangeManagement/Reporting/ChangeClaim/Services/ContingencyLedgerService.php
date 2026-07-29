<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Services;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeRequest;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\DTO\ContingencyMovement;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Models\ContingencyLedgerEntry;
use DomainException;

final readonly class ContingencyLedgerService
{
    public function append(ChangeRequest $change, ContingencyMovement $movement): ContingencyLedgerEntry
    {
        if ($movement->projectId !== (int) $change->project_id
            || $movement->allocationId === null
            || $movement->sourceType === null
            || $movement->sourceId === null
            || $movement->sourceVersion === null
            || $movement->idempotencyKey === null) {
            throw new DomainException('contingency_movement_source_invalid');
        }
        if ($movement->type === 'consumption') {
            $approved = $change->approved_at !== null
                && (int) $movement->sourceId === (int) $change->id;
            if (!$approved) {
                throw new DomainException('contingency_consumption_requires_approved_change');
            }
        }
        $payload = [
            'organization_id' => (int) $change->organization_id,
            'project_id' => $movement->projectId,
            'contract_project_allocation_id' => $movement->allocationId,
            'currency' => $movement->currency,
            'currency_source' => 'change_request_version',
            'movement_type' => $movement->type,
            'signed_amount_minor' => $movement->signedMinor(),
            'effective_on' => now()->toDateString(),
            'source_type' => $movement->sourceType,
            'source_id' => $movement->sourceId,
            'source_version' => $movement->sourceVersion,
            'idempotency_key' => $movement->idempotencyKey,
        ];

        return ContingencyLedgerEntry::query()->create([
            ...$payload,
            'entry_hash' => hash('sha256', CanonicalJson::encode($payload)),
        ]);
    }
}
