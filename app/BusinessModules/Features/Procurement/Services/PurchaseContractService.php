<?php

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\ContractWorkTypeCategoryEnum;
use App\Models\Contract;
use App\Models\Contractor;
use App\Modules\Core\AccessController;
use App\Services\Contract\ContractAuditedMutationService;
use App\Services\Contract\ContractStateEventService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

use function trans_message;

class PurchaseContractService
{
    public function __construct(
        private readonly ContractAuditedMutationService $contractMutations,
        private readonly ContractStateEventService $stateEventService,
    ) {}

    public function createManualContract(array $data, int $organizationId): Contract
    {
        $this->validateProcurementContractCreation($data, $organizationId);

        DB::beginTransaction();

        try {
            $attributes = [
                'organization_id' => $organizationId,
                'project_id' => $data['project_id'] ?? null,
                'supplier_id' => $data['supplier_id'],
                'contract_category' => 'procurement',
                'number' => $data['number'] ?? $this->generateContractNumber($organizationId),
                'date' => $data['date'],
                'subject' => $data['subject'],
                'work_type_category' => ContractWorkTypeCategoryEnum::SUPPLY,
                'base_amount' => $data['total_amount'],
                'total_amount' => $data['total_amount'],
                'status' => ContractStatusEnum::DRAFT,
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'is_fixed_amount' => true,
            ];
            $contract = $this->contractMutations->create($attributes, Auth::id(), function (Contract $created): array {
                $stateEvent = $this->stateEventService->createContractCreatedEvent($created);

                return ['source_event_id' => 'contract_state_event:'.(string) $stateEvent->id, 'origin' => 'procurement_manual'];
            });

            DB::commit();

            return $contract->fresh(['supplier', 'project', 'organization']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function createFromOrder(PurchaseOrder $order): Contract
    {
        $order->loadMissing(['externalSupplierContact', 'supplierParty', 'purchaseRequest.siteRequest']);

        DB::beginTransaction();

        try {
            $externalContractor = $this->resolveExternalSupplierContractor($order);

            $validationData = [
                'supplier_id' => $order->supplier_id,
                'contractor_id' => $externalContractor?->id,
            ];

            $this->validateProcurementContractCreation($validationData, $order->organization_id);

            $contractNumber = $this->generateContractNumber($order->organization_id);

            $attributes = [
                'organization_id' => $order->organization_id,
                'project_id' => $order->purchaseRequest?->siteRequest?->project_id,
                'contractor_id' => $externalContractor?->id,
                'supplier_id' => $order->supplier_id,
                'contract_category' => 'procurement',
                'number' => $contractNumber,
                'date' => now(),
                'subject' => "Договор поставки по заказу {$order->order_number}",
                'work_type_category' => ContractWorkTypeCategoryEnum::SUPPLY,
                'base_amount' => $order->total_amount,
                'total_amount' => $order->total_amount,
                'status' => ContractStatusEnum::DRAFT,
                'notes' => "Создан из заказа поставщику: {$order->order_number}",
                'is_fixed_amount' => true,
            ];
            $contract = $this->contractMutations->create($attributes, Auth::id(), function (Contract $created) use ($order): array {
                $stateEvent = $this->stateEventService->createContractCreatedEvent($created);

                return ['source_event_id' => 'contract_state_event:'.(string) $stateEvent->id, 'purchase_order_id' => (int) $order->id];
            });

            $order->update(['contract_id' => $contract->id]);

            DB::commit();

            event(new \App\BusinessModules\Features\Procurement\Events\PurchaseContractCreated($contract, $order));

            return $contract->fresh(['supplier', 'contractor', 'project', 'organization']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function validateProcurementContractCreation(array $data, int $organizationId): void
    {
        $accessController = app(AccessController::class);

        if (! $accessController->hasModuleAccess($organizationId, 'procurement')) {
            throw new \DomainException(
                trans_message('procurement.contracts.procurement_module_required')
            );
        }

        if (! $accessController->hasModuleAccess($organizationId, 'basic-warehouse')) {
            throw new \DomainException(
                trans_message('procurement.contracts.warehouse_module_required')
            );
        }

        if (empty($data['supplier_id']) && empty($data['contractor_id'])) {
            throw new \InvalidArgumentException(
                trans_message('procurement.contracts.supplier_required')
            );
        }

        if (! empty($data['supplier_id'])) {
            $supplier = \App\Models\Supplier::query()
                ->where('organization_id', $organizationId)
                ->where('is_active', true)
                ->find($data['supplier_id']);

            if (! $supplier) {
                throw new \InvalidArgumentException(trans_message('procurement.contracts.supplier_not_found'));
            }
        }

        if (! empty($data['contractor_id'])) {
            $contractor = Contractor::query()
                ->where('organization_id', $organizationId)
                ->find($data['contractor_id']);

            if (! $contractor) {
                throw new \InvalidArgumentException(trans_message('procurement.contracts.supplier_not_found'));
            }
        }

        if (! empty($data['project_id'])) {
            $projectExists = \App\Models\Project::query()
                ->where('organization_id', $organizationId)
                ->whereKey($data['project_id'])
                ->exists();

            if (! $projectExists) {
                throw new \InvalidArgumentException(trans_message('procurement.contracts.project_not_found'));
            }
        }
    }

    public function linkToPurchaseOrder(Contract $contract, PurchaseOrder $order): void
    {
        if ($order->contract_id && $order->contract_id !== $contract->id) {
            throw new \DomainException(trans_message('procurement.contracts.order_already_linked'));
        }

        DB::beginTransaction();

        try {
            $order->update(['contract_id' => $contract->id]);
            $this->contractMutations->update(
                $contract,
                ['supplier_id' => $order->supplier_id],
                'purchase_order_linked',
                Auth::id(),
                [
                    'purchase_order_id' => (int) $order->id,
                    'source_event_id' => "purchase_order:{$order->id}:contract_link",
                ],
            );

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function generateContractNumber(int $organizationId): string
    {
        $year = date('Y');
        $month = date('m');

        $lastContract = Contract::where('organization_id', $organizationId)
            ->where('contract_category', 'procurement')
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->orderBy('id', 'desc')
            ->first();

        $nextNumber = 1;

        if ($lastContract && preg_match('/(\d+)$/', $lastContract->number, $matches)) {
            $nextNumber = (int) $matches[1] + 1;
        }

        return sprintf('ДП-%s%s-%04d', $year, $month, $nextNumber);
    }

    private function resolveExternalSupplierContractor(PurchaseOrder $order): ?Contractor
    {
        if ($order->supplier_id !== null) {
            return null;
        }

        $contact = $order->externalSupplierContact;
        $party = $order->supplierParty;
        $snapshot = is_array($order->supplier_snapshot) ? $order->supplier_snapshot : [];
        $name = trim((string) ($contact?->name ?? $party?->display_name ?? $snapshot['display_name'] ?? $snapshot['name'] ?? ''));

        if ($name === '') {
            return null;
        }

        $inn = $this->contractorInn($contact?->tax_number ?? $party?->tax_id ?? $snapshot['tax_id'] ?? null);
        $email = trim((string) ($contact?->email ?? $party?->email ?? $snapshot['email'] ?? ''));
        $lookup = ['organization_id' => $order->organization_id];

        if ($inn !== null) {
            $lookup['inn'] = $inn;
        } elseif ($email !== '') {
            $lookup['email'] = $email;
        } else {
            $lookup['name'] = $name;
        }

        return Contractor::query()->firstOrCreate($lookup, [
            'name' => $name,
            'contact_person' => $contact?->contact_person ?? $party?->contact_name ?? null,
            'phone' => $contact?->phone ?? $party?->phone ?? null,
            'email' => $email !== '' ? $email : null,
            'legal_address' => $contact?->address,
            'inn' => $inn,
            'contractor_type' => Contractor::TYPE_MANUAL,
        ]);
    }

    private function contractorInn(mixed $value): ?string
    {
        $inn = trim((string) $value);

        if ($inn === '' || strlen($inn) > 12) {
            return null;
        }

        return $inn;
    }
}
