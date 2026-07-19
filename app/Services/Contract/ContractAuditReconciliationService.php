<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Models\Contract;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class ContractAuditReconciliationService
{
    private const MAXIMUM_ATTEMPTS = 8;

    private const CLAIM_LEASE_SECONDS = 600;

    public function __construct(
        private ConnectionInterface $connection,
        private ContractAuditedMutationService $mutations,
        private ?Closure $afterMutation = null,
        private ?LoggerInterface $logger = null,
    ) {}

    public function recordDebt(
        ?Contract $contract,
        int $contractId,
        string $sourceType,
        string|int $sourceId,
        string $fingerprint,
        ?float $diagnosticExpectedTotal,
        Throwable $error,
        array $entityContext = [],
    ): void {
        $organizationId = $contract?->organization_id;
        if ($organizationId === null) {
            $organizationId = $this->connection->table('contracts')->where('id', $contractId)->value('organization_id');
        }
        $this->connection->table('contract_audit_reconciliation_debts')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'organization_id' => $organizationId === null ? null : (int) $organizationId,
            'contract_id' => $contractId,
            'source_type' => $sourceType,
            'source_id' => (string) $sourceId,
            'change_fingerprint' => $fingerprint,
            'expected_total_amount' => $diagnosticExpectedTotal === null ? null : number_format($diagnosticExpectedTotal, 4, '.', ''),
            'entity_context' => json_encode($entityContext, JSON_THROW_ON_ERROR),
            'last_error' => mb_substr($error->getMessage(), 0, 4000),
            'attempts' => 0,
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->logger?->error('contract.audit_reconciliation_debt.recorded', [
            'contract_id' => $contractId,
            'source_type' => $sourceType,
            'source_id' => (string) $sourceId,
            'error_class' => $error::class,
        ]);
    }

    public function reconcile(int $limit = 100): int
    {
        $claims = $this->claim(max(1, min($limit, 1000)));
        $resolved = 0;
        foreach ($claims as $id => $token) {
            try {
                $resolved += $this->processClaim($id, $token) ? 1 : 0;
            } catch (Throwable $error) {
                $this->markFailure($id, $token, $error);
            }
        }

        return $resolved;
    }

    /** @return array<string, string> */
    private function claim(int $limit): array
    {
        return $this->connection->transaction(function () use ($limit): array {
            $staleBefore = now()->subSeconds(self::CLAIM_LEASE_SECONDS);
            $query = $this->connection->table('contract_audit_reconciliation_debts')
                ->whereNull('resolved_at')->whereNull('dead_lettered_at')->where('available_at', '<=', now())
                ->where(function ($query) use ($staleBefore): void {
                    $query->whereNull('claim_token')->orWhereNull('claimed_at')->orWhere('claimed_at', '<=', $staleBefore);
                })->orderBy('created_at');
            $debts = ($this->connection->getDriverName() === 'pgsql' ? $query->lock('FOR UPDATE SKIP LOCKED') : $query->lockForUpdate())
                ->limit($limit)->get();
            $claims = [];
            foreach ($debts as $debt) {
                $token = (string) Str::uuid();
                $this->connection->table('contract_audit_reconciliation_debts')->where('id', $debt->id)->update([
                    'claim_token' => $token, 'claimed_at' => now(), 'updated_at' => now(),
                ]);
                $claims[(string) $debt->id] = $token;
            }

            return $claims;
        }, 3);
    }

    private function processClaim(string $id, string $token): bool
    {
        return $this->connection->transaction(function () use ($id, $token): bool {
            $debt = $this->connection->table('contract_audit_reconciliation_debts')
                ->where('id', $id)->where('claim_token', $token)->whereNull('resolved_at')->lockForUpdate()->first();
            if ($debt === null) {
                return false;
            }
            $contract = (new Contract)->setConnection($this->connection->getName())->newQuery()
                ->where('organization_id', $debt->organization_id)->whereKey($debt->contract_id)->lockForUpdate()->firstOrFail();
            $authoritativeTotal = $contract->recalculateTotalAmountForNonFixed();
            if ($authoritativeTotal === null) {
                $authoritativeTotal = (float) $contract->total_amount;
            }
            $version = hash('sha256', json_encode([
                'contract_id' => (int) $contract->id,
                'total_amount' => number_format($authoritativeTotal, 2, '.', ''),
                'acts_updated_at' => $contract->performanceActs()->max('updated_at'),
                'agreements_updated_at' => $contract->agreements()->withTrashed()->max('updated_at'),
            ], JSON_THROW_ON_ERROR));
            if (abs((float) $contract->total_amount - $authoritativeTotal) > 0.001) {
                $this->mutations->update($contract, ['total_amount' => number_format($authoritativeTotal, 4, '.', '')], 'audit_reconciliation', null, [
                    'source_event_id' => "reconciliation:contract:{$contract->id}:{$version}",
                    'reconciliation_debt_id' => $id,
                ]);
            }
            ($this->afterMutation)?->__invoke($contract, $debt);
            $this->connection->table('contract_audit_reconciliation_debts')->where('id', $id)->where('claim_token', $token)->update([
                'resolved_at' => now(), 'claim_token' => null, 'claimed_at' => null, 'last_error' => '', 'updated_at' => now(),
            ]);

            return true;
        }, 3);
    }

    private function markFailure(string $id, string $token, Throwable $error): void
    {
        $this->connection->transaction(function () use ($id, $token, $error): void {
            $debt = $this->connection->table('contract_audit_reconciliation_debts')->where('id', $id)->where('claim_token', $token)->lockForUpdate()->first();
            if ($debt === null) {
                return;
            }
            $attempts = ((int) $debt->attempts) + 1;
            $deadLettered = $attempts >= self::MAXIMUM_ATTEMPTS;
            $this->connection->table('contract_audit_reconciliation_debts')->where('id', $id)->update([
                'attempts' => $attempts,
                'last_error' => mb_substr($error->getMessage(), 0, 4000),
                'available_at' => now()->addSeconds(min(3600, 30 * (2 ** min($attempts, 7)))),
                'claim_token' => null,
                'claimed_at' => null,
                'dead_lettered_at' => $deadLettered ? now() : null,
                'updated_at' => now(),
            ]);
            $this->logger?->error('contract.audit_reconciliation_debt.failed', [
                'debt_id' => $id, 'attempts' => $attempts, 'dead_lettered' => $deadLettered, 'error_class' => $error::class,
            ]);
        }, 3);
    }
}
