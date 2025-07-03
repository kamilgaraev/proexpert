<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;
use Illuminate\Support\Str;
use App\Services\OpenApi\DtoToSchemaConverter;
use App\Services\OpenApi\ResourceToSchemaConverter;

class OpenApiSyncCommand extends Command
{
    protected $signature = 'openapi:sync {scope=lk : Область (lk|admin|mobile)}';
    protected $description = 'Сгенерировать/обновить YAML-схемы компонентов OpenAPI на основе DTO/Resource';

    public function handle(): int
    {
        $scope = $this->argument('scope');
        $scope = strtolower($scope);

        if (!in_array($scope, ['lk', 'admin', 'mobile'], true)) {
            $this->error('Неизвестная область. Допустимые значения: lk, admin, mobile');
            return self::FAILURE;
        }

        // Папка назначения schemas
        $schemasDir = base_path("docs/openapi/{$scope}/components/schemas");
        File::ensureDirectoryExists($schemasDir);

        // Подменим отсутствующий пакет spatie/data-transfer-object на заглушку, чтобы reflection не падал
        if (!class_exists('Spatie\\DataTransferObject\\DataTransferObject')) {
            class_alias(\stdClass::class, 'Spatie\\DataTransferObject\\DataTransferObject');
        }

        $generated = 0;
        $converterR = new ResourceToSchemaConverter();
        $converterD = new DtoToSchemaConverter();

        // 1) Resources
        $resourceBase = app_path('Http/Resources/Api/V1');
        if ($scope === 'lk') {
            $resourceBase .= '/Landing';
        } elseif ($scope === 'admin') {
            $resourceBase .= '/Admin';
        } elseif ($scope === 'mobile') {
            $resourceBase .= '/Mobile';
        }

        if (File::isDirectory($resourceBase)) {
            foreach (File::allFiles($resourceBase) as $file) {
                if ($file->getExtension() !== 'php') continue;
                $class = $this->classFromFile($file->getRealPath());
                if (!$class || !class_exists($class)) continue;
                $schema = $converterR->convert($class);
                if (!$schema) continue;
                $this->dumpSchema($schemasDir, class_basename($class), $schema);
                $generated++;
            }
        }

        // 2) DTOs
        $dtoDirs = [app_path('DTOs'), app_path('DataTransferObjects')];
        foreach ($dtoDirs as $dtoDir) {
            if (!File::isDirectory($dtoDir)) continue;
            foreach (File::allFiles($dtoDir) as $file) {
                if ($file->getExtension() !== 'php') continue;
                $class = $this->classFromFile($file->getRealPath());
                if (!$class || !class_exists($class)) continue;
                $schema = $converterD->convert($class);
                if (!$schema) continue;
                $this->dumpSchema($schemasDir, class_basename($class), $schema);
                $generated++;
            }
        }

        $this->info("Сгенерировано/обновлено {$generated} схем. Путь: {$schemasDir}");
        return self::SUCCESS;
    }

    private function classFromFile(string $path): ?string
    {
        $rel = Str::after($path, app_path() . DIRECTORY_SEPARATOR);
        $rel = Str::replaceLast('.php', '', $rel);
        $parts = explode(DIRECTORY_SEPARATOR, $rel);
        $parts = array_map(fn($p) => str_replace(['/', '\\'], '', $p), $parts);
        return 'App\\' . implode('\\', $parts);
    }

    private function dumpSchema(string $dir, string $name, array $schema): void
    {
        $file = $dir . DIRECTORY_SEPARATOR . $name . '.yaml';
        File::put($file, Yaml::dump($schema, 4, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE));
    }
} 