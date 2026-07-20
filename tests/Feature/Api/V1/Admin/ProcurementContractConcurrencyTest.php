<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\DTOs\Contract\ContractDTO;
use App\DTOs\Contract\ContractDossierCreationInput;
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

final class ProcurementContractConcurrencyTest extends TestCase
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
        $schema = $this->database->schema();
        $schema->create('contracts', static function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('organization_id'); $table->string('number'); $table->string('status')->default('draft');
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
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_competing_purchase_order_attempts_return_the_same_persisted_contract_and_dossier(): void
    {
        $this->database->table('legal_archive_documents')->insert(['id' => 41, 'organization_id' => 7]);
        $document = new LegalArchiveDocument;
        $document->forceFill(['id' => 41, 'organization_id' => 7]);
        $documents = Mockery::mock(ContractDossierDocumentCreator::class);
        $documents->shouldReceive('create')->once()->andReturn($document);
        $contracts = Mockery::mock(ContractSideMutationService::class);
        $contracts->shouldReceive('create')->once()->andReturnUsing(static fn (): Contract => Contract::query()->create([
            'organization_id' => 7, 'number' => 'PO-41', 'dossier_creation_key' => 'purchase-order:41',
        ]));
        $service = new ContractDossierCreationService(
            $this->database->getConnection(),
            $contracts,
            new ContractAuditedMutationService(Mockery::mock(LegalDocumentAudit::class)->shouldIgnoreMissing(), $this->database->getConnection()),
            $documents,
        );
        $actor = new User;
        $actor->forceFill(['id' => 3, 'current_organization_id' => 7]);
        $input = new ContractDossierCreationInput(
            new ContractDTO(project_id: null, contractor_id: null, parent_contract_id: null, number: 'PO-41', date: '2026-07-21', subject: 'Purchase order', work_type_category: null, payment_terms: null, base_amount: 1.0, total_amount: 1.0, gp_percentage: null, gp_calculation_type: null, gp_coefficient: null, warranty_retention_calculation_type: null, warranty_retention_percentage: null, warranty_retention_coefficient: null, subcontract_amount: null, planned_advance_amount: null, actual_advance_amount: null, status: ContractStatusEnum::DRAFT, start_date: null, end_date: null, notes: null),
            'purchase-order:41', 'Purchase order', sourceType: 'purchase_order', sourceId: '41',
        );

        $first = $service->create(7, $actor, $input);
        $second = $service->create(7, $actor, $input);

        self::assertFalse($first->replayed);
        self::assertTrue($second->replayed);
        self::assertSame($first->contract->id, $second->contract->id);
        self::assertSame(41, $second->document->id);
        self::assertSame(1, $this->database->table('contracts')->count());
        self::assertSame(1, $this->database->table('contract_dossier_sources')->count());
    }
}
