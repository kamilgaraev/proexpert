<?php

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Enums\PurchaseOrderStatusEnum;
use App\DTOs\Contract\ContractDTO;
use App\DTOs\Contract\ContractDossierCreationInput;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\ContractWorkTypeCategoryEnum;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Contract\ContractAuditedMutationService;
use App\Services\Contract\ContractDossierCreationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

use function trans_message;

class PurchaseContractService
{
    public function __construct(
        private readonly ContractAuditedMutationService $contractMutations,
        private readonly ContractDossierCreationService $dossiers,
    ) {}

    public function createManualContract(array $data, int $organizationId, string $idempotencyKey): Contract
    {
        $this->validateProcurementContractCreation($data, $organizationId);

        $actor = $this->actor();
        $number = (string) ($data['number'] ?? $this->generateContractNumber($organizationId));
        $result = $this->dossiers->create($organizationId, $actor, new ContractDossierCreationInput(
            contract: $this->contractDto($data + ['number' => $number]),
            idempotencyKey: 'procurement-manual:'.$idempotencyKey,
            documentTitle: $data['subject'] ?? 'Договор поставки №'.$number,
            profileCode: 'contract.supply',
        ));

        return $result->contract->fresh(['supplier', 'project', 'organization']);
    }

    public function createFromOrder(PurchaseOrder $order): Contract
    {
        return DB::transaction(function () use ($order): Contract {
            $order = PurchaseOrder::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($order->contract_id !== null) {
                return $order->contract()->firstOrFail();
            }
            if (! in_array($order->status, [
                PurchaseOrderStatusEnum::CONFIRMED,
                PurchaseOrderStatusEnum::IN_DELIVERY,
                PurchaseOrderStatusEnum::PARTIALLY_DELIVERED,
                PurchaseOrderStatusEnum::DELIVERED,
            ], true)) {
                throw new \DomainException(trans_message('procurement.purchase_orders.contract_creation_status_invalid'));
            }
            $order->loadMissing(['externalSupplierContact', 'supplierParty', 'purchaseRequest.siteRequest']);
            $externalContractor = $this->resolveExternalSupplierContractor($order);

            $validationData = [
                'supplier_id' => $order->supplier_id,
                'contractor_id' => $externalContractor?->id,
            ];

            $this->validateProcurementContractCreation($validationData, $order->organization_id);

            $contractNumber = sprintf('ДП-%s-%d', now()->format('Ym'), $order->id);
            $result = $this->dossiers->create($order->organization_id, $this->actor(), new ContractDossierCreationInput(
                contract: $this->contractDto([
                    'project_id' => $order->purchaseRequest?->siteRequest?->project_id,
                    'contractor_id' => $externalContractor?->id,
                    'supplier_id' => $order->supplier_id,
                    'number' => $contractNumber,
                    'date' => now()->toDateString(),
                    'subject' => 'Договор поставки по заказу '.$order->order_number,
                    'total_amount' => (float) $order->total_amount,
                    'notes' => 'Создан из заказа поставщику: '.$order->order_number,
                ]),
                idempotencyKey: 'purchase-order:'.$order->id,
                documentTitle: 'Договор поставки №'.$contractNumber,
                profileCode: 'contract.supply',
                sourceLinks: [[
                    'link_type' => 'purchase_order',
                    'linked_type' => 'purchase_order',
                    'linked_id' => (string) $order->id,
                    'display_name' => $order->order_number,
                ]],
                sourceType: 'purchase_order',
                sourceId: (string) $order->id,
            ));
            $contract = $result->contract;

            $order->update(['contract_id' => $contract->id]);
            if (! $result->replayed) {
                DB::afterCommit(static fn () => event(new \App\BusinessModules\Features\Procurement\Events\PurchaseContractCreated($contract, $order)));
            }

            return $contract->fresh(['supplier', 'contractor', 'project', 'organization']);
        });
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

    private function actor(): User
    {
        $actor = Auth::user();
        if (! $actor instanceof User) {
            throw new \DomainException(trans_message('auth.unauthorized'));
        }

        return $actor;
    }

    private function contractDto(array $data): ContractDTO
    {
        return new ContractDTO(
            project_id: isset($data['project_id']) ? (int) $data['project_id'] : null,
            contractor_id: isset($data['contractor_id']) ? (int) $data['contractor_id'] : null,
            parent_contract_id: null,
            number: (string) $data['number'],
            date: (string) $data['date'],
            subject: $data['subject'] ?? null,
            work_type_category: ContractWorkTypeCategoryEnum::SUPPLY,
            payment_terms: $data['payment_terms'] ?? null,
            base_amount: (float) $data['total_amount'],
            total_amount: (float) $data['total_amount'],
            gp_percentage: null,
            gp_calculation_type: null,
            gp_coefficient: null,
            warranty_retention_calculation_type: null,
            warranty_retention_percentage: null,
            warranty_retention_coefficient: null,
            subcontract_amount: null,
            planned_advance_amount: null,
            actual_advance_amount: null,
            status: ContractStatusEnum::DRAFT,
            start_date: $data['start_date'] ?? null,
            end_date: $data['end_date'] ?? null,
            notes: $data['notes'] ?? null,
            is_fixed_amount: true,
            supplier_id: isset($data['supplier_id']) ? (int) $data['supplier_id'] : null,
            contract_category: 'procurement',
            contract_side_type: ! empty($data['supplier_id'])
                ? ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_SUPPLIER
                : ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_CONTRACTOR,
        );
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
