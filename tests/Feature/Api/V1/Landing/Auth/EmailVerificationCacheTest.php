<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing\Auth;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationCacheTest extends TestCase
{
    public function refreshDatabase(): void
    {
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->unsignedBigInteger('current_organization_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function test_email_verification_clears_cached_profile_with_roles(): void
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'New Owner',
            'email' => 'owner@example.test',
            'password' => 'password',
            'current_organization_id' => 46,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::query()->findOrFail($userId);
        $cacheKey = "user_with_roles_{$user->id}_{$user->current_organization_id}";
        Cache::put($cacheKey, $user, 300);

        $url = URL::temporarySignedRoute(
            'api.v1.landing.verification.verify',
            now()->addMinutes(60),
            [
                'id' => $user->id,
                'hash' => sha1($user->getEmailForVerification()),
            ]
        );

        $this->getJson($url)->assertOk();

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertFalse(Cache::has($cacheKey));
    }
}
