<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Services;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\ChangeManagement\Models\ChangeRequest;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\DTO\ContingencyMovement;
use App\BusinessModules\Features\ChangeManagement\Reporting\ChangeClaim\Models\ContingencyLedgerEntry;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class ContingencyLedgerService
{
    public function append(
        ChangeRequest $change,
        ContingencyMovement $movement,
        ?CarbonInterface $effectiveAt = null,
    ): ContingencyLedgerEntry {
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
            if (! $approved) {
                throw new DomainException('contingency_consumption_requires_approved_change');
            }
        }
        $occurredAt = $effectiveAt ?? now();
        $payload = [
            'organization_id' => (int) $change->organization_id,
            'project_id' => $movement->projectId,
            'contract_project_allocation_id' => $movement->allocationId,
            'currency' => $movement->currency,
            'currency_source' => 'change_request_version',
            'movement_type' => $movement->type,
            'signed_amount_minor' => $movement->signedMinor(),
            'effective_on' => $occurredAt->toDateString(),
            'effective_at' => $occurredAt->toAtomString(),
            'source_type' => $movement->sourceType,
            'source_id' => $movement->sourceId,
            'source_version' => $movement->sourceVersion,
            'idempotency_key' => $movement->idempotencyKey,
        ];

        $entryHash = hash('sha256', CanonicalJson::encode($payload));

        return DB::transaction(function () use ($change, $movement, $payload, $entryHash): ContingencyLedgerEntry {
            $allocation = DB::table('contract_project_allocations')
                ->where('id', $movement->allocationId)
                ->lockForUpdate()
                ->first();
            if ($allocation === null) {
                throw new DomainException('contingency_movement_allocation_missing');
            }
            $existing = ContingencyLedgerEntry::query()
                ->where('organization_id', $change->organization_id)
                ->where('idempotency_key', $movement->idempotencyKey)
                ->first();
            if ($existing instanceof ContingencyLedgerEntry) {
                if (! hash_equals((string) $existing->entry_hash, $entryHash)) {
                    throw new DomainException('contingency_ledger_replay_conflict');
                }

                return $existing;
            }
            $ledger = ContingencyLedgerEntry::query()
                ->where('organization_id', $change->organization_id)
                ->where('project_id', $movement->projectId)
                ->where('contract_project_allocation_id', $movement->allocationId)
                ->where('currency', $movement->currency)
                ->orderBy('effective_at')
                ->orderBy('id')
                ->get();
            $openingCount = $ledger->where('movement_type', 'opening')->count();
            if (($movement->type === 'opening' && $openingCount !== 0)
                || ($movement->type !== 'opening' && $openingCount !== 1)) {
                throw new DomainException('contingency_ledger_opening_cardinality_invalid');
            }
            $balance = (int) $ledger->sum('signed_amount_minor');
            if ($balance + $movement->signedMinor() < 0) {
                throw new DomainException('contingency_ledger_balance_negative');
            }
            ContingencyLedgerEntry::query()->insertOrIgnore([[
                ...$payload,
                'entry_hash' => $entryHash,
                'created_at' => now(),
                'updated_at' => now(),
            ]]);
            $entry = ContingencyLedgerEntry::query()
                ->where('organization_id', $change->organization_id)
                ->where('idempotency_key', $movement->idempotencyKey)
                ->lockForUpdate()
                ->first();
            if (! $entry instanceof ContingencyLedgerEntry
                || ! hash_equals((string) $entry->entry_hash, $entryHash)) {
                throw new DomainException('contingency_ledger_replay_conflict');
            }

            return $entry;
        });
    }
}
