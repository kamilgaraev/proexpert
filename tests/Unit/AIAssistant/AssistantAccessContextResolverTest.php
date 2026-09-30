<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantAccessContextResolver;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

class AssistantAccessContextResolverTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use UsesAssistantUnitTranslations {
        setUp as private setUpTranslations;
        tearDown as private tearDownTranslations;
    }

    private ?\Illuminate\Database\ConnectionResolverInterface $previousResolver;
    private AssistantAuthorizationContextConnection $connection;

    protected function setUp(): void
    {
        $this->setUpTranslations();
        $this->previousResolver = \Illuminate\Database\Eloquent\Model::getConnectionResolver();
        $this->connection = new AssistantAuthorizationContextConnection(null);
        $resolver = new \Illuminate\Database\ConnectionResolver(['unit' => $this->connection]);
        $resolver->setDefaultConnection('unit');
        \Illuminate\Database\Eloquent\Model::setConnectionResolver($resolver);
        $permissions = Mockery::mock(\App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker::class);
        $permissions->shouldReceive('canUseAssistant')->andReturn(true);
        app()->instance(\App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker::class, $permissions);
    }

    protected function tearDown(): void
    {
        if ($this->previousResolver !== null) {
            \Illuminate\Database\Eloquent\Model::setConnectionResolver($this->previousResolver);
        } else {
            \Illuminate\Database\Eloquent\Model::unsetConnectionResolver();
        }
        $this->tearDownTranslations();
    }

    public function test_resolve_builds_public_permission_summary(): void
    {
        $authorizationService = Mockery::mock(AuthorizationService::class);
        $authorizationService->shouldReceive('forCurrentChecks')->once()->andReturnSelf();
        $authorizationService->shouldReceive('getUserPermissions')->once()->andReturn(['projects.view', 'payments.invoice_view', 'reports.view']);
        $authorizationService
            ->shouldReceive('getUserPermissionsStructured')
            ->once()
            ->andReturn([
                'system' => ['dashboard.view'],
                'modules' => [
                    'projects' => ['projects.view'],
                    'payments' => ['payments.invoice_view'],
                ],
            ]);

        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldNotReceive('getPermissions');

        $resolver = new AssistantAccessContextResolver($authorizationService);
        $context = $resolver->resolve($user, 15);

        $this->assertTrue($context['can_use_assistant']);
        $this->assertSame(3, $context['permission_count']);
        $this->assertTrue($context['is_read_only']);
        $this->assertContains('projects', $context['available_modules']);
        $this->assertContains('payments', $context['available_modules']);
        $this->assertTrue($resolver->hasPermission($context, 'projects.view'));
        $this->assertFalse($resolver->hasPermission($context, 'projects.edit'));
    }

    public function test_owner_style_module_wildcards_are_treated_as_full_domain_access(): void
    {
        $authorizationService = Mockery::mock(AuthorizationService::class);
        $authorizationService->shouldReceive('forCurrentChecks')->once()->andReturnSelf();
        $authorizationService->shouldReceive('getUserPermissions')->once()->andReturn(['organization.view', 'admin.*']);
        $authorizationService
            ->shouldReceive('getUserPermissionsStructured')
            ->once()
            ->andReturn([
                'system' => ['admin.*'],
                'modules' => [
                    'projects' => ['*'],
                    'schedule-management' => ['*'],
                ],
            ]);

        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldNotReceive('getPermissions');

        $resolver = new AssistantAccessContextResolver($authorizationService);
        $context = $resolver->resolve($user, 39);

        $this->assertFalse($context['is_read_only']);
        $this->assertTrue($resolver->hasPermission($context, 'projects.view'));
        $this->assertTrue($resolver->hasPermission($context, 'projects.edit'));
        $this->assertTrue($resolver->hasPermission($context, 'schedule-management.view'));
        $this->assertTrue($resolver->hasPermission($context, 'schedules.view'));
    }

    public function test_current_checks_requery_scope_and_do_not_keep_revoked_permissions(): void
    {
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('forCurrentChecks')->twice()->andReturnSelf();
        $authorization->shouldReceive('getUserPermissions')->once()->andReturn(['projects.edit']);
        $authorization->shouldReceive('getUserPermissions')->once()->andReturn([]);
        $authorization->shouldReceive('getUserPermissionsStructured')->twice()->andReturn([]);
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldNotReceive('getPermissions');
        $resolver = new AssistantAccessContextResolver($authorization);
        $first = $resolver->resolve($user, 15);
        $second = $resolver->resolve($user, 15);
        $this->assertFalse($first['is_read_only']);
        $this->assertTrue($second['is_read_only']);
        $this->assertFalse($resolver->hasPermission($second, 'projects.edit'));
        $this->assertSame(4, $this->connection->selects);
    }
}

final class AssistantAuthorizationContextConnection extends \Illuminate\Database\Connection
{
    public int $selects = 0;

    public function select($query, $bindings = [], $useReadPdo = true): array
    {
        $this->selects++;
        $system = ($bindings[0] ?? null) === 'system';

        return [(object) ['id' => $system ? 1 : 2, 'type' => $system ? 'system' : 'organization',
            'resource_id' => $system ? null : $bindings[1], 'parent_context_id' => $system ? null : 1, 'metadata' => null]];
    }
}
