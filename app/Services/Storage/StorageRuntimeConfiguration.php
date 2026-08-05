<?php

declare(strict_types=1);

namespace App\Services\Storage;

use InvalidArgumentException;

final readonly class StorageRuntimeConfiguration
{
    private const ENDPOINT = 'https://s3.twcstorage.ru';

    private const BUCKET = 'prohelper-storage';

    private const REGION = 'ru-1';

    private const MAX_TTL_SECONDS = 3600;

    private function __construct(
        public string $bucket,
        public string $region,
        public string $endpoint,
        public int $downloadTtlSeconds,
        public int $uploadTtlSeconds,
    ) {}

    /** @param array<string, mixed> $config */
    public static function fromConfig(array $config, bool $production): self
    {
        $disks = $config['disks'] ?? null;
        $disk = is_array($disks) ? ($disks['s3'] ?? null) : null;
        $timeouts = $config['s3'] ?? null;

        if (! is_array($disk) || ! is_array($timeouts)) {
            throw new InvalidArgumentException('storage_configuration_invalid');
        }

        $bucket = self::requiredString($disk, 'bucket');
        $region = self::requiredString($disk, 'region');
        $endpoint = self::requiredString($disk, 'endpoint');
        $downloadTtlSeconds = self::ttl($timeouts, 'download_ttl_seconds');
        $uploadTtlSeconds = self::ttl($timeouts, 'upload_ttl_seconds');

        if (
            ($disk['driver'] ?? null) !== 's3'
            || $bucket !== self::BUCKET
            || $region !== self::REGION
            || $endpoint !== self::ENDPOINT
            || ($disk['use_path_style_endpoint'] ?? null) !== true
            || ($disk['visibility'] ?? null) !== 'private'
            || ($disk['throw'] ?? null) !== true
        ) {
            throw new InvalidArgumentException('storage_configuration_invalid');
        }

        if ($production) {
            self::requiredString($disk, 'key');
            self::requiredString($disk, 'secret');
        }

        return new self(
            $bucket,
            $region,
            $endpoint,
            $downloadTtlSeconds,
            $uploadTtlSeconds,
        );
    }

    /** @param array<string, mixed> $values */
    private static function requiredString(array $values, string $key): string
    {
        $value = $values[$key] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('storage_configuration_invalid');
        }

        return trim($value);
    }

    /** @param array<string, mixed> $values */
    private static function ttl(array $values, string $key): int
    {
        $value = $values[$key] ?? null;
        if (! is_int($value) || $value < 1 || $value > self::MAX_TTL_SECONDS) {
            throw new InvalidArgumentException('storage_configuration_invalid');
        }

        return $value;
    }
}
