<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ContractManagement\Reporting;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\ContractManagement\Reporting\Models\ContractSettlementOwnerVersion;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\ContractProjectAllocation;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final readonly class ContractSettlementOwnerVersionRecorder
{
    private const TYPES = [
        Contract::class => 'contract',
        ContractProjectAllocation::class => 'contract_allocation',
        ContractPerformanceAct::class => 'contract_performance_act',
        PaymentDocument::class => 'payment_document',
        PaymentTransaction::class => 'payment_transaction',
    ];

    public function record(Model $owner, string $operation): void
    {
        $ownerType = self::TYPES[$owner::class] ?? throw new DomainException('contract_settlement_owner_type_invalid');
        $organizationId = $this->organizationId($owner);
        $payload = $owner->attributesToArray();
        $occurredAt = $owner->updated_at ?? now();

        DB::transaction(function () use (
            $owner,
            $ownerType,
            $organizationId,
            $payload,
            $operation,
            $occurredAt,
        ): void {
            $organization = DB::table('organizations')
                ->where('id', $organizationId)
                ->lockForUpdate()
                ->first();
            if ($organization === null) {
                throw new DomainException('contract_settlement_owner_organization_missing');
            }
            $latest = ContractSettlementOwnerVersion::query()
                ->where('organization_id', $organizationId)
                ->where('owner_type', $ownerType)
                ->where('owner_id', $owner->getKey())
                ->lockForUpdate()
                ->latest('version')
                ->first();
            $version = ((int) ($latest?->version ?? 0)) + 1;
            $identity = [
                'organization_id' => $organizationId,
                'owner_type' => $ownerType,
                'owner_id' => (string) $owner->getKey(),
                'version' => $version,
                'operation' => $operation,
                'occurred_at' => $occurredAt->format(DATE_ATOM),
                'payload' => $payload,
            ];
            ContractSettlementOwnerVersion::query()->create([
                ...$identity,
                'owner_hash' => hash('sha256', CanonicalJson::encode($identity)),
            ]);
        });
    }

    private function organizationId(Model $owner): int
    {
        $organizationId = $owner->getAttribute('organization_id');
        if (is_numeric($organizationId) && (int) $organizationId > 0) {
            return (int) $organizationId;
        }
        $contractId = $owner->getAttribute('contract_id');
        if (is_numeric($contractId)) {
            $resolved = Contract::query()->whereKey((int) $contractId)->value('organization_id');
            if (is_numeric($resolved) && (int) $resolved > 0) {
                return (int) $resolved;
            }
        }
        $documentId = $owner->getAttribute('payment_document_id');
        if (is_numeric($documentId)) {
            $resolved = PaymentDocument::query()->whereKey((int) $documentId)->value('organization_id');
            if (is_numeric($resolved) && (int) $resolved > 0) {
                return (int) $resolved;
            }
        }

        throw new DomainException('contract_settlement_owner_organization_missing');
    }
}
