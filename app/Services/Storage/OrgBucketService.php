<?php

namespace App\Services\Storage;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Organization;

class OrgBucketService
{
    protected S3Client $client;

    public function __construct()
    {
        $config = Config::get('filesystems.disks.s3');

        $this->client = new S3Client([
            'region' => $config['region'] ?? 'us-east-1',
            'version' => 'latest',
            'credentials' => [
                'key'    => $config['key'] ?? '',
                'secret' => $config['secret'] ?? '',
            ],
            'endpoint' => $config['endpoint'] ?? null,
            'use_path_style_endpoint' => $config['use_path_style_endpoint'] ?? false,
        ]);
    }

    /**
     * Создаёт отдельный бакет для организации, если он ещё не создан.
     * Возвращает имя бакета.
     */
    public function createBucket(Organization $organization): string
    {
        if ($organization->s3_bucket) {
            return $organization->s3_bucket;
        }

        $bucket = 'org-' . $organization->id . '-' . Str::lower(Str::random(6));

        try {
            // Создание бакета
            $this->client->createBucket(['Bucket' => $bucket]);
            // Ждём, пока бакет появится
            $this->client->waitUntil('BucketExists', ['Bucket' => $bucket]);
        } catch (\Aws\S3\Exception\S3Exception $e) {
            if ($e->getAwsErrorCode() === 'InvalidLocationConstraint') {
                // Повторяем с region = us-east-1 (для провайдеров, которым не нравится другое значение)
                $fallbackClient = new S3Client([
                    'region' => 'us-east-1',
                    'version' => 'latest',
                    'credentials' => $this->client->getCredentials()->wait(),
                    'endpoint' => $this->client->getEndpoint(),
                    'use_path_style_endpoint' => true,
                ]);

                $fallbackClient->createBucket(['Bucket' => $bucket]);
                $fallbackClient->waitUntil('BucketExists', ['Bucket' => $bucket]);
                // Заменяем клиента, чтобы последующие вызовы шли с новым конфигом
                $this->client = $fallbackClient;
            } else {
                throw $e;
            }
        }

        // Включаем versioning
        $this->client->putBucketVersioning([
            'Bucket' => $bucket,
            'VersioningConfiguration' => [
                'Status' => 'Enabled',
            ],
        ]);

        // Включаем шифрование SSE-S3 (AES256)
        $this->client->putBucketEncryption([
            'Bucket' => $bucket,
            'ServerSideEncryptionConfiguration' => [
                'Rules' => [[
                    'ApplyServerSideEncryptionByDefault' => [
                        'SSEAlgorithm' => 'AES256',
                    ],
                ]],
            ],
        ]);

        $organization->forceFill(['s3_bucket' => $bucket])->save();

        return $bucket;
    }

    /**
     * Возвращает Laravel-диск S3, настроенный на бакет организации.
     */
    public function getDisk(Organization $organization)
    {
        $bucket = $organization->s3_bucket;
        $config = Config::get('filesystems.disks.s3');
        $diskConfig = array_merge($config, ['bucket' => $bucket]);
        return Storage::build($diskConfig);
    }

    /**
     * Подсчитывает размер бакета в мегабайтах.
     */
    public function calculateBucketSizeMb(string $bucket): int
    {
        $bytes = 0;
        $token = null;
        do {
            $args = ['Bucket' => $bucket];
            if ($token) {
                $args['ContinuationToken'] = $token;
            }
            $resp = $this->client->listObjectsV2($args);
            foreach ($resp['Contents'] ?? [] as $obj) {
                $bytes += $obj['Size'];
            }
            $token = $resp['NextContinuationToken'] ?? null;
        } while ($token);

        return (int) round($bytes / 1_048_576); // в МБ
    }
} 