<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing\Auth;

use App\Models\User;
use App\Services\Storage\FileService;
use Mockery\MockInterface;
use Tests\TestCase;

class LandingProfileResponseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
    }

    public function test_auth_me_returns_profile_fields_required_by_lk_profile(): void
    {
        $storedAvatarPath = 'shared/avatars/profile.jpg';
        $avatarUrl = 'https://cdn.test/shared/avatars/profile.jpg';

        $this->mock(FileService::class, function (MockInterface $mock) use ($storedAvatarPath, $avatarUrl): void {
            $mock->shouldReceive('temporaryUrl')
                ->once()
                ->with($storedAvatarPath, 60, null)
                ->andReturn($avatarUrl);
        });

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'phone' => '+79274097360',
            'position' => 'Director',
            'avatar_path' => $storedAvatarPath,
        ]);

        $response = $this->actingAs($user, 'api_landing')
            ->getJson('/api/v1/landing/auth/me');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'user' => [
                        'id',
                        'name',
                        'email',
                        'email_verified_at',
                        'phone',
                        'position',
                        'avatar_url',
                    ],
                ],
            ]);

        $this->assertNotNull($response->json('data.user.email_verified_at'));
        $response->assertJsonPath('data.user.avatar_url', $avatarUrl);
    }
}
