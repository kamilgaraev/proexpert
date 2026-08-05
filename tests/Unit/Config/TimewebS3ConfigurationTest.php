<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

final class TimewebS3ConfigurationTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    private ?Container $originalContainer = null;

    /** @var array<string, string> */
    private const ENVIRONMENT = [
        'FILESYSTEM_DISK' => 's3',
        'MOST_S3_ACCESS_KEY_ID' => 'timeweb-access',
        'MOST_S3_SECRET_ACCESS_KEY' => 'timeweb-secret',
        'MOST_S3_REGION' => 'ru-1',
        'MOST_S3_BUCKET' => 'prohelper-storage',
        'MOST_S3_ENDPOINT' => 'https://s3.twcstorage.ru',
        'MOST_S3_USE_PATH_STYLE_ENDPOINT' => 'true',
        'MOST_S3_DOWNLOAD_TTL_SECONDS' => '300',
        'MOST_S3_UPLOAD_TTL_SECONDS' => '900',
        'AWS_ACCESS_KEY_ID' => 'legacy-access',
        'AWS_SECRET_ACCESS_KEY' => 'legacy-secret',
        'AWS_DEFAULT_REGION' => 'legacy-region',
        'AWS_ENDPOINT' => 'https://storage.yandexcloud.net',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::ENVIRONMENT as $key => $value) {
            $this->originalEnvironment[$key] = getenv($key);
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        $this->originalContainer = Container::getInstance();
        Container::setInstance(new Application(dirname(__DIR__, 3)));
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->originalContainer);

        foreach ($this->originalEnvironment as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);

                continue;
            }

            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        parent::tearDown();
    }

    public function test_it_configures_one_private_timeweb_s3_disk(): void
    {
        $config = require __DIR__.'/../../../config/filesystems.php';

        self::assertIsArray($config);
        self::assertSame('s3', $config['default']);
        self::assertSame(['local', 'public', 's3'], array_keys($config['disks']));
        self::assertSame([
            'driver' => 's3',
            'key' => 'timeweb-access',
            'secret' => 'timeweb-secret',
            'region' => 'ru-1',
            'bucket' => 'prohelper-storage',
            'endpoint' => 'https://s3.twcstorage.ru',
            'use_path_style_endpoint' => true,
            'visibility' => 'private',
            'throw' => true,
            'report' => false,
        ], $config['disks']['s3']);
        self::assertSame([
            'download_ttl_seconds' => 300,
            'upload_ttl_seconds' => 900,
        ], $config['s3']);
    }

    public function test_it_contains_no_legacy_storage_configuration(): void
    {
        $source = file_get_contents(__DIR__.'/../../../config/filesystems.php');

        self::assertIsString($source);
        self::assertStringNotContainsString('storage.yandexcloud.net', $source);
        self::assertStringNotContainsString('REPORTS_BUCKET', $source);
        self::assertStringNotContainsString('AWS_PERSONALS_BUCKET', $source);
        self::assertStringNotContainsString("'reports' =>", $source);
        self::assertStringNotContainsString("'personals' =>", $source);
    }
}
