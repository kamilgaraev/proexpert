<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CompletedWork\CompletedWorkHistoryTransformationService;
use Illuminate\Console\Command;

final class TransformCompletedWorkHistoryCommand extends Command
{
    protected $signature = 'completed-works:transform-history
        {--organization= : Идентификатор организации}
        {--project= : Идентификатор проекта}
        {--dry-run : Только сверка: ничего не записывать}';

    protected $description = 'Преобразовать однозначную историю объёмов выполненных работ выбранного проекта.';

    public function handle(CompletedWorkHistoryTransformationService $service): int
    {
        $organizationId = (int) $this->option('organization');
        $projectId = (int) $this->option('project');
        if ($organizationId <= 0 || $projectId <= 0) {
            $this->error('Укажите организацию и проект. Глобальный прогон по всей базе не выполняется.');

            return self::FAILURE;
        }

        $result = $service->transformProject(
            $organizationId,
            $projectId,
            null,
            (bool) $this->option('dry-run'),
            null,
            100,
            true,
        );
        $meta = $result['meta'];
        $this->info('Преобразование истории объёмов завершено.');
        $this->line('Обработано: '.$meta['returned']);
        $this->line('Записано каноническое количество: '.$meta['applied']);
        $this->line('Зафиксировано совпадение без правки полей: '.$meta['recorded']);
        $this->line('Уже обработано ранее: '.$meta['already_done']);
        $this->line('Оставлено для ручного решения: '.$meta['skipped_manual']);
        if ($meta['stop_on_discrepancy']) {
            $this->warn('Есть расхождения. Остановитесь и разберите их вручную. Не запускайте массовый повтор, пока сверка не станет чистой.');
        }

        return self::SUCCESS;
    }
}
