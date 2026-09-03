<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Domain\Authorization\Http\Middleware\AuthorizeMiddleware;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAccessService;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\Audit\LegalDocumentAudit;
use App\Services\LegalArchive\Profiles\LegalDocumentProfileRegistry;
use App\Services\LegalArchive\Profiles\LegalDocumentProfileValidator;
use App\Services\LegalArchive\Profiles\LegalDocumentTypeProfileService;
use App\Services\Project\UserProjectAccessService;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

final class LegalArchiveApiContractTest extends TestCase
{
    private string $originalConnection;

    private AuthorizationService $authorization;

    private bool $permissionAllowed = true;

    /** @var list<string> */
    private array $deniedPermissions = [];

    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = DB::getDefaultConnection();
        config()->set(
            'database.connections.legal_api_contract',
            \Tests\Support\IsolatedPostgresTestDatabase::configuration(),
        );
        DB::purge('legal_api_contract');
        DB::setDefaultConnection('legal_api_contract');
        $this->createSchema();
        $this->authorization = Mockery::mock(AuthorizationService::class);
        $this->authorization->shouldReceive('can')->andReturnUsing(
            fn (User $user, string $permission): bool => $this->permissionAllowed
                && ! in_array($permission, $this->deniedPermissions, true),
        );
        $this->authorization->shouldReceive('getUserRoleSlugs')->andReturn([]);
        $this->authorization->shouldReceive('canAccessInterface')->andReturnTrue();
        $this->app->instance(AuthorizationService::class, $this->authorization);
        LegalArchiveApiContractAuthorizeMiddleware::$authorization = $this->authorization;
        $this->app->instance(LegalDocumentAudit::class, new LegalArchiveApiContractAudit);

        $projectAccess = Mockery::mock(UserProjectAccessService::class);
        $projectAccess->shouldReceive('queryAccessibleProjects')->andReturnUsing(
            static fn (): \Illuminate\Database\Eloquent\Builder => \App\Models\Project::query(),
        );
        $access = new LegalDocumentAccessService(
            $this->authorization,
            static fn (User $user, int $organizationId): bool => (int) $user->current_organization_id === $organizationId,
            static fn (): bool => true,
            $projectAccess,
        );
        $this->app->instance(LegalDocumentAccessService::class, $access);
        $this->app->instance(LegalDocumentAuthorizer::class, $access);
    }

    protected function tearDown(): void
    {
        LegalArchiveApiContractActorMiddleware::$actor = null;
        LegalArchiveApiContractAuthorizeMiddleware::$authorization = null;
        DB::setDefaultConnection($this->originalConnection);
        DB::purge('legal_api_contract');
        parent::tearDown();
    }

    public function test_canonical_routes_use_real_admin_stack_and_exact_permissions(): void
    {
        $expected = [
            'admin.legal-archive.documents.store' => 'authorize:legal_archive.create',
            'admin.legal-archive.documents.files.store' => 'authorize:legal_archive.files.upload',
            'admin.legal-archive.workflow.submit' => 'authorize:legal_archive.workflow.submit',
            'admin.legal-archive.documents.available-actions' => 'authorize:legal_archive.workflow.view',
            'admin.legal-archive.signatures.requests.store' => 'authorize:legal_archive.signatures.request',
            'admin.legal-archive.signatures.index' => 'authorize:legal_archive.signatures.view',
            'admin.legal-archive.signatures.verification-history' => 'authorize:legal_archive.signatures.view',
            'admin.legal-archive.access.store' => 'authorize:legal_archive.external_access.manage',
            'admin.legal-archive.retention.update' => 'authorize:legal_archive.retention.manage',
            'admin.legal-archive.type-profiles.store' => 'authorize:legal_archive.settings.manage',
            'admin.legal-archive.type-profiles.show' => 'authorize:legal_archive.view',
            'admin.legal-archive.workflow-templates.show' => 'authorize:legal_archive.view',
        ];

        foreach ($expected as $name => $permission) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route, $name);
            self::assertContains('admin.response', $route->gatherMiddleware());
            self::assertContains($permission, $route->gatherMiddleware());
        }
    }

    public function test_domain_specific_route_parameters_bypass_global_bindings_and_reach_real_controller(): void
    {
        $request = Request::create(
            '/api/v1/admin/legal-archive/document-file-versions/999999/signatures',
            'GET',
        );
        $request->attributes->set('current_organization_id', 7);
        $route = Route::getRoutes()->match($request);
        $route->bind($request);

        self::assertSame('999999', $route->parameter('documentVersion'));
        self::assertNull($route->parameter('version'));
        self::assertSame(404, $route->run()->getStatusCode());

        $templateRequest = Request::create(
            '/api/v1/admin/legal-archive/workflow-templates/999999/versions',
            'POST',
        );
        $templateRoute = Route::getRoutes()->match($templateRequest);
        $templateRoute->bind($templateRequest);

        self::assertSame('999999', $templateRoute->parameter('legalWorkflowTemplate'));
        self::assertNull($templateRoute->parameter('template'));
    }

    public function test_list_detail_and_actions_run_through_canonical_routes_real_services_and_permission_middleware(): void
    {
        DB::table('legal_archive_document_type_profiles')->insert([
            'id' => 'b81f7350-c0b1-4e06-a1cb-8662b23eab01',
            'organization_id' => 7,
            'code' => 'customer.supply',
            'base_code' => 'contract.supply',
            'name' => 'Специальная поставка',
            'retention_policy' => 'Пять лет после исполнения договора',
            'schema' => '[]',
            'required_fields' => '[]',
            'required_file_roles' => '["appendix"]',
            'is_active' => true,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('legal_archive_document_type_profiles')->insert([
            'id' => 'b81f7350-c0b1-4e06-a1cb-8662b23eab08',
            'organization_id' => 8,
            'code' => 'customer.supply',
            'base_code' => 'contract.supply',
            'name' => 'Поставка владельца 8',
            'retention_policy' => 'По номенклатуре другой организации',
            'schema' => '[]',
            'required_fields' => '[]',
            'required_file_roles' => '[]',
            'is_active' => true,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $owned = $this->documentRow(42, 7, 'Договор поставки');
        $owned['type_profile_code'] = 'customer.supply';
        $owned['primary_project_id'] = 11;
        $retired = $this->documentRow(45, 7, 'Архивный профиль');
        $retired['type_profile_code'] = 'retired.profile';
        $external = $this->documentRow(43, 8, 'Внешний договор');
        $external['type_profile_code'] = 'customer.supply';
        DB::table('legal_archive_documents')->insert([
            $owned,
            $retired,
            $external,
            $this->documentRow(44, 8, 'Недоступный договор'),
        ]);
        DB::table('projects')->insert([
            'id' => 11,
            'organization_id' => 7,
            'name' => 'Тестовый проект',
            'status' => 'active',
        ]);
        DB::table('contracts')->insert([
            'id' => 9,
            'organization_id' => 7,
            'project_id' => 11,
            'legal_archive_document_id' => 42,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('legal_document_access_grants')->insert([
            'organization_id' => 8,
            'document_id' => 43,
            'subject_organization_id' => 7,
            'subject_user_id' => null,
            'subject_kind' => 'external_org',
            'subject_role_slug' => null,
            'abilities' => json_encode(['view'], JSON_THROW_ON_ERROR),
            'granted_by_user_id' => 9,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor = $this->actor();

        $list = $this->runCanonical(Request::create('/api/v1/admin/legal-archive/documents?per_page=100', 'GET'), $actor);
        self::assertSame(200, $list->getStatusCode());
        self::assertSame([45, 43, 42], array_column($list->getData(true)['data'], 'id'));
        self::assertSame(100, $list->getData(true)['meta']['per_page']);
        $listed = collect($list->getData(true)['data'])->keyBy('id');
        self::assertSame('customer.supply', $listed[42]['type_profile']['code']);
        self::assertSame('Специальная поставка', $listed[42]['type_profile']['label']);
        self::assertSame('Пять лет после исполнения договора', $listed[42]['type_profile']['retention_policy'] ?? null);
        self::assertSame('По номенклатуре другой организации', $listed[43]['type_profile']['retention_policy'] ?? null);
        self::assertSame('retired.profile', $listed[45]['type_profile']['code']);
        self::assertSame('Поставка владельца 8', $listed[43]['type_profile']['label']);
        self::assertSame('not_available', $listed[43]['workflow_summary']['status']);
        self::assertContains('workflow_permission_denied', $listed[43]['workflow_summary']['problem_flags']);
        self::assertSame('submit', $listed[42]['workflow_summary']['available_action_details'][0]['action']);
        self::assertSame(0, $listed[42]['completeness']['files']);
        self::assertContains('no_files', $listed[42]['problem_flags']);

        $this->deniedPermissions = ['legal_archive.workflow.view'];
        $viewerList = $this->runCanonical(Request::create('/api/v1/admin/legal-archive/documents?per_page=100', 'GET'), $actor);
        self::assertSame(200, $viewerList->getStatusCode());
        $viewerDocuments = collect($viewerList->getData(true)['data'])->keyBy('id');
        self::assertSame('not_available', $viewerDocuments[42]['workflow_summary']['status']);
        self::assertSame('legal_archive.workflow.view', $viewerDocuments[42]['workflow_summary']['available_action_details'][0]['permission']);
        $this->deniedPermissions = [];

        $detailDocument = $this->app->make(\App\Services\LegalArchive\LegalArchiveRegistryService::class)
            ->findForAuthorization(42);
        self::assertNotNull($detailDocument);
        $this->app->make(\App\Services\LegalArchive\Workflow\LegalWorkflowActionResolver::class)
            ->forMany($actor, collect([$detailDocument]));
        $this->app->make(\App\Services\LegalArchive\Editor\LegalDocumentEditorAvailability::class)
            ->currentVersionEditable($detailDocument);

        $detail = $this->runCanonical(Request::create('/api/v1/admin/legal-archive/documents/42', 'GET'), $actor);
        self::assertSame(200, $detail->getStatusCode(), (string) $detail->getContent());
        self::assertSame('42', (string) $detail->getData(true)['data']['id']);
        self::assertSame([['role' => 'appendix', 'label' => 'Приложение', 'ready' => false]], $detail->getData(true)['data']['file_requirements'] ?? null);
        self::assertSame('Пять лет после исполнения договора', $detail->getData(true)['data']['type_profile']['retention_policy'] ?? null);
        self::assertNull($detail->getData(true)['data']['retention']['policy']);
        self::assertSame('"legal-document-42-v3"', $detail->headers->get('ETag'));

        $contractDetail = $this->runCanonical(
            Request::create('/api/v1/admin/projects/11/contracts/9/documents/42', 'GET'),
            $actor,
        );
        self::assertSame(200, $contractDetail->getStatusCode(), (string) $contractDetail->getContent());
        self::assertTrue($contractDetail->getData(true)['data']['type_profile_configured']);
        self::assertSame('Специальная поставка', $contractDetail->getData(true)['data']['type_profile']['label']);
        self::assertSame('Пять лет после исполнения договора', $contractDetail->getData(true)['data']['type_profile']['retention_policy'] ?? null);
        self::assertSame([['role' => 'appendix', 'label' => 'Приложение', 'ready' => false]], $contractDetail->getData(true)['data']['file_requirements'] ?? null);

        $externalDetail = $this->runCanonical(Request::create('/api/v1/admin/legal-archive/documents/43', 'GET'), $actor);
        self::assertSame(200, $externalDetail->getStatusCode());
        self::assertSame('Поставка владельца 8', $externalDetail->getData(true)['data']['type_profile']['label']);
        self::assertSame('not_available', $externalDetail->getData(true)['data']['workflow_summary']['status']);
        self::assertSame([], $externalDetail->getData(true)['data']['file_requirements'] ?? null);

        $foreign = $this->runCanonical(Request::create('/api/v1/admin/legal-archive/documents/44', 'GET'), $actor);
        self::assertSame(404, $foreign->getStatusCode());

        $actions = $this->runCanonical(Request::create('/api/v1/admin/legal-archive/documents/42/available-actions', 'GET'), $actor);
        self::assertSame(200, $actions->getStatusCode());
        $submitAction = $actions->getData(true)['data']['workflow_summary']['available_action_details'][0];
        self::assertSame('submit', $submitAction['action']);
        self::assertFalse($submitAction['enabled']);
        self::assertContains('Для этого вида документа не настроен маршрут согласования', $submitAction['blockers']);
        self::assertNotContains('legal_archive.workflow.blockers.route_not_configured', $submitAction['blockers']);

        $this->permissionAllowed = false;
        $denied = $this->runCanonical(Request::create('/api/v1/admin/legal-archive/documents/42/available-actions', 'GET'), $actor);
        self::assertSame(403, $denied->getStatusCode());
    }

    public function test_draft_profile_change_preserves_document_data_and_exposes_assignment(): void
    {
        $this->isolateKernelAuthentication([
            ['PATCH', '/api/v1/admin/legal-archive/documents/42'],
            ['GET', '/api/v1/admin/legal-archive/documents/42'],
        ]);
        DB::table('legal_archive_documents')->insert([
            ...$this->documentRow(42, 7, 'Поставка крепежа'),
            'structured_fields' => json_encode(['subject' => 'Поставка крепежа'], JSON_THROW_ON_ERROR),
            'retention_policy' => 'Правило организации',
        ]);
        $this->app->make(LegalDocumentTypeProfileService::class)->create(7, [
            'code' => 'qa.supply.appendix',
            'base_code' => 'contract.supply',
            'name' => 'Поставка с приложением',
            'required_file_roles' => ['appendix'],
            'confidentiality_level' => 'public',
        ]);

        $detail = $this->runCanonical(Request::create('/api/v1/admin/legal-archive/documents/42', 'GET'), $this->actor());
        self::assertSame(200, $detail->getStatusCode(), (string) $detail->getContent());
        self::assertTrue($detail->getData(true)['data']['profile_assignment']['allowed']);
        DB::table('legal_archive_documents')->where('id', 42)->update(['legal_hold' => true]);
        $headers = $this->kernelHeaders();
        $this->patchJson('/api/v1/admin/legal-archive/documents/42', [
            'lock_version' => 3,
            'type_profile_code' => 'qa.supply.appendix',
        ], $headers)->assertOk()
            ->assertJsonPath('data.type_profile.code', 'qa.supply.appendix')
            ->assertJsonPath('data.structured_fields.subject', 'Поставка крепежа')
            ->assertJsonPath('data.retention.policy', 'Правило организации')
            ->assertJsonPath('data.retention.legal_hold', true)
            ->assertJsonPath('data.file_requirements.0.role', 'appendix')
            ->assertJsonPath('data.file_requirements.0.ready', false);
        self::assertSame('internal', DB::table('legal_archive_documents')->where('id', 42)->value('confidentiality_level'));
    }

    public function test_profile_change_rejects_an_unrelated_base_without_mutation(): void
    {
        $this->isolateKernelAuthentication([['PATCH', '/api/v1/admin/legal-archive/documents/42']]);
        $headers = $this->kernelHeaders();
        DB::table('legal_archive_documents')->insert($this->documentRow(42, 7, 'Поставка крепежа'));

        $this->patchJson('/api/v1/admin/legal-archive/documents/42', [
            'lock_version' => 3,
            'type_profile_code' => 'other.custom',
        ], $headers)->assertStatus(409)->assertJsonPath('message', trans_message('legal_archive.domain_errors.profile_base_change_not_allowed'));
        self::assertSame('contract.supply', DB::table('legal_archive_documents')->where('id', 42)->value('type_profile_code'));
        self::assertSame(3, DB::table('legal_archive_documents')->where('id', 42)->value('lock_version'));
    }

    public function test_profile_change_does_not_orphan_populated_custom_fields(): void
    {
        $this->isolateKernelAuthentication([['PATCH', '/api/v1/admin/legal-archive/documents/42']]);
        $headers = $this->kernelHeaders();
        $this->app->make(LegalDocumentTypeProfileService::class)->create(7, [
            'code' => 'qa.supply.packaging',
            'base_code' => 'contract.supply',
            'name' => 'Поставка с упаковкой',
            'schema' => ['packaging' => ['type' => 'string', 'label' => 'Упаковка']],
        ]);
        DB::table('legal_archive_documents')->insert([
            ...$this->documentRow(42, 7, 'Поставка крепежа'),
            'type_profile_code' => 'qa.supply.packaging',
            'structured_fields' => json_encode(['packaging' => 'Коробка'], JSON_THROW_ON_ERROR),
        ]);

        $this->patchJson('/api/v1/admin/legal-archive/documents/42', [
            'lock_version' => 3,
            'type_profile_code' => 'contract.supply',
        ], $headers)->assertStatus(409)->assertJsonPath('message', trans_message('legal_archive.domain_errors.profile_existing_fields_incompatible'));
        $document = LegalArchiveDocument::query()->findOrFail(42);
        self::assertSame('qa.supply.packaging', $document->type_profile_code);
        self::assertSame(['packaging' => 'Коробка'], $document->structured_fields);
        self::assertSame(3, $document->lock_version);
    }

    public function test_http_kernel_enforces_validation_mutation_replay_conflict_and_resolvable_etags(): void
    {
        $this->isolateKernelAuthentication([
            ['PATCH', '/api/v1/admin/legal-archive/documents/42'],
            ['GET', '/api/v1/admin/legal-archive/type-profiles/contract.supply'],
            ['GET', '/api/v1/admin/legal-archive/workflow-templates/71'],
        ]);
        $headers = $this->kernelHeaders();
        DB::table('legal_archive_documents')->insert([
            $this->documentRow(42, 7, 'Исходный договор'),
            $this->documentRow(43, 8, 'Чужой договор'),
        ]);

        $this->deniedPermissions = ['legal_archive.update'];
        $denied = $this->patchJson('/api/v1/admin/legal-archive/documents/42', [
            'lock_version' => 3,
            'title' => 'Запрещённое изменение',
        ], $headers);
        self::assertSame(403, $denied->getStatusCode(), (string) $denied->getContent());
        $this->deniedPermissions = [];

        $invalid = $this->patchJson('/api/v1/admin/legal-archive/documents/42', ['title' => 'Без версии'], $headers);
        $invalid->assertStatus(422)->assertJsonValidationErrors(['lock_version']);

        $updated = $this->patchJson('/api/v1/admin/legal-archive/documents/42', [
            'lock_version' => 3,
            'title' => 'Обновлённый договор',
        ], $headers);
        self::assertSame(200, $updated->getStatusCode(), (string) $updated->getContent());
        $updated->assertJsonPath('data.title', 'Обновлённый договор');
        self::assertSame('"legal-document-42-v4"', $updated->headers->get('ETag'));
        self::assertSame('/api/v1/admin/legal-archive/documents/42', $updated->headers->get('Location'));

        $replay = $this->patchJson('/api/v1/admin/legal-archive/documents/42', [
            'lock_version' => 3,
            'title' => 'Повтор команды',
        ], $headers);
        $replay->assertStatus(409)
            ->assertJsonPath('current_lock_version', 4)
            ->assertJsonPath('aggregate_kind', 'legal_document')
            ->assertJsonPath('aggregate_id', '42');
        self::assertSame('"legal-document-42-v4"', $replay->headers->get('ETag'));
        self::assertSame('/api/v1/admin/legal-archive/documents/42', $replay->headers->get('Location'));
        self::assertSame('Обновлённый договор', DB::table('legal_archive_documents')->where('id', 42)->value('title'));

        $foreign = $this->patchJson('/api/v1/admin/legal-archive/documents/43', [
            'lock_version' => 3,
            'title' => 'Попытка изменения',
        ], $headers);
        $foreign->assertNotFound();

        $standard = $this->getJson('/api/v1/admin/legal-archive/type-profiles/contract.supply', $headers);
        $standard->assertOk()->assertJsonPath('data.code', 'contract.supply');
        self::assertSame('/api/v1/admin/legal-archive/type-profiles/contract.supply', $standard->headers->get('Location'));
        $standardReloaded = $this->getJson((string) $standard->headers->get('Location'), $headers);
        $standardReloaded->assertOk();
        self::assertSame($standard->headers->get('ETag'), $standardReloaded->headers->get('ETag'));

        DB::table('legal_workflow_templates')->insert([
            'id' => 71,
            'organization_id' => 7,
            'code' => 'supply-approval',
            'version' => 1,
            'name' => 'Согласование поставки',
            'definition_hash' => str_repeat('a', 64),
            'created_by_user_id' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('legal_workflow_template_steps')->insert([
            'template_id' => 71,
            'organization_id' => 7,
            'step_key' => 'legal',
            'label' => 'Юрист',
            'sequence' => 1,
            'parallel_group' => 'legal',
            'required' => true,
            'actor_type' => 'role',
            'actor_reference' => 'legal_reviewer',
            'settings' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('legal_workflow_template_heads')->insert([
            'organization_id' => 7,
            'code' => 'supply-approval',
            'template_id' => 71,
        ]);
        $template = $this->getJson('/api/v1/admin/legal-archive/workflow-templates/71', $headers);
        $template->assertOk()->assertJsonPath('data.is_current', true)->assertJsonCount(1, 'data.steps');
        self::assertSame('/api/v1/admin/legal-archive/workflow-templates/71', $template->headers->get('Location'));
        $templateReloaded = $this->getJson((string) $template->headers->get('Location'), $headers);
        $templateReloaded->assertOk();
        self::assertSame($template->headers->get('ETag'), $templateReloaded->headers->get('ETag'));
        DB::table('legal_workflow_template_heads')->where('template_id', 71)->delete();
        $noLongerCurrent = $this->getJson((string) $template->headers->get('Location'), $headers);
        $noLongerCurrent->assertOk()->assertJsonPath('data.is_current', false);
        self::assertNotSame($template->headers->get('ETag'), $noLongerCurrent->headers->get('ETag'));
    }

    public function test_profile_change_keeps_permission_revision_and_approval_guards(): void
    {
        $this->isolateKernelAuthentication([['PATCH', '/api/v1/admin/legal-archive/documents/42']]);
        $headers = $this->kernelHeaders();
        DB::table('legal_archive_documents')->insert($this->documentRow(42, 7, 'Поставка крепежа'));
        $this->app->make(LegalDocumentTypeProfileService::class)->create(7, [
            'code' => 'qa.supply.guarded',
            'base_code' => 'contract.supply',
            'name' => 'Поставка с проверкой',
        ]);
        $payload = ['lock_version' => 3, 'type_profile_code' => 'qa.supply.guarded'];
        $this->deniedPermissions = ['legal_archive.update'];
        $this->patchJson('/api/v1/admin/legal-archive/documents/42', $payload, $headers)->assertForbidden();
        $this->deniedPermissions = [];
        $this->patchJson('/api/v1/admin/legal-archive/documents/42', [...$payload, 'lock_version' => 2], $headers)
            ->assertStatus(409)->assertJsonPath('current_lock_version', 3);
        DB::table('legal_archive_documents')->where('id', 42)->update(['approval_status' => 'approved']);
        $this->patchJson('/api/v1/admin/legal-archive/documents/42', $payload, $headers)->assertStatus(409);
        self::assertSame('contract.supply', DB::table('legal_archive_documents')->where('id', 42)->value('type_profile_code'));
        self::assertSame(3, DB::table('legal_archive_documents')->where('id', 42)->value('lock_version'));
    }

    public function test_signature_verification_history_is_bounded_tenant_scoped_and_permission_protected(): void
    {
        DB::table('legal_archive_documents')->insert($this->documentRow(42, 7, 'Подписанный договор'));
        DB::table('legal_document_signatures')->insert([
            'id' => 91,
            'organization_id' => 7,
            'document_id' => 42,
            'document_version_id' => 501,
            'verification_status' => 'valid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('legal_signature_verifications')->insert([
            [
                'id' => 101,
                'organization_id' => 7,
                'document_id' => 42,
                'document_version_id' => 501,
                'signature_id' => 91,
                'status' => 'valid',
                'verified_at' => now()->subMinute(),
                'created_at' => now()->subMinute(),
                'updated_at' => now()->subMinute(),
            ],
            [
                'id' => 102,
                'organization_id' => 7,
                'document_id' => 42,
                'document_version_id' => 501,
                'signature_id' => 91,
                'status' => 'revoked',
                'verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $history = $this->runCanonical(Request::create(
            '/api/v1/admin/legal-archive/signatures/91/verification-history?per_page=1',
            'GET',
        ), $this->actor());
        self::assertSame(200, $history->getStatusCode());
        self::assertSame(102, $history->getData(true)['data'][0]['id']);
        self::assertSame(1, $history->getData(true)['meta']['per_page']);
        self::assertSame(2, $history->getData(true)['meta']['total']);

        $this->permissionAllowed = false;
        $denied = $this->runCanonical(Request::create(
            '/api/v1/admin/legal-archive/signatures/91/verification-history',
            'GET',
        ), $this->actor());
        self::assertSame(403, $denied->getStatusCode());
    }

    public function test_profile_creation_derives_safe_defaults_and_rejects_duplicate_code(): void
    {
        $service = new LegalDocumentTypeProfileService(
            DB::connection(),
            new LegalDocumentProfileRegistry,
            new LegalDocumentProfileValidator,
        );
        $profile = $service->create(7, [
            'code' => 'customer.minimal',
            'base_code' => 'contract.supply',
            'name' => 'Минимальный профиль',
            'confidentiality_level' => null,
        ]);

        self::assertSame([], $profile->schema);
        self::assertSame([], $profile->required_fields);
        self::assertNull($profile->requires_signature);
        self::assertNull($profile->workflow_template_id);
        self::assertSame('internal', $profile->confidentiality_level);

        $profile = $service->update(7, (string) $profile->id, 0, [
            'confidentiality_level' => null,
        ]);

        self::assertSame('internal', $profile->confidentiality_level);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('profile_code_duplicate');
        $service->create(7, [
            'code' => 'customer.minimal',
            'base_code' => 'contract.supply',
            'name' => 'Дубликат',
        ]);
    }

    public function test_profile_creation_can_configure_a_standard_document_code_for_an_organization(): void
    {
        $registry = new LegalDocumentProfileRegistry;
        $service = new LegalDocumentTypeProfileService(
            DB::connection(),
            $registry,
            new LegalDocumentProfileValidator,
        );

        $profile = $service->create(7, [
            'code' => 'contract.supply',
            'base_code' => 'contract.supply',
            'name' => 'Договор поставки с согласованием',
        ]);

        $resolved = $registry->find(7, 'contract.supply');
        $bulkResolved = $registry->findManyForOrganizations([7 => ['contract.supply']]);

        self::assertSame('contract.supply', $profile->code);
        self::assertSame('Договор поставки с согласованием', $resolved->label);
        self::assertTrue($resolved->requiresSignature);
        self::assertContains('delivery_terms', $resolved->requiredFields);
        self::assertSame('Договор поставки с согласованием', $bulkResolved[7]['contract.supply']->label);
    }

    private function runCanonical(Request $request, User $actor): JsonResponse
    {
        $request->attributes->set('current_organization_id', 7);
        $route = Route::getRoutes()->match($request);
        $route->bind($request);
        $request->setRouteResolver(static fn (): RoutingRoute => $route);
        $this->app->instance('request', $request);
        $request->setUserResolver(static fn (): User => $actor);

        $next = static fn (): JsonResponse => $route->run();
        foreach (array_reverse($route->gatherMiddleware()) as $middleware) {
            if (! is_string($middleware) || ! str_starts_with($middleware, 'authorize:')) {
                continue;
            }
            $permission = substr($middleware, strlen('authorize:'));
            $downstream = $next;
            $next = fn (): JsonResponse => (new AuthorizeMiddleware($this->authorization))->handle(
                $request,
                static fn (): JsonResponse => $downstream(),
                $permission,
            );
        }

        return $next();
    }

    private function actor(): User
    {
        $actor = new User;
        $actor->forceFill(['id' => 5, 'current_organization_id' => 7, 'is_active' => true]);
        $actor->exists = true;

        return $actor;
    }

    /** @return array<string, string> */
    private function kernelHeaders(): array
    {
        DB::table('organizations')->insert([
            'id' => 7,
            'name' => 'Организация 7',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('users')->insert([
            'id' => 5,
            'name' => 'Администратор архива',
            'email' => 'legal-archive@example.test',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('organization_user')->insert([
            'organization_id' => 7,
            'user_id' => 5,
            'is_owner' => true,
            'is_active' => true,
            'settings' => json_encode([], JSON_THROW_ON_ERROR),
            'project_access_mode' => 'all',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('authorization_contexts')->insert([
            [
                'id' => 1,
                'type' => 'system',
                'resource_id' => null,
                'parent_context_id' => null,
                'metadata' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'type' => 'organization',
                'resource_id' => 7,
                'parent_context_id' => 1,
                'metadata' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        $actor = User::query()->findOrFail(5);
        $actor->current_organization_id = 7;
        LegalArchiveApiContractActorMiddleware::$actor = $actor;
        $this->actingAs($actor, 'api_admin');

        return [
            'Accept' => 'application/json',
        ];
    }

    /** @param list<array{0:string,1:string}> $requests */
    private function isolateKernelAuthentication(array $requests): void
    {
        foreach ($requests as [$method, $uri]) {
            $route = Route::getRoutes()->match(Request::create($uri, $method));
            $action = $route->getAction();
            $action['middleware'] = array_values(array_map(
                static fn (string $middleware): string => str_starts_with($middleware, 'authorize:')
                    ? LegalArchiveApiContractAuthorizeMiddleware::class.substr($middleware, strlen('authorize'))
                    : $middleware,
                array_filter(
                    $route->gatherMiddleware(),
                    static fn (string $middleware): bool => $middleware !== 'api'
                    && ! str_starts_with($middleware, 'auth:')
                    && ! str_starts_with($middleware, 'auth.jwt:')
                    && $middleware !== 'auth.session'
                    && $middleware !== 'organization.context'
                    && ! str_starts_with($middleware, 'interface:'),
                ),
            ));
            $route->setAction($action);
            $route->computedMiddleware = null;
        }
    }

    /** @return array<string, mixed> */
    private function documentRow(int $id, int $organizationId, string $title): array
    {
        return [
            'id' => $id,
            'organization_id' => $organizationId,
            'primary_project_id' => null,
            'title' => $title,
            'document_type' => 'contract',
            'type_profile_code' => 'contract.supply',
            'status' => 'draft',
            'lifecycle_status' => 'draft',
            'approval_status' => 'not_submitted',
            'signature_status' => 'not_signed',
            'confidentiality_level' => 'internal',
            'direction' => 'incoming',
            'source_system' => 'most',
            'legal_significance_status' => 'not_confirmed',
            'source_create_status' => 'completed',
            'source_create_attempt_count' => 1,
            'legal_hold' => false,
            'lock_version' => 3,
            'created_by_user_id' => 5,
            'owner_user_id' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function createSchema(): void
    {
        $schema = Schema::connection('legal_api_contract');
        $schema->create('projects', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('name')->nullable();
            $table->string('status')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->softDeletes();
        });
        $schema->create('contracts', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('legal_archive_document_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('organizations', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('organization_user', static function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id');
            $table->boolean('is_owner')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->string('project_access_mode')->nullable();
            $table->timestamps();
        });
        $schema->create('authorization_contexts', static function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->unsignedBigInteger('parent_context_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        $schema->create('legal_archive_documents', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('primary_project_id')->nullable();
            $table->unsignedBigInteger('current_primary_version_id')->nullable();
            $table->string('title');
            $table->string('document_number')->nullable();
            $table->string('document_type')->nullable();
            $table->string('type_profile_code')->nullable();
            $table->string('status')->nullable();
            $table->string('lifecycle_status')->nullable();
            $table->string('approval_status')->nullable();
            $table->string('signature_status')->nullable();
            $table->string('confidentiality_level')->nullable();
            $table->string('direction')->nullable();
            $table->string('source_system')->nullable();
            $table->string('counterparty_name')->nullable();
            $table->date('document_date')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->text('description')->nullable();
            $table->string('legal_significance_status')->nullable();
            $table->string('edo_status')->nullable();
            $table->string('one_c_status')->nullable();
            $table->string('retention_policy')->nullable();
            $table->text('retention_basis')->nullable();
            $table->timestamp('retention_started_at')->nullable();
            $table->timestamp('retention_until')->nullable();
            $table->boolean('legal_hold')->default(false);
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->string('source_create_status')->default('completed');
            $table->uuid('create_operation_id')->nullable();
            $table->string('source_create_retry_action')->nullable();
            $table->unsignedInteger('source_create_attempt_count')->default(0);
            $table->timestamp('source_create_started_at')->nullable();
            $table->timestamp('source_create_heartbeat_at')->nullable();
            $table->timestamp('source_create_lease_expires_at')->nullable();
            $table->timestamp('source_create_failed_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->jsonb('structured_fields')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('legal_archive_document_versions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('file_id')->nullable();
            $table->unsignedBigInteger('document_file_id')->nullable();
            $table->unsignedInteger('version_number')->default(1);
            $table->string('processing_status')->nullable();
            $table->string('content_hash')->nullable();
            $table->boolean('is_current')->default(false);
            $table->timestamps();
        });
        $schema->create('legal_archive_document_files', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->string('role')->nullable();
            $table->string('title')->nullable();
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        $schema->create('legal_archive_document_links', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->string('link_type');
            $table->string('linked_type')->nullable();
            $table->unsignedBigInteger('linked_id')->nullable();
            $table->string('display_name')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        $schema->create('legal_workflow_instances', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_version_id')->nullable();
            $table->string('document_content_hash')->nullable();
            $table->string('status')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamp('due_at')->nullable();
            $table->timestamps();
        });
        $schema->create('legal_workflow_steps', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('workflow_instance_id');
            $table->string('status')->nullable();
            $table->string('actor_type')->nullable();
            $table->string('actor_reference')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamp('due_at')->nullable();
            $table->timestamps();
        });
        foreach (['legal_signature_requests', 'legal_document_signatures'] as $name) {
            $schema->create($name, static function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('document_id');
                $table->unsignedBigInteger('document_version_id')->nullable();
                $table->string('status')->nullable();
                $table->string('verification_status')->nullable();
                $table->timestamps();
            });
        }
        $schema->create('legal_signature_verifications', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_version_id');
            $table->unsignedBigInteger('signature_id');
            $table->string('provider')->nullable();
            $table->string('status');
            $table->string('signed_content_hash')->nullable();
            $table->json('certificate_metadata')->nullable();
            $table->json('provider_metadata')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->unsignedBigInteger('verified_by_user_id')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->string('request_hash')->nullable();
            $table->timestamps();
        });
        $schema->create('legal_document_comments', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_version_id')->nullable();
            $table->boolean('is_blocking')->default(false);
            $table->string('status')->default('open');
            $table->timestamps();
        });
        $schema->create('legal_document_obligations', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_version_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('responsible_user_id')->nullable();
            $table->string('title');
            $table->string('responsible_party')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->decimal('amount', 18, 2)->nullable();
            $table->decimal('volume', 18, 3)->nullable();
            $table->string('unit', 64)->nullable();
            $table->string('status', 32)->default('open');
            $table->timestampTz('completed_at')->nullable();
            $table->jsonb('evidence')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
        });
        $schema->create('legal_document_access_grants', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('subject_organization_id');
            $table->unsignedBigInteger('subject_user_id')->nullable();
            $table->string('subject_kind')->nullable();
            $table->string('subject_role_slug')->nullable();
            $table->json('abilities');
            $table->unsignedBigInteger('granted_by_user_id');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
        $schema->create('legal_archive_document_type_profiles', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('organization_id');
            $table->string('code');
            $table->string('base_code');
            $table->string('name');
            $table->json('schema')->nullable();
            $table->json('required_fields')->nullable();
            $table->json('required_file_roles')->nullable();
            $table->boolean('requires_signature')->nullable();
            $table->json('allowed_signature_kinds')->nullable();
            $table->json('required_signature_kinds')->nullable();
            $table->json('allowed_signature_formats')->nullable();
            $table->unsignedBigInteger('workflow_template_id')->nullable();
            $table->string('retention_policy')->nullable();
            $table->string('confidentiality_level')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['organization_id', 'code'], 'legal_doc_profiles_org_code_unique');
        });
        $schema->create('legal_workflow_template_heads', static function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id');
            $table->string('code');
            $table->unsignedBigInteger('template_id');
        });
        $schema->create('legal_workflow_templates', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('code');
            $table->unsignedInteger('version');
            $table->string('name');
            $table->string('definition_hash', 64);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
        });
        $schema->create('legal_workflow_template_steps', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('template_id');
            $table->unsignedBigInteger('organization_id');
            $table->string('step_key');
            $table->string('label');
            $table->unsignedInteger('sequence');
            $table->string('parallel_group');
            $table->boolean('required')->default(true);
            $table->string('policy_key')->nullable();
            $table->string('actor_type');
            $table->string('actor_reference');
            $table->unsignedInteger('due_in_hours')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }
}

final class LegalArchiveApiContractActorMiddleware
{
    public static ?User $actor = null;

    public function handle(Request $request, \Closure $next): \Symfony\Component\HttpFoundation\Response
    {
        $actor = self::$actor;
        if (! $actor instanceof User) {
            throw new \RuntimeException('legal_api_contract_actor_missing');
        }
        $request->setUserResolver(static fn (): User => $actor);
        $request->attributes->set('current_organization_id', 7);

        return $next($request);
    }
}

final class LegalArchiveApiContractAuthorizeMiddleware
{
    public static ?AuthorizationService $authorization = null;

    public function handle(
        Request $request,
        \Closure $next,
        string $permission,
    ): \Symfony\Component\HttpFoundation\Response {
        $actor = LegalArchiveApiContractActorMiddleware::$actor;
        $authorization = self::$authorization;
        if (! $actor instanceof User || ! $authorization instanceof AuthorizationService) {
            throw new \RuntimeException('legal_api_contract_authorization_missing');
        }
        $request->setUserResolver(static fn (): User => $actor);
        $request->attributes->set('current_organization_id', 7);

        if (! $authorization->can($actor, $permission)) {
            return \App\Http\Responses\AdminResponse::error(
                trans_message('errors.unauthorized'),
                403,
            );
        }

        return $next($request);
    }
}

final class LegalArchiveApiContractAudit implements LegalDocumentAudit
{
    public function record(string $event, LegalArchiveDocument $document, User $actor, array $context = []): void {}

    public function recordForActorId(string $event, LegalArchiveDocument $document, ?int $actorId, array $context = []): void {}

    public function recordContractForActorId(string $event, Contract $contract, ?int $actorId, array $context = []): void {}
}
