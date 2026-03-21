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

    public function test_resolve_builds_public_permission_summary(): void
    {
        $authorizationService = Mockery::mock(AuthorizationService::class);
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
        $user->shouldReceive('getPermissions')->once()->andReturn([
            'projects.view',
            'payments.invoice_view',
            'reports.view',
        ]);
        $user->shouldReceive('belongsToOrganization')->once()->with(15)->andReturn(true);

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
}
