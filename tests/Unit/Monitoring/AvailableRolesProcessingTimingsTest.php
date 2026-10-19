<?php

declare(strict_types=1);

namespace Tests\Unit\Monitoring;

use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Domain\Authorization\Services\CustomRoleService;
use App\Domain\Authorization\Services\RolePayloadFormatter;
use App\Domain\Authorization\Services\RoleScanner;
use App\Helpers\AdminPanelAccessHelper;
use App\Http\Controllers\Api\V1\Landing\CustomUserManagementController;
use App\Repositories\UserRepository;
use App\Services\Monitoring\ApiQueryMetrics;
use App\Services\PermissionTranslationService;
use App\Services\User\UserService;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class AvailableRolesProcessingTimingsTest extends TestCase
{
    public function test_all_scope_keeps_payload_and_separates_preparation_from_encoding(): void
    {
        $this->assertScenario('all');
    }

    public function test_admin_panel_scope_keeps_both_role_filters(): void
    {
        $this->assertScenario('admin_panel');
    }

    public function test_unavailable_custom_roles_keep_the_existing_successful_fallback(): void
    {
        $this->assertScenario('all', customFailure: true);
    }

    public function test_preparation_failure_keeps_the_error_response_and_records_the_phase(): void
    {
        $this->assertScenario('all', scannerFailure: true);
    }

    private function assertScenario(string $scope, bool $customFailure = false, bool $scannerFailure = false): void
    {
        Lang::addLines([
            'roles.fixture_alpha.name' => 'Alpha fixture',
            'roles.fixture_alpha.description' => 'Alpha fixture description',
            'roles.fixture_zeta.name' => 'Zeta fixture',
            'roles.fixture_zeta.description' => 'Zeta fixture description',
        ], $this->app->getLocale());
        $baseline = null;
        $systemSlugs = $scope === 'admin_panel' ? ['fixture_zeta'] : ['fixture_alpha', 'fixture_zeta'];
        $customSlugs = $customFailure ? [] : ($scope === 'admin_panel' ? ['fixture_custom'] : ['fixture_custom', 'fixture_other']);
        if ($customFailure) {
            Log::shouldReceive('warning')->twice()->with('Custom roles not available yet', ['error' => 'fixture custom unavailable']);
        } elseif ($scannerFailure) {
            Log::shouldReceive('error')->twice()->with('Error getting available roles', ['error' => 'fixture scanner unavailable', 'organization_id' => 202]);
        } else {
            Log::shouldReceive('error')->never();
            Log::shouldReceive('warning')->never();
        }

        foreach ([false, true] as $capture) {
            $request = Request::create('/api/v1/landing/user-management/available-roles', 'GET', ['scope' => $scope, 'current_interface' => 'lk']);
            $request->attributes->set('current_organization_id', 202);
            $metrics = new ApiQueryMetrics($capture);
            $request->attributes->set(ApiQueryMetrics::REQUEST_ATTRIBUTE, $metrics);
            $this->app->instance('request', $request);
            $observedAtEncoding = [];
            $factory = Mockery::mock(ResponseFactory::class);
            $factory->shouldReceive('json')->once()->andReturnUsing(function (mixed $data, int $status = 200, array $headers = [], int $options = 0) use ($request, $metrics, &$observedAtEncoding): JsonResponse {
                $phases = $metrics->summary()['processing_phases'] ?? [];
                $observedAtEncoding = ['prepare_finished' => isset($phases['list_prepare']), 'encode_finished' => isset($phases['list_encode'])];
                ApiQueryMetrics::record($request, 3.0);

                return new JsonResponse($data, $status, $headers, $options);
            });
            $this->app->instance(ResponseFactory::class, $factory);
            $scanner = Mockery::mock(RoleScanner::class);
            if ($scannerFailure) {
                $scanner->shouldReceive('getAllRoles')->once()->andThrow(new RuntimeException('fixture scanner unavailable'));
            } else {
                $scanner->shouldReceive('getAllRoles')->once()->andReturn(collect([
                    'organization_owner' => ['name' => 'Owner fixture'],
                    'fixture_hidden' => ['name' => 'Hidden fixture', 'assignable' => false],
                    'fixture_zeta' => ['name' => 'Zeta fixture', 'system_permissions' => ['fixture.private_permission']],
                    'fixture_alpha' => ['name' => 'Alpha fixture', 'system_permissions' => ['fixture.private_permission']],
                ]));
            }
            $custom = Mockery::mock(CustomRoleService::class);
            if ($scannerFailure) {
                $custom->shouldNotReceive('getOrganizationRoles');
            } elseif ($customFailure) {
                $custom->shouldReceive('getOrganizationRoles')->once()->with(202)->andThrow(new RuntimeException('fixture custom unavailable'));
            } else {
                $custom->shouldReceive('getOrganizationRoles')->once()->with(202)->andReturn(new Collection([
                    $this->customRole('fixture_custom', 77), $this->customRole('fixture_other', 78),
                ]));
            }
            $adminPanel = Mockery::mock(AdminPanelAccessHelper::class);
            if ($scope === 'admin_panel') {
                $adminPanel->shouldReceive('getAdminPanelRoles')->once()->with(202, 'lk', true)->andReturn(['fixture_zeta', 'fixture_custom']);
            } else {
                $adminPanel->shouldNotReceive('getAdminPanelRoles');
            }
            $translator = new class($request) extends PermissionTranslationService
            {
                public function __construct(private readonly Request $request) {}

                public function processPermissionsForFrontend(array $permissionsData): array
                {
                    ApiQueryMetrics::record($this->request, 0.5);

                    return [
                        'system_permissions' => array_combine($permissionsData['system_permissions'], $permissionsData['system_permissions']),
                        'module_permissions' => [],
                        'module_groups' => [],
                        'interface_access' => array_combine($permissionsData['interface_access'], $permissionsData['interface_access']),
                    ];
                }
            };
            $controller = new CustomUserManagementController(
                $custom, Mockery::mock(AuthorizationService::class), $scanner, new RolePayloadFormatter($translator),
                $adminPanel, Mockery::mock(UserRepository::class), Mockery::mock(UserService::class),
            );
            $response = $controller->getAvailableRoles($request);
            $payload = $response->getData(true);
            self::assertSame($scannerFailure ? 500 : 200, $response->getStatusCode());
            self::assertSame(! $scannerFailure, $payload['success']);
            if (! $scannerFailure) {
                self::assertSame(202, $payload['data']['organization_id']);
                self::assertSame($systemSlugs, array_column($payload['data']['system_roles'], 'slug'));
                self::assertSame($customSlugs, array_column($payload['data']['custom_roles'], 'slug'));
                self::assertNotEmpty($payload['data']['system_roles'][0]['permission_groups']);
            }
            $summary = $metrics->summary();
            self::assertStringNotContainsString('fixture_', json_encode($summary, JSON_THROW_ON_ERROR));
            if (! $capture) {
                $baseline = $payload;
                self::assertArrayNotHasKey('processing_phases', $summary);

                continue;
            }
            self::assertSame($baseline, $payload);
            $phases = $summary['processing_phases'];
            self::assertArrayHasKey('list_prepare', $phases);
            self::assertSame(['prepare_finished' => true, 'encode_finished' => false], $observedAtEncoding);
            self::assertSame($scannerFailure ? ['list_prepare'] : ['list_prepare', 'list_encode'], array_keys($phases));
            $prepared = $scannerFailure ? 0 : count($systemSlugs) + count($customSlugs);
            self::assertSame($prepared, $phases['list_prepare']['sql_count']);
            self::assertSame($prepared * 0.5, $phases['list_prepare']['sql_total_ms']);
            if (! $scannerFailure) {
                self::assertSame(1, $phases['list_encode']['sql_count']);
                self::assertSame(3.0, $phases['list_encode']['sql_total_ms']);
            }
            foreach ($phases as $phase) {
                self::assertSame(1, $phase['count']);
                self::assertGreaterThanOrEqual(0, $phase['max_ms']);
                self::assertGreaterThanOrEqual($phase['max_ms'], $phase['total_ms']);
                if (isset($phase['process_cpu_ms'])) {
                    self::assertTrue(is_int($phase['process_cpu_ms']) || is_float($phase['process_cpu_ms']));
                    self::assertGreaterThanOrEqual(0, $phase['process_cpu_ms']);
                }
            }
        }
    }

    private function customRole(string $slug, int $id): OrganizationCustomRole
    {
        return (new OrganizationCustomRole)->forceFill([
            'id' => $id, 'organization_id' => 202, 'slug' => $slug, 'name' => 'Private fixture role',
            'description' => 'Private fixture description', 'is_active' => true,
            'system_permissions' => ['fixture.private_permission'], 'module_permissions' => [], 'interface_access' => ['lk'],
        ]);
    }
}
