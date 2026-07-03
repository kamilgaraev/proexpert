<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Demo\BrickHouseDemoScenarioService;
use Illuminate\Console\Command;

final class SeedDemoScenarioCommand extends Command
{
    protected $signature = 'demo:seed
        {scenario=brick-house : Демо-сценарий}
        {--reset : Удалить демо-контур и накатить заново}
        {--delete : Только удалить демо-контур}
        {--rollback : Алиас для --delete}
        {--dry-run : Показать, что будет удалено, без изменения данных}
        {--verify : Проверить демо-контур после операции}
        {--force : Разрешить удаление в production}';

    protected $description = 'Seed, refresh, verify or delete МОСТ demo scenario data';

    public function __construct(
        private readonly BrickHouseDemoScenarioService $scenarioService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $scenario = (string) $this->argument('scenario');
        if ($scenario !== BrickHouseDemoScenarioService::SCENARIO_SLUG) {
            $this->error(sprintf('Неизвестный демо-сценарий: %s', $scenario));
            $this->line('Доступный сценарий: brick-house');

            return self::FAILURE;
        }

        $deleteOnly = (bool) $this->option('delete') || (bool) $this->option('rollback');
        $reset = (bool) $this->option('reset');
        $dryRun = (bool) $this->option('dry-run');
        $verify = (bool) $this->option('verify');
        $destructive = ($deleteOnly || $reset) && !$dryRun;

        if ($deleteOnly && $reset) {
            $this->error('Нельзя одновременно использовать --delete/--rollback и --reset.');

            return self::FAILURE;
        }

        if ($destructive && app()->environment('production') && !$this->option('force')) {
            $this->error('Для удаления или сброса демо-контура в production нужен флаг --force.');

            return self::FAILURE;
        }

        if ($verify && !$reset && !$deleteOnly && !$dryRun) {
            return $this->renderVerifyResult($this->scenarioService->verify());
        }

        if ($reset || $deleteOnly || $dryRun) {
            $result = $this->scenarioService->delete($dryRun);
            $this->renderDeleteResult($result);

            if ($dryRun || $deleteOnly) {
                return self::SUCCESS;
            }
        }

        $seedResult = $this->scenarioService->seed();
        if ($seedResult['output'] !== '') {
            $this->line($seedResult['output']);
        }

        if ($verify || $reset) {
            return $this->renderVerifyResult($this->scenarioService->verify());
        }

        $this->info('Демо-сценарий brick-house докатан.');
        $this->line('Для проверки можно запустить: php artisan demo:seed brick-house --verify');

        return self::SUCCESS;
    }

    private function renderDeleteResult(array $result): void
    {
        $this->info($result['dry_run'] ? 'План удаления демо-контура:' : 'Демо-контур удален:');
        $this->line(sprintf(
            'Найдено: проектов %d, организаций %d, пользователей %d',
            $result['ids']['projects'],
            $result['ids']['organizations'],
            $result['ids']['users']
        ));

        if ($result['counts'] === []) {
            $this->line('Записей для удаления не найдено.');

            return;
        }

        $this->table(
            ['Таблица', $result['dry_run'] ? 'Будет удалено' : 'Удалено'],
            array_map(
                static fn (array $row): array => [$row['table'], (string) $row['deleted']],
                $result['counts']
            )
        );
    }

    private function renderVerifyResult(array $result): int
    {
        $this->table(
            ['Проверка', 'Факт', 'Ожидание', 'Статус'],
            array_map(
                static fn (array $check): array => [
                    $check['name'],
                    (string) $check['actual'],
                    (string) $check['expected'],
                    $check['ok'] ? 'OK' : 'FAIL',
                ],
                $result['checks']
            )
        );

        if (!$result['ok']) {
            $this->error('Проверка демо-контура не пройдена.');

            return self::FAILURE;
        }

        $this->info('Проверка демо-контура пройдена.');

        return self::SUCCESS;
    }
}
