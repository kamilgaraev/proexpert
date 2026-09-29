<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Module;
use App\Models\Project;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\PostgresConnection;
use Illuminate\Http\Request;

trait UsesAssistantReportUnitAccess
{
    use UsesAssistantUnitTranslations { setUp as private translationsSetUp; tearDown as private translationsTearDown; }

    private ?ConnectionResolverInterface $previousReportResolver = null;
    private \WeakMap $reportUnitPermissions;

    protected function setUp(): void
    {
        $this->translationsSetUp();
        $this->reportUnitPermissions = new \WeakMap;
        $this->previousReportResolver = Model::getConnectionResolver();
        app()->instance('request', Request::create('/unit-report'));
        $connection = $this->getMockBuilder(PostgresConnection::class)->setConstructorArgs([static fn () => throw new \LogicException('Pure report fixture must not connect to PostgreSQL')])->onlyMethods(['select'])->getMock();
        $connection->method('select')->willReturnCallback(static fn (string $sql): array => preg_match('/^\s*select\s+exists\s*\(/i', $sql) === 1 ? [(object) ['exists' => true]] : [(object) ['id' => 1]]);
        $resolver = $this->createMock(ConnectionResolverInterface::class);
        $resolver->method('connection')->willReturn($connection);
        Model::setConnectionResolver($resolver);
        $schema = $this->createMock(\Illuminate\Database\Schema\Builder::class);
        $schema->method('hasColumn')->willReturn(true);
        $schema->method('getColumnListing')->willReturn(['id', 'organization_id', 'project_id', 'contract_id', 'warehouse_id', 'status', 'deleted_at']);
        app()->instance('db.schema', $schema);
        $authorization = $this->createMock(AuthorizationService::class);
        $authorization->method('canCurrent')->willReturnCallback(fn (User $user, string $permission): bool => in_array($permission, $this->reportUnitPermissions[$user] ?? [], true));
        $modules = $this->createMock(OrganizationEntitlementService::class);
        $modules->method('getEffectiveModules')->willReturn(collect(array_map(static fn (string $slug): Module => new Module(['slug' => $slug]),
            ['ai-assistant', 'project-management', 'contract-management', 'payments', 'basic-warehouse', 'schedule-management', 'reports', 'catalog-management'])));
        $projects = $this->createMock(UserProjectAccessService::class);
        $projects->method('queryAccessibleProjects')->willReturnCallback(static fn () => Project::query());
        app()->instance(AuthorizationService::class, $authorization);
        app()->instance(AssistantDataAccessPolicy::class, new AssistantDataAccessPolicy($authorization, $projects, $modules));
        app()->instance(AIPermissionChecker::class, new AIPermissionChecker($authorization));
    }

    protected function grantReportUnitPermissions(User $user, array $permissions): void
    {
        $user->is_active = true;
        $this->reportUnitPermissions[$user] = $permissions;
    }

    protected function tearDown(): void
    {
        $this->previousReportResolver === null ? Model::unsetConnectionResolver() : Model::setConnectionResolver($this->previousReportResolver);
        $this->reportUnitPermissions = new \WeakMap;
        $this->translationsTearDown();
    }
}
