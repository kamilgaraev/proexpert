<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\Models\Organization;
use App\Services\Logging\LoggingService;
use App\Services\Storage\FileService;
use Illuminate\Container\Container;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

final class FileServicePrivacyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $container = new Container;
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
        $organization = new Organization;
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
        $organization = new Organization;
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

    public function test_privacy_mode_logs_only_allowlisted_storage_failure_code(): void
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
                throw new \RuntimeException('s3_object_tagging_failed');
            }
        };
        $service = $this->service($logging, $disk);
        $organization = new Organization;
        $organization->id = 17;

        $result = $service->upload(
            UploadedFile::fake()->createWithContent('secret-plan.pdf', 'private-content'),
            'estimate-generation/sessions/42/documents',
            organization: $organization,
            privacyMode: true,
        );

        self::assertFalse($result);
        $failedUpload = collect($logging->contexts)
            ->first(static fn (array $entry): bool => $entry[0] === 's3.upload.failed');

        self::assertIsArray($failedUpload);
        self::assertSame('s3_object_tagging_failed', $failedUpload[1]['failure_code'] ?? null);
        self::assertSame('redacted', $failedUpload[1]['exception_message'] ?? null);
    }

    public function test_estimator_preview_signing_failure_does_not_log_paths_signed_urls_or_document_contents(): void
    {
        $logger = new class
        {
            public array $contexts = [];

            public function warning(string $message, array $context): void
            {
                $this->contexts[] = $context;
            }
        };
        \Illuminate\Support\Facades\Log::swap($logger);
        $disk = new class extends FilesystemAdapter
        {
            public function __construct() {}

            public function temporaryUrl($path, $expiration, array $options = []): string
            {
                throw new \RuntimeException('https://private.example.test/document?signature=signing-canary secret-drawing-content');
            }
        };
        $result = $this->service($this->loggingSpy(), $disk)->temporaryUrl('org-17/estimate-generation/sessions/42/secret-project.pdf');
        self::assertNull($result);
        $encoded = var_export($logger->contexts, true);
        foreach (['secret-project.pdf', 'org-17', 'signing-canary', 'private.example.test', 'secret-drawing-content'] as $secret) {
            self::assertStringNotContainsString($secret, $encoded);
        }
        self::assertSame('estimate_document_preview_signing_failed', $logger->contexts[0]['failure_code']);
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
