<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services\Export;

use App\BusinessModules\Features\BasicWarehouse\Services\Export\Contracts\WarehouseExportStrategyInterface;
use App\Services\Storage\FileService;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Менеджер управления экспортом складских документов
 */
class WarehouseExportManager
{
    /** @var Collection<string, WarehouseExportStrategyInterface> */
    protected Collection $strategies;

    public function __construct(protected FileService $fileService)
    {
        $this->strategies = collect();
        $this->registerDefaultStrategies();
    }

    /**
     * Регистрация базовых стратегий
     */
    protected function registerDefaultStrategies(): void
    {
        $strategyClasses = [
            Forms\M4\M4ExportStrategy::class,
            Forms\M11\M11ExportStrategy::class,
            Forms\M15\M15ExportStrategy::class,
            Forms\INV3\INV3ExportStrategy::class,
            Forms\M7\M7ExportStrategy::class,
            Forms\M17\M17ExportStrategy::class,
            Forms\M8\M8ExportStrategy::class,
        ];

        foreach ($strategyClasses as $class) {
            if (class_exists($class)) {
                $strategy = app($class);
                $this->registerStrategy($strategy);
            }
        }
    }

    /**
     * Регистрация стратегии
     */
    public function registerStrategy(WarehouseExportStrategyInterface $strategy): void
    {
        $this->strategies->put($strategy->getSupportedType(), $strategy);
    }

    /**
     * Экспорт документа
     */
    public function export(string $type, $model): string
    {
        $strategy = $this->strategies->get($type);

        if (!$strategy) {
            throw new InvalidArgumentException("Стратегия для типа документа '{$type}' не найдена.");
        }

        return $strategy->export($model);
    }

    /**
     * Получение временной ссылки
     */
    public function getTemporaryUrl(string $path, int $minutes = 15): string
    {
        return $this->fileService->temporaryUrl($path, $minutes);
    }

    /**
     * Список доступных типов для экспорта
     */
    public function getAvailableTypes(): array
    {
        return $this->strategies->keys()->toArray();
    }
}
