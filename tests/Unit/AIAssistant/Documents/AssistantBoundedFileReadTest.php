<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Documents;

use App\Services\Logging\LoggingService;
use App\Services\Storage\FileService;
use Aws\Result;
use Aws\S3\S3ClientInterface;
use GuzzleHttp\Psr7\Utils;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class AssistantBoundedFileReadTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_read_sets_network_deadline_and_byte_range_on_real_s3_boundary(): void
    {
        $client = Mockery::mock(S3ClientInterface::class);
        $client->shouldReceive('getObject')->once()->with([
            'Bucket' => 'test-bucket', 'Key' => 'org-42/documents/file.pdf', 'Range' => 'bytes=0-25000000',
            '@http' => ['connect_timeout' => 5, 'timeout' => 10],
        ])->andReturn(new Result(['Body' => Utils::streamFor('PDF bytes')]));
        $files = $this->files($client);
        $stream = $files->readCurrentBounded('org-42/documents/file.pdf', 10, 25_000_001);
        try {
            self::assertIsResource($stream);
            self::assertSame('PDF bytes', stream_get_contents($stream));
        } finally {
            fclose($stream);
        }
    }

    public function test_invalid_path_is_rejected_before_the_s3_request(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->files(Mockery::mock(S3ClientInterface::class))->readCurrentBounded('org-42/../org-43/private.pdf', 10, 100);
    }

    private function files(S3ClientInterface $client): FileService
    {
        return new class($client) extends FileService {
            public function __construct(private readonly S3ClientInterface $client)
            {
                parent::__construct(Mockery::mock(LoggingService::class));
            }
            protected function s3Client(): S3ClientInterface { return $this->client; }
            protected function reportBucket(): string { return 'test-bucket'; }
        };
    }
}
