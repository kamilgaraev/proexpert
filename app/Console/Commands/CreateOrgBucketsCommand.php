<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Storage\OrgBucketService;
use App\Models\Organization;

class CreateOrgBucketsCommand extends Command
{
    protected $signature = 'org:create-buckets {--with-sync : Копировать файлы из общего бакета (требует настройку shared bucket)} {--org=}';
    protected $description = 'Создать S3-бакеты для организаций, у которых они ещё не созданы.';

    public function handle(OrgBucketService $bucketService): int
    {
        $query = Organization::query()->whereNull('s3_bucket');
        if ($id = $this->option('org')) {
            $query->where('id', $id);
        }

        $withSync = (bool) $this->option('with-sync');

        $query->chunkById(50, function ($orgs) use ($bucketService, $withSync) {
            foreach ($orgs as $org) {
                try {
                    $bucket = $bucketService->createBucket($org);
                    $this->info("Создан бакет {$bucket} для организации {$org->id}");
                    if ($withSync) {
                        // Здесь можно вызвать shell-команду aws s3 sync или аналогичный механизм
                        // оставляем TODO, чтобы не зависеть от окружения.
                        $this->warn(' --with-sync указан, но копирование объектов не реализовано в этой версии команды.');
                    }
                } catch (\Throwable $e) {
                    $this->error("Ошибка организации {$org->id}: " . $e->getMessage());
                }
            }
        });

        return self::SUCCESS;
    }
} 