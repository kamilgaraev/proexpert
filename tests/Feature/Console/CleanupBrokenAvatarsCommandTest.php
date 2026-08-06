<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToCheckFileExistence;
use Tests\TestCase;

final class CleanupBrokenAvatarsCommandTest extends TestCase
{
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
            $table->rememberToken();
            $table->string('avatar_path')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function test_keeps_avatar_path_when_existence_check_fails(): void
    {
        config()->set('filesystems.disks.s3', [
            'driver' => 's3',
        ]);

        $user = User::factory()->create([
            'avatar_path' => 'org-5/avatars/4985eee9-a07a-4724-89c2-f7f2bfb62990.jpg',
        ]);

        Storage::shouldReceive('disk')
            ->once()
            ->with('s3')
            ->andReturn(new class
            {
                public function exists(string $path): bool
                {
                    throw UnableToCheckFileExistence::forLocation($path);
                }
            });

        $this->artisan('avatars:cleanup')
            ->assertExitCode(0);

        self::assertSame(
            'org-5/avatars/4985eee9-a07a-4724-89c2-f7f2bfb62990.jpg',
            $user->refresh()->avatar_path,
        );
    }
}
