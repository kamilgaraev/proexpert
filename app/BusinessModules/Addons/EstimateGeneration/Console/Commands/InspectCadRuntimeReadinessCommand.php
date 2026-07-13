<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Console\Commands;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\CadRuntimeConfiguration;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Geometry\CadRuntimeReadinessInspector;
use Illuminate\Console\Command;

final class InspectCadRuntimeReadinessCommand extends Command
{
    protected $signature = 'estimate-generation:cad-readiness {--json : Вывести машинно-читаемый результат}';

    protected $description = 'Проверяет готовность изолированной обработки CAD без доступа к пользовательским файлам.';

    public function __construct(
        private readonly CadRuntimeReadinessInspector $inspector,
        private readonly CadRuntimeConfiguration $configuration,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $errors = $this->inspector->inspect($this->configuration);
        $result = ['ready' => $errors === [], 'checks' => $errors];
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } elseif ($errors === []) {
            $this->info('Среда обработки CAD готова.');
        } else {
            $this->error('Среда обработки CAD не готова: '.implode(', ', $errors));
        }

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }
}
