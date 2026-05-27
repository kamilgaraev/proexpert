<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Auth\SystemAdminLogin;
use App\Http\Middleware\EnsureSystemAdminSessionIsFresh;
use App\Models\SystemAdmin;
use Filament\Facades\Filament;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use ReflectionProperty;
use Tests\TestCase;

class SystemAdminSessionSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    protected function tearDown(): void
    {
        Auth::guard('system_admin')->logout();
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

    public function test_admin_panel_uses_hardened_login_and_session_middleware(): void
    {
        $providerSource = (string) file_get_contents(app_path('Providers/Filament/AdminPanelProvider.php'));
        $loginSource = (string) file_get_contents(app_path('Filament/Auth/SystemAdminLogin.php'));

        $this->assertStringContainsString('->login(SystemAdminLogin::class)', $providerSource);
        $this->assertStringContainsString('EnsureSystemAdminSessionIsFresh::class', $providerSource);
        $this->assertStringContainsString('isPersistent: true', $providerSource);
        $this->assertStringNotContainsString('getRememberFormComponent()', $loginSource);
        $this->assertStringContainsString("\$this->data['remember'] = false;", $loginSource);
        $this->assertStringContainsString('session()->regenerateToken()', $loginSource);
        $this->assertStringContainsString('migrate(true)', (string) file_get_contents(app_path('Http/Middleware/EnsureSystemAdminSessionIsFresh.php')));
        $this->assertTrue(is_subclass_of(SystemAdminLogin::class, \Filament\Auth\Pages\Login::class));
    }

    public function test_system_admin_session_id_is_rotated_periodically(): void
    {
        config()->set('system_admin_security.session_rotation_minutes', 15);

        [$request, $guard] = $this->authenticatedRequest();
        $request->session()->put(
            EnsureSystemAdminSessionIsFresh::SESSION_ROTATED_AT_KEY,
            now()->subMinutes(20)->getTimestamp(),
        );

        $oldSessionId = $request->session()->getId();

        $response = app(EnsureSystemAdminSessionIsFresh::class)->handle(
            $request,
            fn (): Response => new Response('ok'),
        );

        $this->assertSame('ok', $response->getContent());
        $this->assertTrue($guard->check());
        $this->assertNotSame($oldSessionId, $request->session()->getId());
        $this->assertIsInt($request->session()->get(EnsureSystemAdminSessionIsFresh::SESSION_ROTATED_AT_KEY));
    }

    public function test_system_admin_remember_cookie_login_is_rejected(): void
    {
        [$request, $guard, $admin] = $this->authenticatedRequest();

        $oldRememberToken = $admin->remember_token;
        $this->markGuardAsAuthenticatedViaRememberCookie($guard);

        $response = app(EnsureSystemAdminSessionIsFresh::class)->handle(
            $request,
            fn (): Response => new Response('ok'),
        );

        $this->assertFalse($guard->check());
        $this->assertNotSame($oldRememberToken, $admin->fresh()->remember_token);
        $this->assertTrue($response->isRedirect(Filament::getLoginUrl()));
    }

    /**
     * @return array{0: Request, 1: SessionGuard, 2: SystemAdmin}
     */
    private function authenticatedRequest(): array
    {
        $request = Request::create('/admin', 'GET');
        $session = new Store('system_admin_session_test', new ArraySessionHandler(120));
        $session->start();
        $request->setLaravelSession($session);

        $this->app->instance('request', $request);

        /** @var SessionGuard $guard */
        $guard = Auth::guard('system_admin');
        $guard->setRequest($request);

        $admin = SystemAdmin::factory()->create([
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $guard->login($admin);

        return [$request, $guard, $admin];
    }

    private function markGuardAsAuthenticatedViaRememberCookie(SessionGuard $guard): void
    {
        $property = new ReflectionProperty($guard, 'viaRemember');
        $property->setAccessible(true);
        $property->setValue($guard, true);
    }
}
