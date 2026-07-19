<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Models\Contract;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Throwable;

final readonly class ContractAuditReconciliationService
{
    public function __construct(
        private ConnectionInterface $connection,
        private ContractAuditedMutationService $mutations,
    ) {}

    public function recordDebt(Contract $contract, string $sourceType, string|int $sourceId, string $fingerprint, float $expectedTotal, Throwable $error): void
    {
        $this->connection->table('contract_audit_reconciliation_debts')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'organization_id' => (int) $contract->organization_id,
            'contract_id' => (int) $contract->id,
            'source_type' => $sourceType,
            'source_id' => (string) $sourceId,
            'change_fingerprint' => $fingerprint,
            'expected_total_amount' => $expectedTotal,
            'last_error' => mb_substr($error->getMessage(), 0, 4000),
            'attempts' => 0,
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function reconcile(int $limit = 100): int
    {
        $resolved = 0;
        $debts = $this->connection->table('contract_audit_reconciliation_debts')
            ->whereNull('resolved_at')->where('available_at', '<=', now())->orderBy('created_at')->limit($limit)->get();

        foreach ($debts as $debt) {
            try {
                $contract = (new Contract)->newQuery()->where('organization_id', $debt->organization_id)->findOrFail($debt->contract_id);
                $this->mutations->update($contract, ['total_amount' => $debt->expected_total_amount], 'audit_reconciliation', null, [
                    'source_event_id' => "reconciliation:{$debt->id}:{$debt->change_fingerprint}",
                ]);
                $this->connection->table('contract_audit_reconciliation_debts')->where('id', $debt->id)->update(['resolved_at' => now(), 'updated_at' => now()]);
                $resolved++;
            } catch (Throwable $error) {
                $this->connection->table('contract_audit_reconciliation_debts')->where('id', $debt->id)->update([
                    'attempts' => ((int) $debt->attempts) + 1,
                    'last_error' => mb_substr($error->getMessage(), 0, 4000),
                    'available_at' => now()->addMinutes(5),
                    'updated_at' => now(),
                ]);
            }
        }

        return $resolved;
    }
}
