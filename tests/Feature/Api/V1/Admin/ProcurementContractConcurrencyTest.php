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
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\TestCase;

final class ProcurementContractConcurrencyTest extends TestCase
{
    private Capsule $database;

    private Container $container;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container = new Container;
        $this->database = new Capsule;
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->setAsGlobal();
        $this->database->setEventDispatcher(new Dispatcher($this->container));
        $this->database->bootEloquent();
        $this->container->instance('db', $this->database->getDatabaseManager());
        $this->container->instance('events', new Dispatcher($this->container));
        Container::setInstance($this->container);
        Facade::setFacadeApplication($this->container);
        Model::clearBootedModels();
        $schema = $this->database->schema();
        $schema->create('contracts', static function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('organization_id'); $table->string('number'); $table->string('status')->default('draft');
            $table->string('gp_calculation_type');
            $table->unsignedBigInteger('legal_archive_document_id')->nullable()->unique(); $table->string('dossier_creation_key', 191)->nullable();
            $table->timestamps(); $table->softDeletes(); $table->unique(['organization_id', 'dossier_creation_key']);
        });
        $schema->create('legal_archive_documents', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary(); $table->unsignedBigInteger('organization_id'); $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('contract_dossier_sources', static function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('organization_id'); $table->unsignedBigInteger('contract_id');
            $table->string('source_type', 64); $table->string('source_id', 191); $table->string('idempotency_key', 191); $table->timestamps();
            $table->unique(['organization_id', 'source_type', 'source_id'], 'contract_dossier_sources_source_unique');
            $table->unique(['organization_id', 'idempotency_key'], 'contract_dossier_sources_key_unique');
        });
        $schema->create('purchase_orders', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('purchase_request_id')->nullable();
            $table->unsignedBigInteger('external_supplier_contact_id')->nullable();
            $table->unsignedBigInteger('supplier_party_id')->nullable();
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->string('order_number');
            $table->string('status');
            $table->decimal('total_amount', 15, 2);
            $table->json('supplier_snapshot')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('organizations', static function (Blueprint $table): void {
            $table->id();
        });
        $schema->create('suppliers', static function (Blueprint $table): void {
            $table->id();
        });
        $schema->create('contractors', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('name');
            $table->string('inn')->nullable();
            $table->string('email')->nullable();
            $table->string('contact_person')->nullable();
            $table->string('phone')->nullable();
            $table->string('legal_address')->nullable();
            $table->string('contractor_type')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('external_supplier_contacts', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('address')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('projects', static function (Blueprint $table): void {
            $table->id();
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_supplier_purchase_order_uses_supply_side_type_and_replays_single_dossier(): void
    {
        $this->database->table('legal_archive_documents')->insert(['id' => 41, 'organization_id' => 7]);
        $this->database->table('organizations')->insert(['id' => 7]);
        $order = PurchaseOrder::query()->create([
            'organization_id' => 7,
            'supplier_id' => 5,
            'order_number' => 'PO-41',
            'status' => PurchaseOrderStatusEnum::CONFIRMED,
            'total_amount' => 1.0,
        ]);
        $staleCaller = PurchaseOrder::query()->findOrFail($order->id);
        $document = new LegalArchiveDocument;
        $document->forceFill(['id' => 41, 'organization_id' => 7]);
        $documents = Mockery::mock(ContractDossierDocumentCreator::class);
        $documents->shouldReceive('create')->once()->andReturn($document);
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
                'gp_calculation_type' => $dto->gp_calculation_type?->value,
                'dossier_creation_key' => 'purchase-order:41',
            ]));
        $dossiers = new ContractDossierCreationService(
            $this->database->getConnection(),
            $contracts,
            new ContractAuditedMutationService(Mockery::mock(LegalDocumentAudit::class)->shouldIgnoreMissing(), $this->database->getConnection()),
            $documents,
        );
        $actor = new User;
        $actor->forceFill(['id' => 3, 'current_organization_id' => 7]);
        Auth::shouldReceive('user')->once()->andReturn($actor);
        $mutations = Mockery::mock(ContractAuditedMutationService::class);
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
        $this->database->table('legal_archive_documents')->insert(['id' => 42, 'organization_id' => 7]);
        $this->database->table('organizations')->insert(['id' => 7]);
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
            'status' => PurchaseOrderStatusEnum::CONFIRMED,
            'total_amount' => 1.0,
        ]);
        $staleCaller = PurchaseOrder::query()->findOrFail($order->id);
        $document = new LegalArchiveDocument;
        $document->forceFill(['id' => 42, 'organization_id' => 7]);
        $documents = Mockery::mock(ContractDossierDocumentCreator::class);
        $documents->shouldReceive('create')->once()->andReturn($document);
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
                'gp_calculation_type' => $dto->gp_calculation_type?->value,
                'dossier_creation_key' => 'purchase-order:42',
            ]));
        $dossiers = new ContractDossierCreationService(
            $this->database->getConnection(),
            $contracts,
            new ContractAuditedMutationService(Mockery::mock(LegalDocumentAudit::class)->shouldIgnoreMissing(), $this->database->getConnection()),
            $documents,
        );
        $actor = new User;
        $actor->forceFill(['id' => 3, 'current_organization_id' => 7]);
        Auth::shouldReceive('user')->once()->andReturn($actor);
        $mutations = Mockery::mock(ContractAuditedMutationService::class);
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
}
