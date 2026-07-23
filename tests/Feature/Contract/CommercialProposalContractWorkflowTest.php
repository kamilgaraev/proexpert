<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\BusinessModules\Features\CommercialProposals\Enums\CommercialProposalStatus;
use App\BusinessModules\Features\CommercialProposals\Exceptions\CommercialProposalWorkflowException;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposal;
use App\BusinessModules\Features\CommercialProposals\Services\CommercialProposalExportService;
use App\BusinessModules\Features\CommercialProposals\Services\CommercialProposalService;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Domain\Authorization\Services\AuthorizationService;
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
use App\Services\Storage\FileService;
use DomainException;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\TestCase;

final class CommercialProposalContractWorkflowTest extends TestCase
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
        Container::setInstance($this->container);
        Facade::setFacadeApplication($this->container);
        Model::clearBootedModels();

        $this->database->schema()->create('contracts', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('number');
            $table->string('status')->default('draft');
            $table->unsignedBigInteger('legal_archive_document_id')->nullable()->unique();
            $table->string('dossier_creation_key', 191)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $this->database->schema()->create('legal_archive_documents', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('organization_id');
            $table->timestamp('deleted_at')->nullable();
        });
        $this->database->schema()->create('commercial_proposals', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->string('number');
            $table->string('title');
            $table->string('status', 32);
            $table->string('currency', 3)->default('RUB');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
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
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_accepted_proposal_creates_one_contract_dossier_and_replays_it(): void
    {
        $proposal = $this->proposal(7, 13);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->twice()->with(
            Mockery::type(User::class),
            'contracts.create',
            ['organization_id' => 7, 'project_id' => 13],
        )->andReturnTrue();

        $first = $this->service($authorization, $this->creatingDossiers())->createContract(
            7,
            $proposal->id,
            $this->actor(),
            $this->input(13, $proposal->id),
        );
        $second = $this->service($authorization, $this->dossiers())->createContract(
            7,
            $proposal->id,
            $this->actor(),
            $this->input(13, $proposal->id),
        );

        self::assertFalse($first->replayed);
        self::assertTrue($second->replayed);
        self::assertSame($first->contract->id, $second->contract->id);
        self::assertSame(71, $first->document->id);
        self::assertSame($first->contract->id, $proposal->fresh()->contract_id);
        self::assertSame(1, $this->database->table('contract_dossier_sources')->count());
    }

    public function test_accepted_proposal_replays_its_complete_contract_dossier(): void
    {
        $proposal = $this->proposal(7, 13);
        $contract = $this->contractWithDossier(7, 13);
        $proposal->update(['contract_id' => $contract->id]);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->once()->with(
            Mockery::type(User::class),
            'contracts.create',
            ['organization_id' => 7, 'project_id' => 13],
        )->andReturnTrue();
        $dossiers = $this->dossiers();

        $result = $this->service($authorization, $dossiers)->createContract(7, $proposal->id, $this->actor(), $this->input(13));

        self::assertTrue($result->replayed);
        self::assertSame($contract->id, $result->contract->id);
        self::assertSame(71, $result->document->id);
    }

    public function test_contract_creation_requires_project_scoped_permission(): void
    {
        $proposal = $this->proposal(7, 13);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->once()->andReturnFalse();
        $dossiers = $this->dossiers();

        $this->expectException(CommercialProposalWorkflowException::class);
        $this->expectExceptionMessage('contract_create_forbidden');
        $this->service($authorization, $dossiers)->createContract(7, $proposal->id, $this->actor(), $this->input(13));
    }

    public function test_contract_creation_rejects_a_different_project_before_dossier_creation(): void
    {
        $proposal = $this->proposal(7, 13);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldNotReceive('can');
        $dossiers = $this->dossiers();

        $this->expectException(CommercialProposalWorkflowException::class);
        $this->expectExceptionMessage('contract_only_from_accepted_proposal');
        $this->service($authorization, $dossiers)->createContract(7, $proposal->id, $this->actor(), $this->input(14));
    }

    public function test_contract_creation_cannot_read_a_proposal_from_another_organization(): void
    {
        $proposal = $this->proposal(8, 13);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldNotReceive('can');
        $dossiers = $this->dossiers();

        $this->expectException(ModelNotFoundException::class);
        $this->service($authorization, $dossiers)->createContract(7, $proposal->id, $this->actor(), $this->input(13));
    }

    private function service(AuthorizationService $authorization, ContractDossierCreationService $dossiers): CommercialProposalService
    {
        $files = Mockery::mock(FileService::class);

        return new CommercialProposalService(
            $files,
            new CommercialProposalExportService($files),
            $dossiers,
            $authorization,
        );
    }

    private function dossiers(): ContractDossierCreationService
    {
        $contracts = Mockery::mock(ContractSideMutationService::class);
        $contracts->shouldNotReceive('create');
        $documents = Mockery::mock(ContractDossierDocumentCreator::class);
        $documents->shouldNotReceive('create');

        return new ContractDossierCreationService(
            $this->database->getConnection(),
            $contracts,
            new ContractAuditedMutationService(
                Mockery::mock(LegalDocumentAudit::class)->shouldIgnoreMissing(),
                $this->database->getConnection(),
            ),
            $documents,
        );
    }

    private function creatingDossiers(): ContractDossierCreationService
    {
        $document = new LegalArchiveDocument;
        $document->forceFill(['id' => 71, 'organization_id' => 7]);
        $this->database->table('legal_archive_documents')->insert(['id' => 71, 'organization_id' => 7]);
        $contracts = Mockery::mock(ContractSideMutationService::class);
        $contracts->shouldReceive('create')->once()->andReturnUsing(static fn (): Contract => Contract::query()->create([
            'organization_id' => 7,
            'project_id' => 13,
            'number' => 'CP-CONTRACT-71',
            'dossier_creation_key' => 'commercial-proposal-71',
        ]));
        $documents = Mockery::mock(ContractDossierDocumentCreator::class);
        $documents->shouldReceive('create')->once()->andReturn($document);

        return new ContractDossierCreationService(
            $this->database->getConnection(),
            $contracts,
            new ContractAuditedMutationService(
                Mockery::mock(LegalDocumentAudit::class)->shouldIgnoreMissing(),
                $this->database->getConnection(),
            ),
            $documents,
        );
    }

    private function proposal(int $organizationId, int $projectId): CommercialProposal
    {
        return CommercialProposal::query()->create([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'number' => "CP-{$organizationId}-{$projectId}",
            'title' => 'Accepted proposal',
            'status' => CommercialProposalStatus::ACCEPTED,
        ]);
    }

    private function contractWithDossier(int $organizationId, int $projectId): Contract
    {
        $this->database->table('legal_archive_documents')->insert(['id' => 71, 'organization_id' => $organizationId]);

        return Contract::query()->create([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'number' => 'CP-CONTRACT-71',
            'legal_archive_document_id' => 71,
        ]);
    }

    private function actor(): User
    {
        $actor = new User;
        $actor->forceFill(['id' => 3, 'current_organization_id' => 7]);

        return $actor;
    }

    private function input(int $projectId, ?string $proposalId = null): ContractDossierCreationInput
    {
        return new ContractDossierCreationInput(
            new ContractDTO(
                project_id: $projectId,
                contractor_id: 1,
                parent_contract_id: null,
                number: 'CP-CONTRACT-71',
                date: '2026-07-20',
                subject: 'Contract from commercial proposal',
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
            'commercial-proposal-71',
            'Contract from commercial proposal',
            sourceType: $proposalId === null ? null : 'commercial_proposal',
            sourceId: $proposalId,
        );
    }
}
