<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\Services\Storage\StorageRuntimeConfiguration;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StorageRuntimeConfigurationTest extends TestCase
{
    public function test_it_accepts_complete_timeweb_configuration(): void
    {
        $configuration = StorageRuntimeConfiguration::fromConfig($this->validConfig(), true);

        self::assertSame('prohelper-storage', $configuration->bucket);
        self::assertSame('ru-1', $configuration->region);
        self::assertSame('https://s3.twcstorage.ru', $configuration->endpoint);
        self::assertSame(300, $configuration->downloadTtlSeconds);
        self::assertSame(900, $configuration->uploadTtlSeconds);
    }

    #[DataProvider('invalidProductionConfigurationProvider')]
    public function test_it_rejects_invalid_production_configuration(array $config): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('storage_configuration_invalid');

        StorageRuntimeConfiguration::fromConfig($config, true);
    }

    public static function invalidProductionConfigurationProvider(): iterable
    {
        $valid = (new self('test'))->validConfig();

        $missingSecret = $valid;
        $missingSecret['disks']['s3']['secret'] = null;
        yield 'missing secret' => [$missingSecret];

        $wrongEndpoint = $valid;
        $wrongEndpoint['disks']['s3']['endpoint'] = 'http://s3.twcstorage.ru';
        yield 'non-https endpoint' => [$wrongEndpoint];

        $invalidDownloadTtl = $valid;
        $invalidDownloadTtl['s3']['download_ttl_seconds'] = 0;
        yield 'invalid download ttl' => [$invalidDownloadTtl];

        $invalidUploadTtl = $valid;
        $invalidUploadTtl['s3']['upload_ttl_seconds'] = 86_401;
        yield 'invalid upload ttl' => [$invalidUploadTtl];
    }

    public function test_it_allows_missing_credentials_outside_production(): void
    {
        $config = $this->validConfig();
        $config['disks']['s3']['key'] = null;
        $config['disks']['s3']['secret'] = null;

        $configuration = StorageRuntimeConfiguration::fromConfig($config, false);

        self::assertSame('prohelper-storage', $configuration->bucket);
    }

    /** @return array<string, mixed> */
    private function validConfig(): array
    {
        return [
            'disks' => [
                's3' => [
                    'driver' => 's3',
                    'key' => 'timeweb-access',
                    'secret' => 'timeweb-secret',
                    'region' => 'ru-1',
                    'bucket' => 'prohelper-storage',
                    'endpoint' => 'https://s3.twcstorage.ru',
                    'use_path_style_endpoint' => true,
                    'visibility' => 'private',
                    'throw' => true,
                ],
            ],
            's3' => [
                'download_ttl_seconds' => 300,
                'upload_ttl_seconds' => 900,
            ],
        ];
    }
}
