<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Core\MultiOrganization\Contracts\ContractorSharingInterface;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Services\PurchaseOrderContractRequirementService;
use App\BusinessModules\Features\CommercialProposals\Enums\CommercialProposalStatus;
use App\BusinessModules\Features\CommercialProposals\Models\CommercialProposal;
use App\BusinessModules\Features\CommercialProposals\Services\CommercialProposalExportService;
use App\BusinessModules\Features\CommercialProposals\Services\CommercialProposalService;
use App\BusinessModules\Features\Crm\Models\CrmDeal;
use App\BusinessModules\Features\Crm\Services\CrmTimelineService;
use App\BusinessModules\Features\Crm\Services\DealConversionWizardService;
use App\BusinessModules\Features\Procurement\Enums\PurchaseOrderStatusEnum;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Tenders\Services\TenderTimelineService;
use App\BusinessModules\Features\ContractManagement\Services\ContractEstimateService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\DTOs\Contract\ContractDTO;
use App\DTOs\Contract\ContractDossierCreationInput;
use App\Enums\Contract\ContractStatusEnum;
use App\Http\Controllers\Api\V1\Admin\ContractController;
use App\Http\Requests\Api\V1\Admin\Contract\StoreContractRequest;
use App\Models\Contract;
use App\Models\Estimate;
use App\Models\Project;
use App\Models\User;
use App\Services\Contract\ContractAuditedMutationService;
use App\Services\Contract\ContractDossierCreationService;
use App\Services\Contract\ContractDossierDocumentCreator;
use App\Services\Contract\ContractFromEstimateService;
use App\Services\Contract\ContractLifecycleService;
use App\Services\Contract\ContractSideMutationService;
use App\Services\Contract\ContractService;
use App\Services\Logging\LoggingService;
use App\Services\Project\ProjectBudgetAmountService;
use App\Services\Project\ProjectService;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\Storage\FileService;
use App\BusinessModules\Features\BudgetEstimates\Services\Export\OfficialFormsExportService;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\TestCase;

final class ContractSourceIntegrationTest extends TestCase
{
    private Capsule $database;

    private Container $container;

    private array $documentPayloads = [];

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
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('number');
            $table->string('status')->default('draft');
            $table->unsignedBigInteger('legal_archive_document_id')->nullable()->unique();
            $table->string('dossier_creation_key', 191)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'dossier_creation_key']);
        });
        $schema->create('legal_archive_documents', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('organization_id');
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('contract_dossier_sources', static function (Blueprint $table): void {
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
        $schema->create('estimate_items', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('estimate_id');
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('commercial_proposals', static function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->string('number');
            $table->string('status');
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
        });
        $schema->create('purchase_orders', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->string('status');
            $table->timestamps();
            $table->timestamp('deleted_at')->nullable();
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

    public function test_manual_contract_controller_creates_one_linked_dossier_and_replays_by_idempotency_key(): void
    {
        $this->insertDocument(200);
        $actor = $this->actor();
        $request = new class($actor, $this->input('manual', '1', 1)->contract) extends StoreContractRequest {
            public function __construct(private readonly User $actor, private readonly ContractDTO $contract)
            {
                parent::__construct();
            }

            public function user($guard = null): User
            {
                return $this->actor;
            }

            public function toDto(): ContractDTO
            {
                return $this->contract;
            }

            public function validated($key = null, $default = null): mixed
            {
                $validated = ['idempotency_key' => 'manual-contract-1'];

                return $key === null ? $validated : ($validated[$key] ?? $default);
            }
        };
        $request->attributes->set('current_organization_id', 7);
        $controller = new ContractController(
            Mockery::mock(ContractService::class),
            Mockery::mock(OfficialFormsExportService::class),
            $this->withoutConstructor(ContractLifecycleService::class),
            $this->service(200, 'manual', 'manual-contract-1'),
        );
        $this->container->instance('response', new class {
            public function json(mixed $data, int $status): JsonResponse
            {
                return new JsonResponse($data, $status);
            }
        });
        $first = $controller->store($request);
        $second = $controller->store($request);

        self::assertSame(201, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
        self::assertSame(1, $this->database->table('contracts')->count());
        self::assertSame(200, (int) $this->database->table('contracts')->value('legal_archive_document_id'));
    }

    public function test_estimate_adapter_creates_the_dossier_once_and_attaches_items_only_on_initial_creation(): void
    {
        $this->insertDocument(201);
        $this->database->table('estimate_items')->insert([
            ['id' => 21, 'estimate_id' => 2],
            ['id' => 22, 'estimate_id' => 2],
        ]);
        $estimateItems = Mockery::mock(ContractEstimateService::class);
        $estimateItems->shouldReceive('attachItems')->once()->with(
            Mockery::type(Contract::class),
            Mockery::type(Estimate::class),
            [21, 22],
            true,
        );
        $service = new ContractFromEstimateService($this->database->getConnection(), $this->service(201, 'estimate', '2'), $estimateItems);
        $project = new Project;
        $project->forceFill(['id' => 1, 'organization_id' => 7]);
        $estimate = new Estimate;
        $estimate->forceFill(['id' => 2, 'organization_id' => 7, 'project_id' => 1]);
        $input = $this->input('estimate', '2', 1);

        $first = $service->create(7, $this->actor(), $project, $estimate, $input, [21, 22], true);
        $second = $service->create(7, $this->actor(), $project, $estimate, $input, [21, 22], true);

        self::assertFalse($first->replayed);
        self::assertTrue($second->replayed);
        self::assertSame($first->contract->id, $second->contract->id);
        self::assertSame(1, $this->sourceCount('estimate', '2'));
    }

    public function test_crm_adapter_preserves_preview_hash_and_binds_the_deal_source(): void
    {
        $this->insertDocument(202);
        $dossiers = $this->service(202, 'crm_deal', 'deal-3');
        $wizard = new DealConversionWizardService(
            Mockery::mock(ProjectService::class),
            $dossiers,
            $this->withoutConstructor(CrmTimelineService::class),
            $this->withoutConstructor(TenderTimelineService::class),
            Mockery::mock(AuthorizationService::class),
            Mockery::mock(ContractorSharingInterface::class),
            Mockery::mock(LoggingService::class),
            $this->withoutConstructor(ProjectBudgetAmountService::class),
        );
        $project = new Project;
        $project->forceFill(['id' => 1, 'organization_id' => 7]);
        $deal = new CrmDeal;
        $deal->forceFill(['id' => 'deal-3', 'title' => 'CRM deal']);
        $proposal = new CommercialProposal;
        $proposal->forceFill(['id' => 'proposal-3', 'number' => 'KP-3']);
        $method = new \ReflectionMethod($wizard, 'resolveContractForConvert');
        $preview = [
            'preview_hash' => 'preview-3',
            'contract' => ['mode' => 'create', 'fields' => $this->crmContractFields('CRM-3')],
        ];

        $contract = $method->invoke($wizard, 7, $preview, [], $project, $this->actor(), 'crm-key-3', [
            'deal' => $deal,
            'commercial_proposal' => $proposal,
        ]);

        self::assertInstanceOf(Contract::class, $contract);
        self::assertSame(1, $this->sourceCount('crm_deal', 'deal-3'));
        self::assertSame('preview-3', $this->documentPayloads[0]['metadata']['preview_hash']);
        self::assertSame('crm_deal', $this->documentPayloads[0]['links'][1]['link_type']);
        self::assertSame('commercial_proposal', $this->documentPayloads[0]['links'][2]['link_type']);
    }

    public function test_accepted_commercial_proposal_creates_and_replays_its_linked_dossier(): void
    {
        $this->insertDocument(203);
        $this->database->table('commercial_proposals')->insert([
            'id' => 'proposal-4',
            'organization_id' => 7,
            'project_id' => 1,
            'number' => 'KP-4',
            'status' => CommercialProposalStatus::ACCEPTED->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->twice()->andReturnTrue();
        $service = new CommercialProposalService(
            Mockery::mock(FileService::class),
            $this->withoutConstructor(CommercialProposalExportService::class),
            $this->service(203, 'commercial_proposal', 'proposal-4'),
            $authorization,
        );
        $input = $this->input('commercial_proposal', 'proposal-4', 1);

        $first = $service->createContract(7, 'proposal-4', $this->actor(), $input);
        $second = $service->createContract(7, 'proposal-4', $this->actor(), $input);

        self::assertFalse($first->replayed);
        self::assertTrue($second->replayed);
        self::assertSame($first->contract->id, $second->contract->id);
        self::assertSame($first->contract->id, (int) $this->database->table('commercial_proposals')->value('contract_id'));
        self::assertSame(1, $this->sourceCount('commercial_proposal', 'proposal-4'));
    }

    public function test_payment_recovery_exposes_continuation_only_for_confirmed_order_without_contract_and_blocks_inactive_contract(): void
    {
        $this->database->table('purchase_orders')->insert([
            'id' => 51,
            'organization_id' => 7,
            'status' => PurchaseOrderStatusEnum::CONFIRMED->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $document = new PaymentDocument;
        $document->forceFill(['id' => 61, 'organization_id' => 7, 'metadata' => ['purchase_order_id' => 51]]);
        $requirement = new PurchaseOrderContractRequirementService;
        $requirement->preload([$document]);

        self::assertSame(['type' => 'continue_contract_creation', 'purchase_order_id' => 51], $requirement->continuationAction($document));
        self::assertSame('contract_required_not_active', $requirement->blocker($document));

        $this->database->table('contracts')->insert([
            'id' => 71,
            'organization_id' => 7,
            'number' => 'inactive-contract',
            'status' => ContractStatusEnum::DRAFT->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->database->table('purchase_orders')->where('id', 51)->update(['contract_id' => 71]);
        $blockedDocument = new PaymentDocument;
        $blockedDocument->forceFill(['id' => 62, 'organization_id' => 7, 'metadata' => ['purchase_order_id' => 51]]);
        $blockedRequirement = new PurchaseOrderContractRequirementService;
        $blockedRequirement->preload([$blockedDocument]);

        self::assertNull($blockedRequirement->continuationAction($blockedDocument));
        self::assertSame('contract_required_not_active', $blockedRequirement->blocker($blockedDocument));
        $this->expectException(\DomainException::class);
        $blockedRequirement->assertPaymentAllowed($blockedDocument);
    }

    private function service(int $documentId, string $sourceType, string $sourceId): ContractDossierCreationService
    {
        $documents = Mockery::mock(ContractDossierDocumentCreator::class);
        $document = new LegalArchiveDocument;
        $document->forceFill(['id' => $documentId, 'organization_id' => 7]);
        $documents->shouldReceive('create')->once()->andReturnUsing(function (int $organizationId, int $actorId, array $payload) use ($document): LegalArchiveDocument {
            $this->documentPayloads[] = $payload;

            return $document;
        });
        $contracts = Mockery::mock(ContractSideMutationService::class);
        $contracts->shouldReceive('create')->once()->andReturnUsing(function (int $organizationId, ContractDTO $contract, mixed $projectContext = null, ?int $actorId = null, ?string $dossierCreationKey = null) use ($sourceType, $sourceId): Contract {
            return Contract::query()->create([
                'organization_id' => 7,
                'number' => "{$sourceType}-{$sourceId}",
                'dossier_creation_key' => $dossierCreationKey,
            ]);
        });

        return new ContractDossierCreationService(
            $this->database->getConnection(),
            $contracts,
            new ContractAuditedMutationService(Mockery::mock(LegalDocumentAudit::class)->shouldIgnoreMissing(), $this->database->getConnection()),
            $documents,
        );
    }

    private function actor(): User
    {
        $actor = new User;
        $actor->forceFill(['id' => 3, 'current_organization_id' => 7]);

        return $actor;
    }

    private function input(string $sourceType, string $sourceId, ?int $projectId = null): ContractDossierCreationInput
    {
        return new ContractDossierCreationInput(
            new ContractDTO(
                project_id: $projectId, contractor_id: null, parent_contract_id: null, number: "{$sourceType}-{$sourceId}",
                date: '2026-07-21', subject: 'Contract source fixture', work_type_category: null, payment_terms: null,
                base_amount: 1.0, total_amount: 1.0, gp_percentage: null, gp_calculation_type: null, gp_coefficient: null,
                warranty_retention_calculation_type: null, warranty_retention_percentage: null, warranty_retention_coefficient: null,
                subcontract_amount: null, planned_advance_amount: null, actual_advance_amount: null, status: ContractStatusEnum::DRAFT,
                start_date: null, end_date: null, notes: null,
            ),
            "{$sourceType}:{$sourceId}",
            'Contract source fixture',
            sourceType: $sourceType,
            sourceId: $sourceId,
        );
    }

    private function insertDocument(int $id): void
    {
        $this->database->table('legal_archive_documents')->insert(['id' => $id, 'organization_id' => 7]);
    }

    private function sourceCount(string $sourceType, string $sourceId): int
    {
        return $this->database->table('contract_dossier_sources')
            ->where('organization_id', 7)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->count();
    }

    private function crmContractFields(string $number): array
    {
        return [
            'number' => $number,
            'date' => '2026-07-21',
            'contract_side_type' => 'customer_to_general_contractor',
            'status' => ContractStatusEnum::DRAFT->value,
            'base_amount' => 1.0,
            'total_amount' => 1.0,
        ];
    }

    private function withoutConstructor(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }
}
