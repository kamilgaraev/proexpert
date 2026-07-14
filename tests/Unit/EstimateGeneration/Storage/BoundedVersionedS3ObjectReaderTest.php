<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Storage;

use App\BusinessModules\Addons\EstimateGeneration\Storage\BoundedVersionedS3ObjectReader;
use App\Services\Storage\FileService;
use DomainException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BoundedVersionedS3ObjectReaderTest extends TestCase
{
    #[Test]
    public function it_accepts_only_the_exact_tenant_size_hash_and_version(): void
    {
        $body = '{"ok":true}';
        $reader = new BoundedVersionedS3ObjectReader($this->files($body));

        $object = $reader->read(
            7,
            'org-7/estimate-generation/sessions/3/pipeline/attempts/a/object.json',
            1024,
            strlen($body),
            'sha256:'.hash('sha256', $body),
            'version-7',
        );

        self::assertSame($body, $object->body);
        self::assertSame(strlen($body), $object->bytes);
        self::assertSame('version-7', $object->versionId);
    }

    #[Test]
    public function it_rejects_a_locator_hash_that_does_not_match_the_versioned_body(): void
    {
        $reader = new BoundedVersionedS3ObjectReader($this->files('content'));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('estimate_generation_object_integrity_failed');

        $reader->read(
            7,
            'org-7/estimate-generation/sessions/3/document.txt',
            1024,
            7,
            'sha256:'.str_repeat('0', 64),
            'version-7',
        );
    }

    private function files(string $body): FileService
    {
        return new class($body) extends FileService
        {
            public function __construct(private readonly string $body) {}

            public function describeVersion(string $path, ?string $versionId, int $maxBytes = 64_000_000): array
            {
                return [
                    'path' => $path,
                    'body' => $this->body,
                    'size' => strlen($this->body),
                    'sha256' => hash('sha256', $this->body),
                    'etag' => 'etag',
                    'version_id' => 'version-7',
                    'content_type' => 'application/json',
                ];
            }
        };
    }
}
