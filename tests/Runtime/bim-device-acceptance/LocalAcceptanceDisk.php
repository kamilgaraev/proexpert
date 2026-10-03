<?php

declare(strict_types=1);

namespace Tests\Runtime\BimDeviceAcceptance;

use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\S3Client;
use Aws\S3\S3ClientInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\FilesystemAdapter;
use RuntimeException;

final class LocalAcceptanceDisk extends FilesystemAdapter
{
    public function getClient(): S3ClientInterface
    {
        return new S3Client([
            'version' => '2006-03-01',
            'region' => 'acceptance-local',
            'credentials' => ['key' => 'test-only', 'secret' => 'test-only'],
            'endpoint' => 'http://127.0.0.1',
            'handler' => function (CommandInterface $command) {
                $key = (string) $command['Key'];
                $prefix = (string) $this->getConfig()['organization_prefix'];
                if (! str_starts_with($key, $prefix) || str_contains($key, '..') || str_contains($key, '\\') || ! $this->exists($key)) {
                    throw new RuntimeException('bim_acceptance_storage_path_invalid');
                }
                if ($command->getName() === 'HeadObject') {
                    return Create::promiseFor(new Result([
                        'ContentLength' => $this->size($key),
                        'ContentType' => $this->mimeType($key),
                        'ETag' => '"'.hash_file('sha256', $this->path($key)).'"',
                    ]));
                }
                if ($command->getName() === 'GetObject') {
                    return Create::promiseFor(new Result(['Body' => Utils::streamFor($this->readStream($key))]));
                }
                throw new RuntimeException('bim_acceptance_storage_operation_forbidden');
            },
        ]);
    }
}
