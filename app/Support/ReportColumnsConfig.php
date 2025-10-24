<?php

namespace App\Support;

class ReportColumnsConfig
{
    /**
     * Получить конфигурацию колонок для типа отчета.
     *
     * @param string $reportType
     * @return array
     */
    public static function get(string $reportType): array
    {
        $configPath = config_path("reports/{$reportType}.php");
        
        if (!file_exists($configPath)) {
            return [];
        }
        
        return require $configPath;
    }
    
    /**
     * Проверить, существует ли конфигурация для типа отчета.
     *
     * @param string $reportType
     * @return bool
     */
    public static function exists(string $reportType): bool
    {
        return file_exists(config_path("reports/{$reportType}.php"));
    }
    
    /**
     * Получить список всех доступных типов отчетов.
     *
     * @return array
     */
    public static function getAvailableReportTypes(): array
    {
        $reportsPath = config_path('reports');
        
        if (!is_dir($reportsPath)) {
            return [];
        }
        
        $files = glob($reportsPath . '/*.php');
        
        return array_map(function ($file) {
            return basename($file, '.php');
        }, $files);
    }
    
    /**
     * Получить список колонок с их метаданными для типа отчета.
     *
     * @param string $reportType
     * @return array
     */
    public static function getColumnsMetadata(string $reportType): array
    {
        return self::get($reportType);
    }
    
    /**
     * Получить только ключи колонок (data_key) для типа отчета.
     *
     * @param string $reportType
     * @return array
     */
    public static function getColumnKeys(string $reportType): array
    {
        $config = self::get($reportType);
        return array_keys($config);
    }
    
    /**
     * Получить только labels (человеко-читаемые названия) для типа отчета.
     *
     * @param string $reportType
     * @return array
     */
    public static function getColumnLabels(string $reportType): array
    {
        $config = self::get($reportType);
        return array_map(function ($column) {
            return $column['label'] ?? '';
        }, $config);
    }
}

