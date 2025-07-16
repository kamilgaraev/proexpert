<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\Storage\OrgBucketService;
use App\Models\Organization;
use App\Models\PersonalFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class CleanupPersonalFilesCommand extends Command
{
    protected $signature = 'personals:cleanup {--dry-run : Тестовый запуск без удаления} {--days=365 : Удалять физические файлы без записи в БД, если им более N дней}';

    protected $description = 'Синхронизирует таблицу personal_files с объектами в S3 и при необходимости удаляет лишние объекты.';

    public function handle(OrgBucketService $bucketService): int
    {
        $dry = (bool) $this->option('dry-run');
        $days = (int) $this->option('days');
        $cutoff = Carbon::now()->subDays($days);

        $dbDeleted = 0;
        $s3Deleted = 0;

        Organization::query()->whereNotNull('s3_bucket')->chunkById(50, function ($orgs) use (&$dbDeleted, &$s3Deleted, $bucketService, $dry, $cutoff) {
            foreach ($orgs as $org) {
                $disk = $bucketService->getDisk($org);
                // Список всех объектов (файлы + zero-byte "папки")
                $objects = $disk->allFiles();
                $objectSet = array_flip($objects);

                // 1) Удаляем записи, для которых файла нет
                $userIds = $org->users()->pluck('users.id')->all();
                if (!$userIds) { continue; }

                $lostRecords = PersonalFile::whereIn('user_id', $userIds)
                    ->whereNotIn('path', $objects)
                    ->get();
                foreach ($lostRecords as $rec) {
                    if ($dry) {
                        $this->line("[DRY] DB delete {$rec->path}");
                    } else {
                        $rec->delete();
                        $dbDeleted++;
                    }
                }

                // 2) Удаляем физические объекты без записи в БД (старше cutoff)
                $userIds = $org->users()->pluck('users.id')->all();
                if (!$userIds) { continue; }

                $knownPaths = PersonalFile::whereIn('user_id', $userIds)->pluck('path', 'path')->all();
                foreach ($objects as $obj) {
                    if (!isset($knownPaths[$obj])) {
                        $lastMod = Carbon::createFromTimestamp($disk->lastModified($obj));
                        if ($lastMod->lessThan($cutoff)) {
                            if ($dry) {
                                $this->line("[DRY] S3 delete {$obj}");
                            } else {
                                $disk->delete($obj);
                                $s3Deleted++;
                                Log::info('[personals:cleanup] deleted orphan file', ['path'=>$obj]);
                            }
                        }
                    }
                }
            }
        });

        if ($dry) {
            $this->info('DRY RUN завершён.');
            return Command::SUCCESS;
        }

        $this->info("Удалено записей БД: {$dbDeleted}, удалено файлов в S3: {$s3Deleted}.");
        return Command::SUCCESS;
    }
} 