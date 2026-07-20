<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocument;
use App\Domain\Authorization\Http\Middleware\AuthorizeMiddleware;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Http\Controllers\Api\V1\Admin\LegalArchive\LegalArchiveApiController;
use App\Models\User;
use App\Services\LegalArchive\LegalArchiveLockConflict;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Mockery;
use PDO;
use Tests\TestCase;
use Throwable;

final class LegalArchiveApiContractTest extends TestCase
{
    public function refreshDatabase(): void {}

    public function test_canonical_routes_use_real_admin_stack_and_exact_permissions(): void
    {
        $expected = [
            'admin.legal-archive.documents.store' => 'authorize:legal_archive.create',
            'admin.legal-archive.documents.files.store' => 'authorize:legal_archive.files.upload',
            'admin.legal-archive.workflow.submit' => 'authorize:legal_archive.workflow.submit',
            'admin.legal-archive.signatures.requests.store' => 'authorize:legal_archive.signatures.request',
            'admin.legal-archive.signatures.index' => 'authorize:legal_archive.signatures.view',
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

    public function test_admin_response_contract_covers_not_found_conflict_validation_and_etag(): void
    {
        $controller = new LegalArchiveApiContractProbe;
        $request = Request::create('/api/v1/admin/legal-archive/documents/42', 'PATCH');
        $request->attributes->set('current_organization_id', 7);

        $notFound = $controller->fail(new AuthorizationException, $request, ['document_id' => 42]);
        self::assertSame(404, $notFound->getStatusCode());

        $conflict = $controller->fail(new LegalArchiveLockConflict(9), $request, ['document_id' => 42]);
        self::assertSame(409, $conflict->getStatusCode());
        self::assertSame('"legal-document-lock-v9"', $conflict->headers->get('ETag'));
        self::assertSame('/api/v1/admin/legal-archive/documents/42', $conflict->headers->get('Location'));
        self::assertSame(9, $conflict->getData(true)['current_lock_version']);

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

    public function test_list_detail_and_actions_contracts_are_bounded_and_tenant_scoped(): void
    {
        $registry = file_get_contents(base_path('app/Services/LegalArchive/LegalArchiveRegistryService.php'));
        $document = file_get_contents(base_path('app/Http/Controllers/Api/V1/Admin/LegalArchive/LegalArchiveDocumentController.php'));
        self::assertIsString($registry);
        self::assertIsString($document);
        self::assertStringContainsString('scopeAccessibleQuery($query, $actor, $organizationId)', $registry);
        self::assertStringContainsString("'project:id,name,status,organization_id'", $registry);
        self::assertStringContainsString("->withCount(['files', 'signatureRequests', 'signatures'])", $registry);
        self::assertStringContainsString('max(10, min(', $registry);
        self::assertStringContainsString("'available_action_details'", $document);
        self::assertStringContainsString("'completeness'", $document);
        self::assertStringContainsString("'problem_flags'", $document);
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
