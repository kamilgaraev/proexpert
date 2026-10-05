<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\DTOs\Auth\LoginDTO;
use App\Models\User;
use App\Services\Auth\JwtAuthService;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class LoginPasswordVerificationTest extends TestCase
{
    public function test_unchanged_password_is_verified_once_before_locked_user_checks(): void
    {
        [$result, $checks] = $this->authenticate();
        self::assertTrue($result['success']);
        self::assertSame(200, $result['status_code']);
        self::assertSame(1, $checks);
    }

    public function test_password_changed_after_provider_validation_rejects_old_credentials(): void
    {
        $replacement = Hash::make('replacement-password');
        [$result, $checks] = $this->authenticate(static function (Authenticatable $user) use ($replacement): void {
            DB::table('users')->where('id', $user->getAuthIdentifier())->update(['password' => $replacement]);
        });
        self::assertFalse($result['success']);
        self::assertSame(401, $result['status_code']);
        self::assertSame(2, $checks);
    }

    public function test_rehashed_same_password_is_verified_again_and_remains_valid(): void
    {
        $replacement = Hash::make('password');
        [$result, $checks] = $this->authenticate(static function (Authenticatable $user) use ($replacement): void {
            DB::table('users')->where('id', $user->getAuthIdentifier())->update(['password' => $replacement]);
        });
        self::assertTrue($result['success']);
        self::assertSame(2, $checks);
    }

    public function test_same_password_does_not_bypass_current_inactive_account_state(): void
    {
        [$result, $checks] = $this->authenticate(static function (Authenticatable $user): void {
            DB::table('users')->where('id', $user->getAuthIdentifier())->update(['is_active' => false]);
        });
        self::assertFalse($result['success']);
        self::assertSame(403, $result['status_code']);
        self::assertSame(1, $checks);
    }

    private function authenticate(?\Closure $afterValidation = null): array
    {
        $fixture = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $originalHasher = Hash::getFacadeRoot();
        $hasher = new LoginCountingHasher((array) config('hashing.bcrypt'));
        $guard = Auth::guard('api_admin');
        $originalProvider = $guard->getProvider();
        $provider = new LoginRaceUserProvider($hasher, $afterValidation);
        Hash::swap($hasher);
        $guard->setProvider($provider);
        try {
            $result = app(JwtAuthService::class)->authenticate(new LoginDTO($fixture->user->email, 'password'), 'api_admin');

            return [$result, $hasher->checks];
        } finally {
            $guard->setProvider($originalProvider);
            Hash::swap($originalHasher);
        }
    }
}

final class LoginCountingHasher extends BcryptHasher
{
    public int $checks = 0;

    public function check(#[\SensitiveParameter] $value, $hashedValue, array $options = []): bool
    {
        $this->checks++;

        return parent::check($value, $hashedValue, $options);
    }
}

final class LoginRaceUserProvider extends EloquentUserProvider
{
    public function __construct(Hasher $hasher, private readonly ?\Closure $afterValidation)
    {
        parent::__construct($hasher, User::class);
    }

    public function validateCredentials(Authenticatable $user, #[\SensitiveParameter] array $credentials): bool
    {
        $valid = parent::validateCredentials($user, $credentials);
        if ($valid && $this->afterValidation !== null) {
            ($this->afterValidation)($user);
        }

        return $valid;
    }
}
