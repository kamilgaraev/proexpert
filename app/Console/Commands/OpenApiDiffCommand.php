<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\OpenApi\OpenApiDiffService;

class OpenApiDiffCommand extends Command
{
    protected $signature = 'openapi:diff';
    protected $description = 'Сравнить маршруты Laravel с документацией OpenAPI';

    public function handle(): int
    {
        $service = new OpenApiDiffService();
        $diff = $service->diff();
        $undocumented = $diff['undocumented'];
        $obsolete = $diff['obsolete'];
        if ($undocumented) {
            $this->error('Маршруты без документации:');
            foreach ($undocumented as $route) {
                $this->line($route);
            }
        }
        if ($obsolete) {
            $this->error('Документация без маршрута:');
            foreach ($obsolete as $route) {
                $this->line($route);
            }
        }
        if (!$undocumented && !$obsolete) {
            $this->info('Все маршруты покрыты документацией.');
            return self::SUCCESS;
        }
        return self::FAILURE;
    }
} 