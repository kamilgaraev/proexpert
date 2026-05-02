<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Organization;
use App\Models\ReportFile;
use App\Models\User;
use App\Services\Storage\OrgBucketService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToRetrieveMetadata;
use Tests\TestCase;

class StorageCleanupCommandsTest extends TestCase
{
    public function refreshDatabase(): void
    {
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('report_files');
        Schema::dropIfExists('organizations');
        Schema::dropIfExists('users');

        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('legal_name')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('registration_number')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_verified')->default(false);
            $table->string('verification_status')->nullable();
            $table->string('s3_bucket')->nullable();
            $table->string('bucket_region')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('report_files', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('organization_id')->nullable();
            $table->string('path')->unique();
            $table->string('type')->nullable();
            $table->string('filename')->nullable();
            $table->string('name')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamps();
        });

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

    public function testReportsSyncSkipsFilesWhenSizeMetadataIsUnavailable(): void
    {
        Organization::factory()->create([
            's3_bucket' => 'prohelper-storage',
            'bucket_region' => 'ru-central1',
        ]);

        $this->app->instance(OrgBucketService::class, new class () extends OrgBucketService {
            public function __construct()
            {
            }

            public function getDisk(Organization $organization): object
            {
                return new class () {
                    public function allFiles(string $directory): array
                    {
                        return ['reports/39/project_profitability_report_1772444161.pdf'];
                    }

                    public function size(string $path): int
                    {
                        throw UnableToRetrieveMetadata::fileSize($path);
                    }
                };
            }
        });

        $this->artisan('reports:sync')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('report_files', [
            'path' => 'reports/39/project_profitability_report_1772444161.pdf',
        ]);
    }

    public function testReportsCleanupSkipsFilesWhenLastModifiedMetadataIsUnavailable(): void
    {
        $organization = Organization::factory()->create([
            's3_bucket' => 'prohelper-storage',
            'bucket_region' => 'ru-central1',
        ]);

        ReportFile::query()->create([
            'organization_id' => $organization->id,
            'path' => 'reports/39/project_profitability_report_1772444161.pdf',
            'type' => '39',
            'filename' => 'project_profitability_report_1772444161.pdf',
            'name' => 'project_profitability_report_1772444161.pdf',
            'size' => 1024,
            'expires_at' => now()->addYear(),
            'user_id' => null,
        ]);

        $this->app->instance(OrgBucketService::class, new class () extends OrgBucketService {
            public function __construct()
            {
            }

            public function getDisk(Organization $organization): object
            {
                return new class () {
                    public array $deleted = [];

                    public function allFiles(string $directory): array
                    {
                        return ['reports/39/project_profitability_report_1772444161.pdf'];
                    }

                    public function lastModified(string $path): int
                    {
                        throw UnableToRetrieveMetadata::lastModified($path);
                    }

                    public function delete(string $path): bool
                    {
                        $this->deleted[] = $path;

                        return true;
                    }
                };
            }
        });

        $this->artisan('reports:cleanup')
            ->assertExitCode(0);

        $this->assertDatabaseHas('report_files', [
            'path' => 'reports/39/project_profitability_report_1772444161.pdf',
        ]);
    }

    public function testAvatarsCleanupKeepsAvatarPathWhenExistenceCheckFails(): void
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
            ->andReturn(new class () {
                public function exists(string $path): bool
                {
                    throw UnableToCheckFileExistence::forLocation($path);
                }
            });

        $this->artisan('avatars:cleanup')
            ->assertExitCode(0);

        $this->assertSame(
            'org-5/avatars/4985eee9-a07a-4724-89c2-f7f2bfb62990.jpg',
            $user->refresh()->avatar_path
        );
    }
}
