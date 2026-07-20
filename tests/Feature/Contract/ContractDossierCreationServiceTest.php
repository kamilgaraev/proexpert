<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\DTOs\Contract\ContractDTO;
use App\DTOs\Contract\ContractDossierCreationInput;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Enums\Contract\ContractStatusEnum;
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
use Mockery;
use PHPUnit\Framework\TestCase;
use DomainException;

final class ContractDossierCreationServiceTest extends TestCase
{
    private Capsule $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = new Capsule;
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->setAsGlobal();
        $this->database->setEventDispatcher(new Dispatcher(new Container));
        $this->database->bootEloquent();
        Model::clearBootedModels();
        $this->database->schema()->create('contracts', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('number');
            $table->string('status')->default('draft');
            $table->unsignedBigInteger('legal_archive_document_id')->nullable()->unique();
            $table->string('dossier_creation_key', 191)->nullable();
            $table->unique(['organization_id', 'dossier_creation_key']);
            $table->timestamps();
            $table->softDeletes();
        });
        $this->database->schema()->create('legal_archive_documents', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('organization_id');
            $table->timestamp('deleted_at')->nullable();
        });
        $this->database->schema()->create('contract_dossier_sources', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('contract_id');
            $table->string('source_type', 64);
            $table->string('source_id', 191);
            $table->string('idempotency_key', 191);
            $table->timestamps();
            $table->unique(['organization_id', 'source_type', 'source_id'], 'contract_dossier_sources_source_unique');
            $table->unique(['organization_id', 'idempotency_key'], 'contract_dossier_sources_key_unique');
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_repeated_creation_reuses_one_contract_and_dossier(): void
    {
        $document = new LegalArchiveDocument;
        $document->forceFill(['id' => 17, 'organization_id' => 7]);
        $this->database->table('legal_archive_documents')->insert(['id' => 17, 'organization_id' => 7]);
        $creator = Mockery::mock(ContractDossierDocumentCreator::class);
        $creator->shouldReceive('create')->once()->andReturn($document);
        $contracts = Mockery::mock(ContractSideMutationService::class);
        $contracts->shouldReceive('create')->once()->andReturnUsing(static fn (): Contract => Contract::query()->create([
            'organization_id' => 7,
            'number' => 'ДП-7',
            'dossier_creation_key' => 'contract-dossier-7',
        ]));
        $audit = Mockery::mock(LegalDocumentAudit::class)->shouldIgnoreMissing();
        $service = new ContractDossierCreationService(
            $this->database->getConnection(),
            $contracts,
            new ContractAuditedMutationService($audit, $this->database->getConnection()),
            $creator,
        );
        $actor = new User;
        $actor->forceFill(['id' => 3, 'current_organization_id' => 7]);

        $first = $service->create(7, $actor, $this->input());
        $second = $service->create(7, $actor, $this->input());

        self::assertFalse($first->replayed);
        self::assertTrue($second->replayed);
        self::assertSame($first->contract->id, $second->contract->id);
        self::assertSame($first->document->id, $second->document->id);
    }

    public function test_existing_contract_without_a_dossier_is_not_silently_reused(): void
    {
        Contract::query()->create([
            'organization_id' => 7,
            'number' => 'ДП-7',
            'dossier_creation_key' => 'contract-dossier-7',
        ]);
        $contracts = Mockery::mock(ContractSideMutationService::class);
        $contracts->shouldNotReceive('create');
        $creator = Mockery::mock(ContractDossierDocumentCreator::class);
        $creator->shouldNotReceive('create');
        $service = new ContractDossierCreationService(
            $this->database->getConnection(),
            $contracts,
            new ContractAuditedMutationService(
                Mockery::mock(LegalDocumentAudit::class)->shouldIgnoreMissing(),
                $this->database->getConnection(),
            ),
            $creator,
        );
        $actor = new User;
        $actor->forceFill(['id' => 3, 'current_organization_id' => 7]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('contract_dossier_creation_incomplete');
        $service->create(7, $actor, $this->input());
    }

    public function test_repeated_source_reuses_one_contract_and_dossier(): void
    {
        $document = new LegalArchiveDocument;
        $document->forceFill(['id' => 17, 'organization_id' => 7]);
        $this->database->table('legal_archive_documents')->insert(['id' => 17, 'organization_id' => 7]);
        $creator = Mockery::mock(ContractDossierDocumentCreator::class);
        $creator->shouldReceive('create')->once()->andReturn($document);
        $contracts = Mockery::mock(ContractSideMutationService::class);
        $contracts->shouldReceive('create')->once()->andReturnUsing(static fn (): Contract => Contract::query()->create([
            'organization_id' => 7,
            'number' => 'ДП-7',
            'dossier_creation_key' => 'purchase-order-7',
        ]));
        $service = new ContractDossierCreationService(
            $this->database->getConnection(),
            $contracts,
            new ContractAuditedMutationService(
                Mockery::mock(LegalDocumentAudit::class)->shouldIgnoreMissing(),
                $this->database->getConnection(),
            ),
            $creator,
        );
        $actor = new User;
        $actor->forceFill(['id' => 3, 'current_organization_id' => 7]);
        $base = $this->input();
        $input = new ContractDossierCreationInput(
            $base->contract,
            'purchase-order-7',
            'Договор поставки ДП-7',
            sourceType: 'purchase_order',
            sourceId: '7',
        );

        $first = $service->create(7, $actor, $input);
        $second = $service->create(7, $actor, $input);

        self::assertFalse($first->replayed);
        self::assertTrue($second->replayed);
        self::assertSame($first->contract->id, $second->contract->id);
        self::assertSame($first->document->id, $second->document->id);
    }

    private function input(): ContractDossierCreationInput
    {
        return new ContractDossierCreationInput(
            new ContractDTO(
                project_id: null,
                contractor_id: 1,
                parent_contract_id: null,
                number: 'ДП-7',
                date: '2026-07-20',
                subject: 'Договор',
                work_type_category: null,
                payment_terms: null,
                base_amount: 1.0,
                total_amount: 1.0,
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
                start_date: null,
                end_date: null,
                notes: null,
                contract_side_type: ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_CONTRACTOR,
            ),
            'contract-dossier-7',
            'Договор ДП-7',
        );
    }
}
