<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentLink;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Http\Requests\Api\V1\Admin\Contract\ListContractLegalDossierCandidatesRequest;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Contract\ContractAuditedMutationService;
use App\Services\Contract\ContractDossierDocumentCreator;
use App\Services\Contract\ContractLegalDossierService;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\LegalArchive\Profiles\LegalDocumentProfileRegistry;
use App\Services\LegalArchive\Profiles\LegalDocumentProfileValidator;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\TestCase;
use Illuminate\Validation\ValidationException;

final class ContractLegalDossierServiceTest extends TestCase
{
    private Capsule $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = new Capsule;
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database->setAsGlobal();
        $container = new Container;
        $container->instance('app', new class {
            public function getLocale(): string
            {
                return 'ru';
            }
        });
        $container->instance('config', new class {
            public function get(string $key, mixed $default = null): mixed
            {
                return $default;
            }
        });
        $container->instance('translator', new class {
            public function get(string $key): string
            {
                return $key;
            }
        });
        $container->instance('log', new class {
            public function warning(string $message): void {}
        });
        Facade::setFacadeApplication($container);
        $this->database->setEventDispatcher(new Dispatcher($container));
        $this->database->bootEloquent();
        Model::clearBootedModels();

        $this->database->schema()->create('contracts', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->string('number');
            $table->string('status')->default('draft');
            $table->unsignedBigInteger('contractor_id')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('contract_category')->nullable();
            $table->string('work_type_category')->nullable();
            $table->string('subject')->nullable();
            $table->string('payment_terms')->nullable();
            $table->string('delivery_terms')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('base_amount', 20, 4)->nullable();
            $table->decimal('total_amount', 20, 4)->nullable();
            $table->unsignedBigInteger('legal_archive_document_id')->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();
        });
        $this->database->schema()->create('legal_archive_documents', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('primary_project_id')->nullable();
            $table->string('title');
            $table->string('document_type');
            $table->string('type_profile_code')->nullable();
            $table->string('source_type')->nullable();
            $table->string('source_id')->nullable();
            $table->string('source_idempotency_key')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $this->database->schema()->create('legal_archive_document_links', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('organization_id');
            $table->string('link_type');
            $table->string('linked_type')->nullable();
            $table->string('linked_id')->nullable();
            $table->string('display_name');
            $table->timestamps();
        });
        $this->database->schema()->create('legal_archive_document_type_profiles', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('organization_id');
            $table->string('code');
            $table->string('base_code');
            $table->boolean('is_active')->default(true);
        });
        $this->database->schema()->create('organizations', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $this->database->schema()->create('suppliers', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });
        $this->database->schema()->create('contractors', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_create_binds_one_server_profiled_dossier_and_exact_retry_replays(): void
    {
        $contract = $this->contract();
        $creator = Mockery::mock(ContractDossierDocumentCreator::class);
        $creator->shouldReceive('create')->once()->withArgs(function (int $organizationId, int $actorId, array $data) use ($contract): bool {
            self::assertSame(7, $organizationId);
            self::assertSame(3, $actorId);
            self::assertSame(11, $data['primary_project_id']);
            self::assertSame('contract.work', $data['type_profile_code']);
            self::assertSame('contract', $data['source_type']);
            self::assertSame((string) $contract->id, $data['source_id']);
            self::assertSame('9ae6f032-8ef0-4baf-9a7a-93f4413744d7', $data['source_idempotency_key']);
            self::assertSame('contract', $data['links'][0]['linked_type']);
            self::assertSame((string) $contract->id, $data['links'][0]['linked_id']);

            return true;
        })->andReturnUsing(function (): LegalArchiveDocument {
            return LegalArchiveDocument::query()->create([
                'organization_id' => 7,
                'primary_project_id' => 11,
                'title' => 'Договор подряда',
                'document_type' => 'contract',
                'type_profile_code' => 'contract.work',
                'source_type' => 'contract',
                'source_id' => '1',
                'source_idempotency_key' => '9ae6f032-8ef0-4baf-9a7a-93f4413744d7',
            ]);
        });
        $service = $this->service($creator);

        $created = $service->create($this->actor(), 7, 11, (int) $contract->id, [
            'title' => 'Договор подряда',
            'idempotency_key' => '9ae6f032-8ef0-4baf-9a7a-93f4413744d7',
        ]);
        $replayed = $service->create($this->actor(), 7, 11, (int) $contract->id, [
            'title' => 'Договор подряда',
            'idempotency_key' => '9ae6f032-8ef0-4baf-9a7a-93f4413744d7',
        ]);

        self::assertSame('created', $created->operationResult);
        self::assertSame('replayed', $replayed->operationResult);
        self::assertSame((int) $created->document->id, (int) $contract->refresh()->legal_archive_document_id);
        self::assertSame(1, LegalArchiveDocument::query()->count());
        self::assertSame(1, LegalArchiveDocumentLink::query()->count());
    }

    public function test_create_derives_supply_profile_for_supplier_contract(): void
    {
        Organization::query()->forceCreate(['id' => 7, 'name' => 'Заказчик МОСТ']);
        Supplier::query()->forceCreate(['id' => 55, 'organization_id' => 7, 'name' => 'Поставщик МОСТ']);
        $contract = $this->contract([
            'supplier_id' => 55,
            'subject' => 'Поставка материалов',
            'payment_terms' => 'Оплата после приемки',
            'base_amount' => 1000,
            'total_amount' => 1250,
        ]);
        $contract->forceFill([
            'delivery_terms' => 'Поставка в течение 10 дней после оплаты',
        ])->save();
        $storedContract = Contract::query()
            ->with(['organization:id,name,legal_name', 'supplier:id,organization_id,name'])
            ->findOrFail((int) $contract->id);
        self::assertSame('Поставка материалов', $storedContract->subject);
        self::assertSame('Оплата после приемки', $storedContract->payment_terms);
        self::assertSame('Поставка в течение 10 дней после оплаты', $storedContract->delivery_terms);
        self::assertSame('Заказчик МОСТ', $storedContract->organization?->name);
        self::assertSame('Поставщик МОСТ', $storedContract->supplier?->name);
        $creator = Mockery::mock(ContractDossierDocumentCreator::class);
        $creator->shouldReceive('create')->once()->withArgs(function (int $organizationId, int $actorId, array $data): bool {
            self::assertSame(7, $organizationId);
            self::assertSame(3, $actorId);
            self::assertSame('contract.supply', $data['type_profile_code']);
            self::assertSame([
                'subject' => 'Поставка материалов',
                'buyer' => 'Заказчик МОСТ',
                'supplier' => 'Поставщик МОСТ',
                'price' => 1250.0,
                'delivery_terms' => 'Поставка в течение 10 дней после оплаты',
            ], $data['metadata']);

            return true;
        })->andReturnUsing(function () use ($contract): LegalArchiveDocument {
            return LegalArchiveDocument::query()->create([
                'organization_id' => 7,
                'primary_project_id' => 11,
                'title' => 'Договор поставки',
                'document_type' => 'contract',
                'type_profile_code' => 'contract.supply',
                'source_type' => 'contract',
                'source_id' => (string) $contract->id,
                'source_idempotency_key' => 'f48e2af3-3851-4c5a-b5a2-3373677f9a46',
            ]);
        });

        $result = $this->service($creator)->create($this->actor(), 7, 11, (int) $contract->id, [
            'title' => 'Договор поставки',
            'idempotency_key' => 'f48e2af3-3851-4c5a-b5a2-3373677f9a46',
        ]);

        self::assertSame('created', $result->operationResult);
        self::assertSame((int) $result->document->id, (int) $contract->refresh()->legal_archive_document_id);
    }

    public function test_create_derives_supply_profile_for_external_procurement_contract(): void
    {
        Organization::query()->forceCreate(['id' => 7, 'name' => 'Заказчик МОСТ']);
        Contractor::withoutEvents(static fn (): Contractor => Contractor::query()->forceCreate([
            'id' => 56,
            'organization_id' => 7,
            'name' => 'Внешний поставщик МОСТ',
        ]));
        $contract = $this->contract([
            'contractor_id' => 56,
            'contract_category' => 'procurement',
            'subject' => 'Поставка материалов',
            'payment_terms' => 'Оплата после приемки',
            'base_amount' => 1000,
            'total_amount' => 1250,
        ]);
        $contract->forceFill([
            'delivery_terms' => 'Поставка в течение 10 дней после оплаты',
        ])->save();
        $creator = Mockery::mock(ContractDossierDocumentCreator::class);
        $creator->shouldReceive('create')->once()->withArgs(function (int $organizationId, int $actorId, array $data): bool {
            self::assertSame(7, $organizationId);
            self::assertSame(3, $actorId);
            self::assertSame('contract.supply', $data['type_profile_code']);
            self::assertSame([
                'subject' => 'Поставка материалов',
                'buyer' => 'Заказчик МОСТ',
                'supplier' => 'Внешний поставщик МОСТ',
                'price' => 1250.0,
                'delivery_terms' => 'Поставка в течение 10 дней после оплаты',
            ], $data['metadata']);

            return true;
        })->andReturnUsing(function () use ($contract): LegalArchiveDocument {
            return LegalArchiveDocument::query()->create([
                'organization_id' => 7,
                'primary_project_id' => 11,
                'title' => 'Договор поставки',
                'document_type' => 'contract',
                'type_profile_code' => 'contract.supply',
                'source_type' => 'contract',
                'source_id' => (string) $contract->id,
                'source_idempotency_key' => 'e1ba7a5d-0cac-48b6-a40a-3f17251196a1',
            ]);
        });

        $result = $this->service($creator)->create($this->actor(), 7, 11, (int) $contract->id, [
            'title' => 'Договор поставки',
            'idempotency_key' => 'e1ba7a5d-0cac-48b6-a40a-3f17251196a1',
        ]);

        self::assertSame('created', $result->operationResult);
        self::assertSame((int) $result->document->id, (int) $contract->refresh()->legal_archive_document_id);
    }

    public function test_supply_dossier_rejects_incomplete_authoritative_contract_data_before_creating_any_binding(): void
    {
        Organization::query()->create(['id' => 7, 'name' => 'Заказчик МОСТ']);
        Supplier::query()->create(['id' => 55, 'organization_id' => 7, 'name' => 'Поставщик МОСТ']);
        $contract = $this->contract(['supplier_id' => 55]);
        $creator = Mockery::mock(ContractDossierDocumentCreator::class);
        $creator->shouldNotReceive('create');

        try {
            $this->service($creator)->create($this->actor(), 7, 11, (int) $contract->id, [
                'title' => 'Договор поставки',
                'idempotency_key' => 'cd4b28c4-2c0d-46c2-8b31-e9869baf406e',
                'metadata' => [
                    'subject' => 'Нельзя подменить предмет',
                    'buyer' => 'Нельзя подменить покупателя',
                    'supplier' => 'Нельзя подменить поставщика',
                    'price' => 1,
                    'delivery_terms' => 'Нельзя подменить условия',
                ],
            ]);
            self::fail('Incomplete contract source data must prevent supply dossier creation.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('metadata', $exception->errors());
        }

        self::assertNull($contract->refresh()->legal_archive_document_id);
        self::assertSame(0, LegalArchiveDocument::query()->count());
        self::assertSame(0, LegalArchiveDocumentLink::query()->count());
    }

    public function test_attach_replays_same_document_and_refuses_a_different_document_after_binding(): void
    {
        $contract = $this->contract();
        $document = $this->document();
        $service = $this->service();

        $attached = $service->attach($this->actor(), 7, 11, (int) $contract->id, (int) $document->id);
        $replayed = $service->attach($this->actor(), 7, 11, (int) $contract->id, (int) $document->id);

        self::assertSame('attached', $attached->operationResult);
        self::assertSame('replayed', $replayed->operationResult);
        self::assertSame((int) $document->id, (int) $contract->refresh()->legal_archive_document_id);
        self::assertSame(1, LegalArchiveDocumentLink::query()->count());

        $other = $this->document(['title' => 'Другой договор']);
        try {
            $service->attach($this->actor(), 7, 11, (int) $contract->id, (int) $other->id);
            self::fail('A different document must not replace the existing dossier binding.');
        } catch (DomainException $exception) {
            self::assertSame('contract_legal_dossier_already_bound', $exception->getMessage());
        }
        self::assertSame((int) $document->id, (int) $contract->refresh()->legal_archive_document_id);
        self::assertSame(1, LegalArchiveDocumentLink::query()->count());
    }

    public function test_attach_refuses_foreign_or_project_mismatched_document(): void
    {
        $contract = $this->contract();
        $service = $this->service();

        $foreign = $this->document(['organization_id' => 8]);
        try {
            $service->attach($this->actor(), 7, 11, (int) $contract->id, (int) $foreign->id);
            self::fail('A foreign document must not be attached.');
        } catch (DomainException $exception) {
            self::assertSame('contract_legal_dossier_document_unavailable', $exception->getMessage());
        }
        $this->assertUnbound($contract);
    }

    public function test_attach_refuses_document_from_another_project_or_contract(): void
    {
        $contract = $this->contract();
        $service = $this->service();
        $wrongProject = $this->document(['primary_project_id' => 12]);

        try {
            $service->attach($this->actor(), 7, 11, (int) $contract->id, (int) $wrongProject->id);
            self::fail('A document from another project must not be attached.');
        } catch (DomainException $exception) {
            self::assertSame('contract_legal_dossier_project_mismatch', $exception->getMessage());
        }
        $this->assertUnbound($contract);

        $linked = $this->document();
        LegalArchiveDocumentLink::query()->create([
            'document_id' => $linked->id,
            'organization_id' => 7,
            'link_type' => 'contract',
            'linked_type' => 'contract',
            'linked_id' => '999',
            'display_name' => 'Другой договор',
        ]);

        try {
            $service->attach($this->actor(), 7, 11, (int) $contract->id, (int) $linked->id);
            self::fail('A document linked to another contract must not be attached.');
        } catch (DomainException $exception) {
            self::assertSame('contract_legal_dossier_document_bound_elsewhere', $exception->getMessage());
        }
        $this->assertUnbound($contract, 1);

        $bound = $this->document(['title' => 'Уже прикреплённый договор']);
        Contract::query()->create([
            'organization_id' => 7,
            'project_id' => 11,
            'number' => 'ДП-12',
            'legal_archive_document_id' => $bound->id,
        ]);

        try {
            $service->attach($this->actor(), 7, 11, (int) $contract->id, (int) $bound->id);
            self::fail('A document bound by another contract must not be attached.');
        } catch (DomainException $exception) {
            self::assertSame('contract_legal_dossier_document_bound_elsewhere', $exception->getMessage());
        }
        $this->assertUnbound($contract, 1);
    }

    public function test_attach_refuses_forged_non_contract_profile_and_source_of_another_contract_without_mutation(): void
    {
        $contract = $this->contract();
        $service = $this->service();
        $forgedProfile = $this->document(['type_profile_code' => 'execution.act']);

        try {
            $service->attach($this->actor(), 7, 11, (int) $contract->id, (int) $forgedProfile->id);
            self::fail('A non-contract profile must not be attached as a contract dossier.');
        } catch (DomainException $exception) {
            self::assertSame('contract_legal_dossier_profile_invalid', $exception->getMessage());
        }
        $this->assertUnbound($contract);

        $foreignSource = $this->document([
            'source_type' => 'contract',
            'source_id' => '999',
        ]);
        try {
            $service->attach($this->actor(), 7, 11, (int) $contract->id, (int) $foreignSource->id);
            self::fail('A dossier sourced from another contract must not be attached.');
        } catch (DomainException $exception) {
            self::assertSame('contract_legal_dossier_source_invalid', $exception->getMessage());
        }
        $this->assertUnbound($contract);
    }

    public function test_attach_requires_object_level_document_permission(): void
    {
        $contract = $this->contract();
        $document = $this->document();
        $authorizer = Mockery::mock(LegalDocumentAuthorizer::class);
        $authorizer->shouldReceive('authorizePermission')->once()->andThrow(new \Illuminate\Auth\Access\AuthorizationException);
        $service = $this->service(authorizer: $authorizer);

        try {
            $service->attach($this->actor(), 7, 11, (int) $contract->id, (int) $document->id);
            self::fail('Object-level permission is mandatory for attachment.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
        }
        $this->assertUnbound($contract);
    }

    public function test_candidates_only_expose_attachable_documents_for_the_exact_contract_context_and_search(): void
    {
        $contract = $this->contract();
        $compatible = $this->document([
            'title' => 'Подходящий договор поставки',
            'type_profile_code' => 'contract.supply',
        ]);
        $this->document(['title' => 'Другой проект', 'primary_project_id' => 12]);
        $this->document(['title' => 'Другая организация', 'organization_id' => 8]);
        $this->document(['title' => 'Чужой источник', 'source_type' => 'contract', 'source_id' => '999']);

        $linkedElsewhere = $this->document(['title' => 'Связан с другим договором']);
        LegalArchiveDocumentLink::query()->create([
            'document_id' => $linkedElsewhere->id,
            'organization_id' => 7,
            'link_type' => 'contract',
            'linked_type' => 'contract',
            'linked_id' => '999',
            'display_name' => 'Другой договор',
        ]);

        $boundElsewhere = $this->document(['title' => 'Уже прикреплён']);
        Contract::query()->create([
            'organization_id' => 7,
            'project_id' => 11,
            'number' => 'ДП-12',
            'legal_archive_document_id' => $boundElsewhere->id,
        ]);

        $authorizer = Mockery::mock(LegalDocumentAuthorizer::class);
        $authorizer->shouldReceive('scopeAccessibleQuery')->once()->andReturnUsing(
            static fn ($query) => $query,
        );

        $candidates = $this->service(authorizer: $authorizer)->candidates(
            $this->actor(),
            7,
            11,
            (int) $contract->id,
            ['q' => 'подходящий', 'per_page' => 25],
        );

        self::assertSame(1, $candidates->total());
        self::assertSame([(int) $compatible->id], $candidates->pluck('id')->all());
    }

    public function test_candidates_hide_unavailable_contract_context(): void
    {
        $contract = $this->contract();

        $this->expectException(AuthorizationException::class);

        $this->service()->candidates($this->actor(), 7, 12, (int) $contract->id, []);
    }

    public function test_candidates_and_attach_reject_document_bound_to_a_soft_deleted_contract(): void
    {
        $contract = $this->contract();
        $document = $this->document(['title' => 'Досье удалённого договора']);
        $deletedContract = Contract::query()->create([
            'organization_id' => 7,
            'project_id' => 11,
            'number' => 'ДП-удалённый',
            'legal_archive_document_id' => $document->id,
        ]);
        $deletedContract->delete();

        $authorizer = Mockery::mock(LegalDocumentAuthorizer::class);
        $authorizer->shouldReceive('scopeAccessibleQuery')->once()->andReturnUsing(
            static fn ($query) => $query,
        );
        $candidates = $this->service(authorizer: $authorizer)->candidates(
            $this->actor(),
            7,
            11,
            (int) $contract->id,
            [],
        );

        self::assertSame([], $candidates->pluck('id')->all());

        try {
            $this->service()->attach($this->actor(), 7, 11, (int) $contract->id, (int) $document->id);
            self::fail('A document bound to a soft-deleted contract must remain unavailable.');
        } catch (DomainException $exception) {
            self::assertSame('contract_legal_dossier_document_bound_elsewhere', $exception->getMessage());
        }
    }

    public function test_candidates_request_requires_contract_and_legal_archive_update_permissions(): void
    {
        $actor = $this->actor();
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->once()->with($actor, 'contracts.edit', [
            'organization_id' => 7,
            'project_id' => 11,
        ])->andReturnTrue();
        $authorization->shouldReceive('can')->once()->with($actor, 'legal_archive.update', [
            'organization_id' => 7,
            'project_id' => 11,
        ])->andReturnFalse();

        $container = new Container;
        $container->instance(AuthorizationService::class, $authorization);
        $previousContainer = Container::getInstance();
        Container::setInstance($container);
        try {
            $request = ListContractLegalDossierCandidatesRequest::create('/api/v1/admin/projects/11/contracts/1/legal-dossier/candidates');
            $request->attributes->set('current_organization_id', 7);
            $request->setUserResolver(static fn (): User => $actor);
            $request->setRouteResolver(static fn (): Route => new Route(['GET'], '/api/v1/admin/projects/{project}', ['project' => 11]));

            self::assertFalse($request->authorize());
        } finally {
            Container::setInstance($previousContainer);
        }
    }

    private function service(
        ?ContractDossierDocumentCreator $creator = null,
        ?LegalDocumentAuthorizer $authorizer = null,
    ): ContractLegalDossierService {
        $audit = Mockery::mock(LegalDocumentAudit::class)->shouldIgnoreMissing();

        return new ContractLegalDossierService(
            $this->database->getConnection(),
            new ContractAuditedMutationService($audit, $this->database->getConnection()),
            $creator ?? Mockery::mock(ContractDossierDocumentCreator::class),
            $authorizer ?? Mockery::mock(LegalDocumentAuthorizer::class)->shouldIgnoreMissing(),
            new LegalDocumentProfileRegistry,
            new LegalDocumentProfileValidator,
        );
    }

    /** @param array<string, mixed> $attributes */
    private function contract(array $attributes = []): Contract
    {
        return Contract::query()->create([
            'organization_id' => 7,
            'project_id' => 11,
            'number' => 'ДП-11',
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function document(array $attributes = []): LegalArchiveDocument
    {
        return LegalArchiveDocument::query()->create([
            'organization_id' => 7,
            'primary_project_id' => 11,
            'title' => 'Договор',
            'document_type' => 'contract',
            'type_profile_code' => 'contract.work',
            ...$attributes,
        ]);
    }

    private function actor(): User
    {
        $actor = new User;
        $actor->forceFill(['id' => 3, 'current_organization_id' => 7]);

        return $actor;
    }

    private function assertUnbound(Contract $contract, int $expectedLinks = 0): void
    {
        self::assertNull($contract->refresh()->legal_archive_document_id);
        self::assertSame($expectedLinks, LegalArchiveDocumentLink::query()->count());
    }
}
