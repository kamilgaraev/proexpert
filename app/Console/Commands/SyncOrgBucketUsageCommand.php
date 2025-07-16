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
        $query = Organization::query()->whereNotNull('s3_bucket');

        if ($orgId = $this->option('org')) {
            $query->where('id', $orgId);
        }

        $query->chunkById(50, function ($orgs) use ($bucketService) {
            foreach ($orgs as $org) {
                try {
                    $used = $bucketService->calculateBucketSizeMb($org->s3_bucket);
                    $org->forceFill([
                        'storage_used_mb' => $used,
                        'storage_usage_synced_at' => now(),
                    ])->save();
                    $this->info("Org {$org->id}: {$used} MB");
                } catch (\Throwable $e) {
                    $this->error("Ошибка синхронизации организации {$org->id}: " . $e->getMessage());
                }
            }
        });

        return self::SUCCESS;
    }
} 