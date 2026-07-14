<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\Models\Organization;
use App\Services\Logging\LoggingService;
use App\Services\Storage\FileService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

final class FileServicePrivacyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $container = new Container();
        $container->instance('log', new class
        {
            public function __call(string $name, array $arguments): void {}
        });
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        parent::tearDown();
    }

    public function test_privacy_mode_redacts_upload_identifiers_and_exception_details(): void
    {
        $logging = $this->loggingSpy();
        $disk = new class extends FilesystemAdapter
        {
            public function __construct() {}

            public function getConfig(): array
            {
                return ['driver' => 's3', 'bucket' => 'private'];
            }

            public function put($path, $contents, $options = []): bool
            {
                throw new \RuntimeException('secret-plan.pdf /tmp/private-upload');
            }
        };
        $service = $this->service($logging, $disk);
        $organization = new Organization();
        $organization->id = 17;

        $result = $service->upload(
            UploadedFile::fake()->createWithContent('secret-plan.pdf', 'private-content'),
            'estimate-generation/sessions/42/documents',
            organization: $organization,
            privacyMode: true,
        );

        self::assertFalse($result);
        $encoded = var_export($logging->contexts, true);
        self::assertStringNotContainsString('secret-plan.pdf', $encoded);
        self::assertStringNotContainsString('/tmp/private-upload', $encoded);
        self::assertStringNotContainsString('estimate-generation/sessions/42', $encoded);
        self::assertStringContainsString('redacted', $encoded);
    }

    public function test_default_mode_preserves_existing_observable_logging(): void
    {
        $logging = $this->loggingSpy();
        $disk = new class extends FilesystemAdapter
        {
            public function __construct() {}

            public function getConfig(): array
            {
                return ['driver' => 's3', 'bucket' => 'private'];
            }

            public function put($path, $contents, $options = []): bool
            {
                throw new \RuntimeException('ordinary.pdf /tmp/ordinary-upload');
            }
        };
        $service = $this->service($logging, $disk);
        $organization = new Organization();
        $organization->id = 17;

        $result = $service->upload(
            UploadedFile::fake()->createWithContent('ordinary.pdf', 'ordinary-content'),
            'documents',
            organization: $organization,
        );

        self::assertFalse($result);
        $encoded = var_export($logging->contexts, true);
        self::assertStringContainsString('ordinary.pdf', $encoded);
        self::assertStringContainsString('/tmp/ordinary-upload', $encoded);
    }

    private function service(LoggingService $logging, FilesystemAdapter $disk): FileService
    {
        return new class($logging, $disk) extends FileService
        {
            public function __construct(LoggingService $logging, private readonly FilesystemAdapter $fakeDisk)
            {
                parent::__construct($logging);
            }

            public function disk(?Organization $organization = null): FilesystemAdapter
            {
                return $this->fakeDisk;
            }
        };
    }

    private function loggingSpy(): LoggingService
    {
        return new class extends LoggingService
        {
            public array $contexts = [];

            public function __construct() {}

            public function technical(string $event, array $context = [], string $level = 'info'): void
            {
                $this->contexts[] = [$event, $context];
            }

            public function business(string $event, array $context = []): void
            {
                $this->contexts[] = [$event, $context];
            }
        };
    }
}
