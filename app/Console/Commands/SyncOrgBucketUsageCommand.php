<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Storage\OrgBucketService;
use App\Models\Organization;

class SyncOrgBucketUsageCommand extends Command
{
    protected $signature = 'org:sync-bucket-usage {--org=}';
    protected $description = 'Синхронизировать занятое место в S3-бакетах организаций';

    public function handle(OrgBucketService $bucketService): int
    {
        $query = Organization::query();

        if ($orgId = $this->option('org')) {
            $query->where('id', $orgId);
        }

        $query->chunkById(50, function ($orgs) use ($bucketService) {
            foreach ($orgs as $org) {
                try {
                    $bucket = $org->s3_bucket ?? config('filesystems.disks.s3.bucket', 'prohelper-storage');

                    if (!$bucket) {
                        $this->warn("Org {$org->id}: No bucket configured and no default found. Skipping.");
                        continue;
                    }

                    $used = $bucketService->calculateOrganizationSizeMb($bucket, $org);
                    
                    $data = [
                        'storage_used_mb' => $used,
                        'storage_usage_synced_at' => now(),
                    ];

                    // Если бакет не был прописан, пропишем его сейчас
                    if (!$org->s3_bucket) {
                        $data['s3_bucket'] = $bucket;
                        if (!$org->bucket_region) {
                             $data['bucket_region'] = config('filesystems.disks.s3.region', 'ru-central1');
                        }
                    }

                    $org->forceFill($data)->save();
                    
                    $this->info("Org {$org->id}: {$used} MB");
                } catch (\Throwable $e) {
                    $this->error("Ошибка синхронизации организации {$org->id}: " . $e->getMessage());
                }
            }
        });

        return self::SUCCESS;
    }
}
