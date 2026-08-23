<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\Procurement\Enums\PurchaseOrderStatusEnum;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Services\PurchaseContractService;
use App\DTOs\Contract\ContractDTO;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Enums\Contract\GpCalculationTypeEnum;
use App\Models\Contract;
use App\Models\User;
use App\Services\Contract\ContractAuditedMutationService;
use App\Services\Contract\ContractDossierCreationService;
use App\Services\Contract\ContractDossierDocumentCreator;
use App\Services\Contract\ContractSideMutationService;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

final class ProcurementContractConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private ConnectionInterface $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = DB::connection();
    }

    public function test_supplier_purchase_order_uses_supply_side_type_and_replays_single_dossier(): void
    {
        $this->database->table('organizations')->insert([
            'id' => 7,
            'name' => 'Заказчик МОСТ',
            'legal_name' => 'Заказчик МОСТ',
        ]);
        $this->database->table('legal_archive_documents')->insert([
            'id' => 41,
            'organization_id' => 7,
            'title' => 'Договор поставки PO-41',
            'document_type' => 'contract',
        ]);
        $this->database->table('suppliers')->insert(['id' => 5, 'organization_id' => 7, 'name' => 'Поставщик МОСТ']);
        $order = PurchaseOrder::query()->create([
            'organization_id' => 7,
            'supplier_id' => 5,
            'order_number' => 'PO-41',
            'order_date' => '2026-07-24',
            'status' => PurchaseOrderStatusEnum::CONFIRMED,
            'total_amount' => 1.0,
            'delivery_date' => '2026-07-31',
        ]);
        $staleCaller = PurchaseOrder::query()->findOrFail($order->id);
        $document = new LegalArchiveDocument;
        $document->forceFill(['id' => 41, 'organization_id' => 7]);
        $documents = Mockery::mock(ContractDossierDocumentCreator::class);
        $documents->shouldReceive('create')->once()->withArgs(static function (int $organizationId, int $actorId, array $data): bool {
            $metadata = $data['metadata'] ?? null;

            return $organizationId === 7
                && $actorId === 3
                && is_array($metadata)
                && ($metadata['subject'] ?? null) === 'Договор поставки по заказу PO-41'
                && ($metadata['buyer'] ?? null) === 'Заказчик МОСТ'
                && ($metadata['supplier'] ?? null) === 'Поставщик МОСТ'
                && ($metadata['price'] ?? null) === 1.0
                && ($metadata['delivery_terms'] ?? null) === '2026-07-31';
        })->andReturn($document);
        $contracts = Mockery::mock(ContractSideMutationService::class);
        $contracts->shouldReceive('create')
            ->once()
            ->withArgs(static function (mixed ...$arguments): bool {
                return $arguments[1] instanceof ContractDTO
                    && $arguments[1]->contract_side_type === ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_SUPPLIER
                    && $arguments[1]->gp_calculation_type === GpCalculationTypeEnum::PERCENTAGE;
            })
            ->andReturnUsing(static fn (int $organizationId, ContractDTO $dto): Contract => Contract::query()->create([
                'organization_id' => $organizationId,
                'number' => 'PO-41',
                'date' => '2026-07-24',
                'supplier_id' => 5,
                'subject' => 'Договор поставки по заказу PO-41',
                'base_amount' => 1.0,
                'total_amount' => 1.0,
                'notes' => 'Создан из заказа поставщику: PO-41',
                'gp_calculation_type' => $dto->gp_calculation_type?->value,
                'dossier_creation_key' => 'purchase-order:41',
            ]));
        $dossiers = new ContractDossierCreationService(
            $this->database,
            $contracts,
            new ContractAuditedMutationService(Mockery::mock(LegalDocumentAudit::class)->shouldIgnoreMissing(), $this->database),
            $documents,
        );
        $actor = User::factory()->create([
            'id' => 3,
            'current_organization_id' => 7,
        ]);
        $this->actingAs($actor);
        $mutations = new ContractAuditedMutationService(
            Mockery::mock(LegalDocumentAudit::class)->shouldIgnoreMissing(),
            $this->database
        );
        $service = Mockery::mock(PurchaseContractService::class, [$mutations, $dossiers])->makePartial();
        $service->shouldReceive('validateProcurementContractCreation')->once()->andReturnNull();

        $first = $service->createFromOrder($order);
        $second = $service->createFromOrder($staleCaller);

        self::assertSame($first->id, $second->id);
        self::assertSame($first->id, $order->fresh()->contract_id);
        self::assertSame(41, $first->legal_archive_document_id);
        self::assertSame('percentage', $this->database->table('contracts')->where('id', $first->id)->value('gp_calculation_type'));
        self::assertSame(1, $this->database->table('contracts')->count());
        self::assertSame(1, $this->database->table('contract_dossier_sources')->count());
    }

    public function test_external_supplier_order_uses_contractor_side_type_and_replays_single_dossier(): void
    {
        $this->database->table('organizations')->insert([
            'id' => 7,
            'name' => 'Заказчик МОСТ',
        ]);
        $this->database->table('legal_archive_documents')->insert([
            'id' => 42,
            'organization_id' => 7,
            'title' => 'Договор поставки PO-42',
            'document_type' => 'contract',
        ]);
        $this->database->table('external_supplier_contacts')->insert([
            'id' => 9,
            'organization_id' => 7,
            'name' => 'Внешний поставщик',
            'tax_number' => '7701234567',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $order = PurchaseOrder::query()->create([
            'organization_id' => 7,
            'external_supplier_contact_id' => 9,
            'order_number' => 'PO-42',
            'order_date' => '2026-07-25',
            'status' => PurchaseOrderStatusEnum::CONFIRMED,
            'total_amount' => 1.0,
            'delivery_date' => '2026-08-01',
        ]);
        $staleCaller = PurchaseOrder::query()->findOrFail($order->id);
        $document = new LegalArchiveDocument;
        $document->forceFill(['id' => 42, 'organization_id' => 7]);
        $documents = Mockery::mock(ContractDossierDocumentCreator::class);
        $documents->shouldReceive('create')->once()->withArgs(static function (int $organizationId, int $actorId, array $data): bool {
            $metadata = $data['metadata'] ?? null;

            return $organizationId === 7
                && $actorId === 3
                && is_array($metadata)
                && ($metadata['subject'] ?? null) === 'Договор поставки по заказу PO-42'
                && ($metadata['buyer'] ?? null) === 'Заказчик МОСТ'
                && is_string($metadata['supplier'] ?? null)
                && trim((string) $metadata['supplier']) !== ''
                && ($metadata['price'] ?? null) === 1.0
                && ($metadata['delivery_terms'] ?? null) === '2026-08-01';
        })->andReturn($document);
        $contracts = Mockery::mock(ContractSideMutationService::class);
        $contracts->shouldReceive('create')
            ->once()
            ->withArgs(static function (mixed ...$arguments): bool {
                return $arguments[1] instanceof ContractDTO
                    && $arguments[1]->contract_side_type === ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_CONTRACTOR
                    && $arguments[1]->contractor_id !== null
                    && $arguments[1]->supplier_id === null
                    && $arguments[1]->gp_calculation_type === GpCalculationTypeEnum::PERCENTAGE;
            })
            ->andReturnUsing(static fn (int $organizationId, ContractDTO $dto): Contract => Contract::query()->create([
                'organization_id' => $organizationId,
                'number' => 'PO-42',
                'date' => '2026-07-25',
                'contractor_id' => $dto->contractor_id,
                'subject' => 'Договор поставки по заказу PO-42',
                'base_amount' => 1.0,
                'total_amount' => 1.0,
                'notes' => 'Создан из заказа поставщику: PO-42',
                'gp_calculation_type' => $dto->gp_calculation_type?->value,
                'dossier_creation_key' => 'purchase-order:42',
            ]));
        $dossiers = new ContractDossierCreationService(
            $this->database,
            $contracts,
            new ContractAuditedMutationService(Mockery::mock(LegalDocumentAudit::class)->shouldIgnoreMissing(), $this->database),
            $documents,
        );
        $actor = User::factory()->create([
            'id' => 3,
            'current_organization_id' => 7,
        ]);
        $this->actingAs($actor);
        $mutations = new ContractAuditedMutationService(
            Mockery::mock(LegalDocumentAudit::class)->shouldIgnoreMissing(),
            $this->database
        );
        $service = Mockery::mock(PurchaseContractService::class, [$mutations, $dossiers])->makePartial();
        $service->shouldReceive('validateProcurementContractCreation')->once()->andReturnNull();

        $first = $service->createFromOrder($order);
        $second = $service->createFromOrder($staleCaller);

        self::assertSame($first->id, $second->id);
        self::assertSame($first->id, $order->fresh()->contract_id);
        self::assertSame(42, $first->legal_archive_document_id);
        self::assertSame('percentage', $this->database->table('contracts')->where('id', $first->id)->value('gp_calculation_type'));
        self::assertSame(1, $this->database->table('contracts')->count());
        self::assertSame(1, $this->database->table('contract_dossier_sources')->count());
    }

    public function test_supplier_purchase_order_without_delivery_terms_rolls_back_contract_and_dossier(): void
    {
        $this->database->table('organizations')->insert([
            'id' => 7,
            'name' => 'Заказчик МОСТ',
            'legal_name' => 'Заказчик МОСТ',
        ]);
        $this->database->table('suppliers')->insert(['id' => 5, 'organization_id' => 7, 'name' => 'Поставщик МОСТ']);
        $order = PurchaseOrder::query()->create([
            'organization_id' => 7,
            'supplier_id' => 5,
            'order_number' => 'PO-43',
            'order_date' => '2026-07-26',
            'status' => PurchaseOrderStatusEnum::CONFIRMED,
            'total_amount' => 1.0,
        ]);
        $documents = Mockery::mock(ContractDossierDocumentCreator::class);
        $documents->shouldNotReceive('create');
        $contracts = Mockery::mock(ContractSideMutationService::class);
        $contracts->shouldReceive('create')->once()->andReturnUsing(static fn (int $organizationId, ContractDTO $dto): Contract => Contract::query()->create([
            'organization_id' => $organizationId,
            'number' => 'PO-43',
            'date' => '2026-07-26',
            'supplier_id' => 5,
            'subject' => 'Договор поставки по заказу PO-43',
            'base_amount' => 1.0,
            'total_amount' => 1.0,
            'gp_calculation_type' => $dto->gp_calculation_type?->value,
            'dossier_creation_key' => 'purchase-order:43',
        ]));
        $dossiers = new ContractDossierCreationService(
            $this->database,
            $contracts,
            new ContractAuditedMutationService(Mockery::mock(LegalDocumentAudit::class)->shouldIgnoreMissing(), $this->database),
            $documents,
        );
        $actor = User::factory()->create([
            'id' => 3,
            'current_organization_id' => 7,
        ]);
        $this->actingAs($actor);
        $mutations = new ContractAuditedMutationService(
            Mockery::mock(LegalDocumentAudit::class)->shouldIgnoreMissing(),
            $this->database
        );
        $service = Mockery::mock(PurchaseContractService::class, [$mutations, $dossiers])->makePartial();
        $service->shouldReceive('validateProcurementContractCreation')->once()->andReturnNull();

        try {
            $service->createFromOrder($order);
            self::fail('Expected validation exception for the missing delivery terms.');
        } catch (ValidationException) {
            self::assertSame(0, $this->database->table('contracts')->count());
            self::assertSame(0, $this->database->table('contract_dossier_sources')->count());
            self::assertNull($order->fresh()->contract_id);
        }
    }
}
