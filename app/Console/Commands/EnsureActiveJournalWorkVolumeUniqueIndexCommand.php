<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CompletedWork\ActiveJournalWorkVolumeUniqueness;
use Illuminate\Console\Command;

final class EnsureActiveJournalWorkVolumeUniqueIndexCommand extends Command
{
    protected $signature = 'completed-works:ensure-active-journal-volume-unique-index';

    protected $description = 'Создать уникальный индекс активного факта на объём журнала, если дублей больше нет.';

    public function handle(ActiveJournalWorkVolumeUniqueness $uniqueness): int
    {
        $result = $uniqueness->ensureUniqueIndex();

        return match ($result) {
            'created' => $this->created(),
            'already_exists' => $this->alreadyExists(),
            'skipped_duplicates' => $this->duplicatesRemain($uniqueness),
            default => $this->unsupported(),
        };
    }

    private function created(): int
    {
        $this->info('Уникальный индекс активного факта на объём журнала создан.');

        return self::SUCCESS;
    }

    private function alreadyExists(): int
    {
        $this->info('Уникальный индекс активного факта на объём журнала уже есть. Повторный запуск ничего не меняет.');

        return self::SUCCESS;
    }

    private function duplicatesRemain(ActiveJournalWorkVolumeUniqueness $uniqueness): int
    {
        $groups = count($uniqueness->duplicateGroups());
        $this->error(
            'Индекс не создан: остались дубли активных фактов на один объём журнала'
            ." (групп: {$groups}). Разберите их вручную по сверке истории объёмов,"
            .' затем запустите команду снова. Строки факта команда не изменяет и не удаляет.'
        );

        return self::FAILURE;
    }

    private function unsupported(): int
    {
        $this->warn('Команда поддерживает только PostgreSQL с колонкой journal_work_volume_id.');

        return self::FAILURE;
    }
}
