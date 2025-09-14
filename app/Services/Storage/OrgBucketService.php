<?php

namespace App\Services\Storage;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Models\Organization;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для работы с организационными папками в основном S3 бакете.
 * Теперь используется единый бакет с папками для каждой организации: org-{id}/
 */
class OrgBucketService
{
    protected S3Client $client;

    public function __construct()
    {
        $config = Config::get('filesystems.disks.s3');

        $this->client = new S3Client([
            'region' => $config['region'] ?? 'ru-central1',
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
     * Создаёт папку для организации в основном бакете.
     * Возвращает имя основного бакета.
     */
    public function createBucket(Organization $organization): string
    {
        // Используем основной бакет для всех организаций
        $mainBucket = config('filesystems.disks.s3.bucket', 'prohelper-storage');
        
        // Если у организации уже указан бакет, обновляем на основной
        if ($organization->s3_bucket !== $mainBucket) {
            $organization->forceFill([
                's3_bucket' => $mainBucket,
                'bucket_region' => 'ru-central1',
            ])->save();
        }

        // Папка создается автоматически при первой загрузке файла

        return $mainBucket;
    }

    /**
     * Возвращает Laravel-диск S3, настроенный на основной бакет.
     * Файлы организации автоматически размещаются в папке org-{id}/
     */
    public function getDisk(Organization $organization)
    {
        // Используем основной бакет для всех организаций
        $bucket = config('filesystems.disks.s3.bucket', 'prohelper-storage');

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
        // Если регион не указан, содержит XML/«default», пробуем получить реальный регион
        if ($region === '' || strtolower($region) === 'default' || str_contains($region, '<')) {
            try {
                Log::debug('[OrgBucketService] Fetching bucket location from S3');
                $loc = $this->client->getBucketLocation(['Bucket' => $bucket]);
                $regionRaw = is_array($loc)
                    ? ($loc['LocationConstraint'] ?? '')
                    : ($loc->get('LocationConstraint') ?? '');

                $region = trim(strip_tags((string) $regionRaw));
                // Yandex Object Storage: для подписи нужен «ru-central1». Пустое или default приводим к ru-central1
                if ($region === '' || strtolower($region) === 'default') {
                    $region = 'ru-central1';
                }
                Log::debug('[OrgBucketService] getBucketLocation() result', [ 'raw' => $regionRaw, 'clean' => $region ]);
            } catch (\Throwable $e) {
                Log::warning('[OrgBucketService] Failed to getBucketLocation()', [
                    'error' => $e->getMessage(),
                ]);
                $region = 'ru-central1';
            }
        }

        // fallback, если после всех манипуляций регион всё ещё пуст
        if ($region === '') {
            $region = 'ru-central1';
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
     * Подсчитывает размер папки организации в основном бакете в мегабайтах.
     */
    public function calculateBucketSizeMb(string $bucket): float
    {
        return $this->calculateOrganizationSizeMb($bucket, null);
    }

    /**
     * Подсчитывает размер папки конкретной организации в мегабайтах.
     */
    public function calculateOrganizationSizeMb(string $bucket, ?Organization $organization = null): float
    {
        $bytes = 0;
        $token = null;
        
        // Если передана организация, считаем только её папку
        $prefix = $organization ? "org-{$organization->id}/" : '';
        
        do {
            $args = ['Bucket' => $bucket];
            if ($prefix) {
                $args['Prefix'] = $prefix;
            }
            if ($token) {
                $args['ContinuationToken'] = $token;
            }
            $resp = $this->client->listObjectsV2($args);
            foreach ($resp['Contents'] ?? [] as $obj) {
                $bytes += $obj['Size'];
            }
            $token = $resp['NextContinuationToken'] ?? null;
        } while ($token);

        return round($bytes / 1_048_576, 2); // в МБ
    }

} 