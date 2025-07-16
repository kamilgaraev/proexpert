<?php

namespace App\Services\Storage;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Organization;
use Illuminate\Support\Facades\Log;

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
        } catch (\Throwable $e) {
            // Логируем и пробрасываем — для Yandex OS повторов не делаем
            Log::error('[OrgBucketService] createBucket failed', [
                'bucket' => $bucket,
                'err' => $e->getMessage(),
            ]);
            throw $e;
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

        $organization->forceFill([
            's3_bucket' => $bucket,
            'bucket_region' => 'us-east-1',
        ])->save();

        return $bucket;
    }

    /**
     * Возвращает Laravel-диск S3, настроенный на бакет организации.
     */
    public function getDisk(Organization $organization)
    {
        $bucket = $organization->s3_bucket;

        // Гарантируем существование бакета
        $this->ensureBucketExists($bucket);

        Log::debug('[OrgBucketService] getDisk(): start', [
            'org_id' => $organization->id,
            'bucket' => $bucket,
            'bucket_region_original' => $organization->bucket_region,
        ]);
        $regionOriginal = $organization->bucket_region;
        // Sanitize (удаляем возможные XML-теги и пробелы)
        $region = trim(strip_tags((string) $regionOriginal));

        Log::debug('[OrgBucketService] Region after sanitize', [
            'region' => $region,
        ]);
        // Если регион не указан, содержит XML/«default» ИЛИ равен us-east-1 (заглушка), пробуем получить реальный регион
        if ($region === '' || strtolower($region) === 'default' || str_contains($region, '<') || strtolower($region) === 'us-east-1') {
            try {
                Log::debug('[OrgBucketService] Fetching bucket location from S3');
                $loc = $this->client->getBucketLocation(['Bucket' => $bucket]);
                $regionRaw = is_array($loc)
                    ? ($loc['LocationConstraint'] ?? '')
                    : ($loc->get('LocationConstraint') ?? '');

                $region = trim(strip_tags((string) $regionRaw));
                // Regru-S3: для подписи нужен «ru-msk». Пустое, default или us-east-1 приводим к ru-msk
                if ($region === '' || strtolower($region) === 'us-east-1' || strtolower($region) === 'default') {
                    $region = 'ru-msk';
                }
                Log::debug('[OrgBucketService] getBucketLocation() result', [ 'raw' => $regionRaw, 'clean' => $region ]);
            } catch (\Throwable $e) {
                Log::warning('[OrgBucketService] Failed to getBucketLocation()', [
                    'error' => $e->getMessage(),
                ]);
                $region = 'us-east-1';
            }
        }

        // fallback, если после всех манипуляций регион всё ещё пуст
        if ($region === '') {
            $region = 'ru-msk';
        }
        
        // Если регион обновился после санитации или получения из S3 — сохраняем изменение, обрезая до 120 символов
        if ($region !== $regionOriginal) {
            Log::debug('[OrgBucketService] Persisting updated bucket_region', [
                'old' => $regionOriginal,
                'new' => $region,
            ]);
            $organization->forceFill(['bucket_region' => substr($region, 0, 120)])->save();
        }
        
        $config = Config::get('filesystems.disks.s3');

        // Yandex Object Storage: стандартный virtual-host стиль, path-style не нужен.
        // Убираем кастомные твики для Regru, оставляем базовый конфиг.
        $diskConfig = array_merge($config, [
            'bucket' => $bucket,
            'use_path_style_endpoint' => false,
            'region' => $region ?: ($config['region'] ?? 'ru-central1'),
        ]);
        Log::debug('[OrgBucketService] Building disk', [
            'config' => $diskConfig,
        ]);
        return Storage::build($diskConfig);
    }

    /**
     * Подсчитывает размер бакета в мегабайтах.
     */
    public function calculateBucketSizeMb(string $bucket): int
    {
        // Гарантируем существование бакета
        $this->ensureBucketExists($bucket);
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

    /**
     * Гарантирует наличие бакета: пытается создать, а если уже существует – игнорирует ошибку.
     */
    private function ensureBucketExists(string $bucket): void
    {
        try {
            $this->client->createBucket(['Bucket' => $bucket]);
            $this->client->waitUntil('BucketExists', ['Bucket' => $bucket]);
        } catch (\Aws\S3\Exception\S3Exception $e) {
            $code = $e->getAwsErrorCode();
            if (in_array($code, ['BucketAlreadyOwnedByYou', 'BucketAlreadyExists'])) {
                return; // бакет уже есть – это нормально
            }
            if (in_array($code, ['NotFound', 'NoSuchBucket'])) {
                // Параллельный запрос мог удалить бакет – повторяем один раз
                $this->client->createBucket(['Bucket' => $bucket]);
                $this->client->waitUntil('BucketExists', ['Bucket' => $bucket]);
                return;
            }
            throw $e;
        }
    }
} 