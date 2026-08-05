<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement\Reporting;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\ContractManagement\Reporting\Models\ContractSettlementOwnerHistoryCheckpoint;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\ContractProjectAllocation;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final readonly class ContractSettlementOwnerHistoryBackfillService
{
    public function __construct(private ContractSettlementOwnerVersionRecorder $recorder) {}

    public function backfill(int $organizationId): ContractSettlementOwnerHistoryCheckpoint
    {
        if ($organizationId < 1) {
            throw new DomainException('contract_settlement_owner_organization_missing');
        }

        return DB::transaction(function () use ($organizationId): ContractSettlementOwnerHistoryCheckpoint {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement(
                    'LOCK TABLE contracts, contract_project_allocations, contract_performance_acts, '
                    .'payment_documents, payment_transactions IN SHARE ROW EXCLUSIVE MODE',
                );
            }
            $organization = DB::table('organizations')
                ->where('id', $organizationId)
                ->lockForUpdate()
                ->first();
            if ($organization === null) {
                throw new DomainException('contract_settlement_owner_organization_missing');
            }
            $existing = ContractSettlementOwnerHistoryCheckpoint::query()
                ->where('organization_id', $organizationId)
                ->first();
            if ($existing instanceof ContractSettlementOwnerHistoryCheckpoint) {
                return $existing;
            }

            $completedAt = now();
            $queries = [
                'contract' => Contract::query()->where('organization_id', $organizationId),
                'contract_allocation' => ContractProjectAllocation::query()
                    ->whereHas('contract', static fn (Builder $query): Builder => $query
                        ->where('organization_id', $organizationId)),
                'contract_performance_act' => ContractPerformanceAct::query()
                    ->whereHas('contract', static fn (Builder $query): Builder => $query
                        ->where('organization_id', $organizationId)),
                'payment_document' => PaymentDocument::query()->where('organization_id', $organizationId),
                'payment_transaction' => PaymentTransaction::query()
                    ->whereHas('paymentDocument', static fn (Builder $query): Builder => $query
                        ->where('organization_id', $organizationId)),
            ];
            $counts = [];
            $identities = [];
            foreach ($queries as $type => $query) {
                $counts[$type] = 0;
                $query->orderBy('id')->chunkById(500, function ($owners) use (
                    $type,
                    $completedAt,
                    &$counts,
                    &$identities,
                ): void {
                    foreach ($owners as $owner) {
                        if (! $owner instanceof Model) {
                            throw new DomainException('contract_settlement_owner_history_invalid');
                        }
                        $version = $this->recorder->record($owner, 'upsert', $completedAt);
                        $counts[$type]++;
                        $identities[] = [
                            'type' => $type,
                            'id' => (string) $owner->getKey(),
                            'version' => (int) $version->version,
                            'hash' => (string) $version->owner_hash,
                        ];
                    }
                });
            }

            return ContractSettlementOwnerHistoryCheckpoint::query()->create([
                'organization_id' => $organizationId,
                'completed_at' => ContractSettlementOwnerTimestamp::database($completedAt),
                'owner_counts' => $counts,
                'source_hash' => hash('sha256', CanonicalJson::encode([
                    'organization_id' => $organizationId,
                    'completed_at' => ContractSettlementOwnerTimestamp::canonical($completedAt),
                    'owners' => $identities,
                ])),
            ]);
        });
    }
}
