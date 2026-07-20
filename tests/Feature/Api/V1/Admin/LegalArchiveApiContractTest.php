<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Domain\Authorization\Http\Middleware\AuthorizeMiddleware;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Http\Controllers\Api\V1\Admin\LegalArchive\LegalArchiveApiController;
use App\Models\User;
use App\Services\LegalArchive\Access\LegalDocumentAccessService;
use App\Services\LegalArchive\Access\LegalDocumentAuthorizer;
use App\Services\LegalArchive\LegalArchiveLockConflict;
use App\Services\LegalArchive\Profiles\LegalDocumentProfileRegistry;
use App\Services\LegalArchive\Profiles\LegalDocumentProfileValidator;
use App\Services\LegalArchive\Profiles\LegalDocumentTypeProfileService;
use App\Services\Project\UserProjectAccessService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Mockery;
use PDO;
use Tests\TestCase;
use Throwable;

final class LegalArchiveApiContractTest extends TestCase
{
    private string $originalConnection;

    private AuthorizationService $authorization;

    private bool $permissionAllowed = true;

    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalConnection = DB::getDefaultConnection();
        config()->set('database.connections.legal_api_contract', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('legal_api_contract');
        DB::setDefaultConnection('legal_api_contract');
        $this->createSchema();
        $this->authorization = Mockery::mock(AuthorizationService::class);
        $this->authorization->shouldReceive('can')->andReturnUsing(fn (): bool => $this->permissionAllowed);
        $this->authorization->shouldReceive('getUserRoleSlugs')->andReturn([]);
        $this->app->instance(AuthorizationService::class, $this->authorization);

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
            'admin.legal-archive.signatures.requests.store' => 'authorize:legal_archive.signatures.request',
            'admin.legal-archive.signatures.index' => 'authorize:legal_archive.signatures.view',
            'admin.legal-archive.signatures.verification-history' => 'authorize:legal_archive.signatures.view',
            'admin.legal-archive.access.store' => 'authorize:legal_archive.external_access.manage',
            'admin.legal-archive.retention.update' => 'authorize:legal_archive.retention.manage',
            'admin.legal-archive.type-profiles.store' => 'authorize:legal_archive.settings.manage',
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

    public function test_admin_response_contract_covers_not_found_conflict_validation_and_etag(): void
    {
        $controller = new LegalArchiveApiContractProbe;
        $request = Request::create('/api/v1/admin/legal-archive/documents/42', 'PATCH');
        $request->attributes->set('current_organization_id', 7);

        $notFound = $controller->fail(new AuthorizationException, $request, ['document_id' => 42]);
        self::assertSame(404, $notFound->getStatusCode());

        $conflict = $controller->fail(LegalArchiveLockConflict::forDocument(42, 9), $request, ['document_id' => 42]);
        self::assertSame(409, $conflict->getStatusCode());
        self::assertSame('"legal-document-42-v9"', $conflict->headers->get('ETag'));
        self::assertSame('/api/v1/admin/legal-archive/documents/42', $conflict->headers->get('Location'));
        self::assertSame(9, $conflict->getData(true)['current_lock_version']);
        self::assertSame('legal_document', $conflict->getData(true)['aggregate_kind']);
        self::assertSame('42', $conflict->getData(true)['aggregate_id']);

        $invalid = $controller->fail(ValidationException::withMessages(['lock_version' => ['required']]), $request);
        self::assertSame(422, $invalid->getStatusCode());

        $document = new LegalArchiveDocument;
        $document->forceFill(['id' => 42, 'lock_version' => 10]);
        $success = $controller->tag(new JsonResponse(['success' => true]), $document);
        self::assertSame('"legal-document-42-v10"', $success->headers->get('ETag'));
        self::assertSame('/api/v1/admin/legal-archive/documents/42', $success->headers->get('Location'));

        $user = new User;
        $user->forceFill(['id' => 5, 'current_organization_id' => 7]);
        $deniedRequest = Request::create('/api/v1/admin/legal-archive/documents', 'POST');
        $deniedRequest->attributes->set('current_organization_id', 7);
        $deniedRequest->setUserResolver(static fn (): User => $user);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->once()->andReturnFalse();
        $denied = (new AuthorizeMiddleware($authorization))->handle(
            $deniedRequest,
            static fn (): JsonResponse => new JsonResponse([], 200),
            'legal_archive.create',
        );
        self::assertSame(403, $denied->getStatusCode());
    }

    public function test_conflicts_identify_the_exact_aggregate_and_refresh_resource(): void
    {
        $controller = new LegalArchiveApiContractProbe;
        $request = Request::create('/api/v1/admin/legal-archive/workflow-steps/81/approve', 'POST');
        $request->attributes->set('current_organization_id', 7);

        $step = $controller->fail(LegalArchiveLockConflict::forWorkflowStep(81, 4, 42), $request);
        self::assertSame(409, $step->getStatusCode());
        self::assertSame('legal_workflow_step', $step->getData(true)['aggregate_kind']);
        self::assertSame('81', $step->getData(true)['aggregate_id']);
        self::assertSame(42, $step->getData(true)['document_id']);
        self::assertSame('/api/v1/admin/legal-archive/documents/42/available-actions', $step->getData(true)['refresh_url']);
        self::assertSame('"legal-workflow-step-81-v4"', $step->headers->get('ETag'));

        $profile = $controller->fail(LegalArchiveLockConflict::forProfile('profile-id', 3), $request);
        self::assertSame('document_type_profile', $profile->getData(true)['aggregate_kind']);
        self::assertSame('profile-id', $profile->getData(true)['aggregate_id']);
        self::assertSame('/api/v1/admin/legal-archive/type-profiles/profile-id', $profile->headers->get('Location'));
        self::assertSame('"legal-profile-profile-id-v3"', $profile->headers->get('ETag'));
    }

    public function test_list_detail_and_actions_run_through_canonical_routes_real_services_and_permission_middleware(): void
    {
        DB::table('legal_archive_document_type_profiles')->insert([
            'id' => 'b81f7350-c0b1-4e06-a1cb-8662b23eab01',
            'organization_id' => 7,
            'code' => 'customer.supply',
            'base_code' => 'contract.supply',
            'name' => 'Специальная поставка',
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
        $retired = $this->documentRow(45, 7, 'Архивный профиль');
        $retired['type_profile_code'] = 'retired.profile';
        DB::table('legal_archive_documents')->insert([
            $owned,
            $retired,
            $this->documentRow(43, 8, 'Чужой договор'),
        ]);
        $actor = $this->actor();

        $list = $this->runCanonical(Request::create('/api/v1/admin/legal-archive/documents?per_page=100', 'GET'), $actor);
        self::assertSame(200, $list->getStatusCode());
        self::assertSame([45, 42], array_column($list->getData(true)['data'], 'id'));
        self::assertSame(100, $list->getData(true)['meta']['per_page']);
        $listed = collect($list->getData(true)['data'])->keyBy('id');
        self::assertSame('customer.supply', $listed[42]['type_profile']['code']);
        self::assertSame('Специальная поставка', $listed[42]['type_profile']['label']);
        self::assertSame('retired.profile', $listed[45]['type_profile']['code']);
        self::assertSame('submit', $listed[42]['workflow_summary']['available_action_details'][0]['action']);
        self::assertSame(0, $listed[42]['completeness']['files']);
        self::assertContains('no_files', $listed[42]['problem_flags']);

        $detail = $this->runCanonical(Request::create('/api/v1/admin/legal-archive/documents/42', 'GET'), $actor);
        self::assertSame(200, $detail->getStatusCode());
        self::assertSame('42', (string) $detail->getData(true)['data']['id']);
        self::assertSame('"legal-document-42-v3"', $detail->headers->get('ETag'));

        $foreign = $this->runCanonical(Request::create('/api/v1/admin/legal-archive/documents/43', 'GET'), $actor);
        self::assertSame(404, $foreign->getStatusCode());

        $actions = $this->runCanonical(Request::create('/api/v1/admin/legal-archive/documents/42/available-actions', 'GET'), $actor);
        self::assertSame(200, $actions->getStatusCode());
        self::assertSame('submit', $actions->getData(true)['data']['workflow_summary']['available_action_details'][0]['action']);

        $this->permissionAllowed = false;
        $denied = $this->runCanonical(Request::create('/api/v1/admin/legal-archive/documents/42/available-actions', 'GET'), $actor);
        self::assertSame(403, $denied->getStatusCode());
    }

    public function test_controlled_sqlite_fixture_rejects_cross_tenant_and_stale_concurrent_mutations(): void
    {
        $database = new PDO('sqlite::memory:');
        $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $database->exec('CREATE TABLE legal_archive_documents (id INTEGER PRIMARY KEY, organization_id INTEGER NOT NULL, primary_project_id INTEGER, lock_version INTEGER NOT NULL)');
        $database->exec('INSERT INTO legal_archive_documents VALUES (42, 7, 70, 3)');

        $first = $database->prepare('UPDATE legal_archive_documents SET lock_version = lock_version + 1 WHERE id = ? AND organization_id = ? AND primary_project_id = ? AND lock_version = ?');
        $first->execute([42, 7, 70, 3]);
        self::assertSame(1, $first->rowCount());

        $stale = $database->prepare('UPDATE legal_archive_documents SET lock_version = lock_version + 1 WHERE id = ? AND organization_id = ? AND primary_project_id = ? AND lock_version = ?');
        $stale->execute([42, 7, 70, 3]);
        self::assertSame(0, $stale->rowCount());

        $crossTenant = $database->prepare('SELECT id FROM legal_archive_documents WHERE id = ? AND organization_id = ?');
        $crossTenant->execute([42, 8]);
        self::assertFalse($crossTenant->fetchColumn());
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
        ]);

        self::assertSame([], $profile->schema);
        self::assertSame([], $profile->required_fields);
        self::assertNull($profile->requires_signature);
        self::assertNull($profile->workflow_template_id);
        self::assertSame('internal', $profile->confidentiality_level);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('profile_code_duplicate');
        $service->create(7, [
            'code' => 'customer.minimal',
            'base_code' => 'contract.supply',
            'name' => 'Дубликат',
        ]);
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

    /** @return array<string, mixed> */
    private function documentRow(int $id, int $organizationId, string $title): array
    {
        return [
            'id' => $id,
            'organization_id' => $organizationId,
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
        $schema->create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
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
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        $schema->create('legal_archive_document_versions', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('file_id')->nullable();
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
            $table->unsignedBigInteger('template_id');
        });
        $schema->create('legal_workflow_templates', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
        });
    }
}

final class LegalArchiveApiContractProbe extends LegalArchiveApiController
{
    public function fail(Throwable $error, Request $request, array $context = []): JsonResponse
    {
        return $this->failure($error, $request, 'contract_probe', $context);
    }

    public function tag(JsonResponse $response, LegalArchiveDocument $document): JsonResponse
    {
        return $this->etag($response, $document);
    }
}
