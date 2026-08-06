<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controllers\Api\V1\Admin;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Http\Controllers\Api\V1\Admin\Auth\AuthController;
use App\Models\User;
use App\Services\Auth\WebAuthenticationService;
use App\Services\Auth\WebRefreshCookieService;
use App\Services\Logging\LoggingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Request;
use ReflectionClass;

final class AuthControllerProfileResponseTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 7).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function test_me_returns_public_avatar_url_without_internal_storage_path(): void
    {
        $user = new class(['name' => 'Анна Админова', 'email' => 'anna@example.com', 'avatar_path' => 'org-38/avatars/avatar.png', 'is_active' => true, 'current_organization_id' => 38]) extends User
        {
            public function getAvatarUrlAttribute(): ?string
            {
                return 'https://storage.example.test/signed-avatar';
            }
        };
        $user->id = 39;

        $request = Request::create('/api/v1/admin/auth/me');
        $request->setUserResolver(static fn (): User => $user);

        $response = $this->controller()->me($request);
        $payload = $response->getData(true);

        self::assertTrue($payload['success']);
        self::assertArrayHasKey('avatar_url', $payload['data']['user']);
        self::assertArrayNotHasKey('avatar_path', $payload['data']['user']);
    }

    private function controller(): AuthController
    {
        return new AuthController(
            $this->withoutConstructor(WebAuthenticationService::class),
            $this->withoutConstructor(WebRefreshCookieService::class),
            $this->withoutConstructor(AuthorizationService::class),
            $this->withoutConstructor(LoggingService::class),
        );
    }

    /** @template T of object
     * @param  class-string<T>  $className
     * @return T
     */
    private function withoutConstructor(string $className): object
    {
        return (new ReflectionClass($className))->newInstanceWithoutConstructor();
    }
}
