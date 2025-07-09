<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class CleanupBrokenAvatarsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * --dry-run   : только вывод статистики, БД не изменяется
     * --disk      : диск, на котором хранятся аватары (по умолчанию s3)
     * --chunk     : размер пачки при обходе пользователей
     */
    protected $signature = 'avatars:cleanup {--dry-run : Показывать, но не изменять данные} {--disk=s3 : Какой диск проверить} {--chunk=1000 : Кол-во пользователей за одну итерацию}';

    protected $description = 'Сбрасывает avatar_path у пользователей, если файл отсутствует на диске.';

    public function handle(): int
    {
        $diskName = $this->option('disk');
        $dryRun   = $this->option('dry-run');
        $chunk    = (int) $this->option('chunk');

        if (!config("filesystems.disks.{$diskName}")) {
            $this->error("Диск {$diskName} не найден в конфигурации filesystems.php");
            return self::FAILURE;
        }

        $storage = Storage::disk($diskName);

        $this->info("Старт проверки пользователей (disk={$diskName}, chunk={$chunk}, dryRun=" . ($dryRun ? 'yes' : 'no') . ")");

        $processed = 0;
        $cleared   = 0;

        User::whereNotNull('avatar_path')->chunkById($chunk, function ($users) use (&$processed, &$cleared, $storage, $dryRun) {
            foreach ($users as $user) {
                $processed++;
                if (!$storage->exists($user->avatar_path)) {
                    $cleared++;
                    $this->line("[MISSING] id={$user->id} path={$user->avatar_path}");

                    if (!$dryRun) {
                        $user->avatar_path = null;
                        $user->save();
                        Log::info('[AvatarCleanup] Cleared broken avatar', ['user_id' => $user->id]);
                    }
                }
            }
        });

        $this->info("Проверено: {$processed}, сброшено: {$cleared}" . ($dryRun ? ' (dry-run)' : ''));

        return self::SUCCESS;
    }
} 