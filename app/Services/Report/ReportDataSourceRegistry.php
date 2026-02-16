<?php

namespace App\Services\Report;

use Illuminate\Support\Arr;

class ReportDataSourceRegistry
{
    protected array $dataSources;
    protected array $categories;

    public function __construct()
    {
        $this->dataSources = config('custom-reports.data_sources', []);
        $this->categories = config('custom-reports.categories', []);
    }

    public function getAllDataSources(): array
    {
        return array_map(function ($source, $key) {
            return [
                'key' => $key,
                'label' => $source['label'],
                'category' => $source['category'],
                'table' => $source['table'],
            ];
        }, $this->dataSources, array_keys($this->dataSources));
    }

    public function getDataSource(string $key): ?array
    {
        return $this->dataSources[$key] ?? null;
    }

    public function getAvailableFields(string $dataSourceKey): array
    {
        $dataSource = $this->getDataSource($dataSourceKey);
        
        if (!$dataSource) {
            return [];
        }

        return array_map(function ($field, $key) use ($dataSourceKey) {
            return array_merge($field, [
                'key' => $key,
                'full_name' => "{$dataSourceKey}.{$key}",
            ]);
        }, $dataSource['fields'], array_keys($dataSource['fields']));
    }

    public function getAvailableRelations(string $dataSourceKey): array
    {
        $dataSource = $this->getDataSource($dataSourceKey);
        
        if (!$dataSource || !isset($dataSource['relations'])) {
            return [];
        }

        return array_map(function ($relation, $key) {
            return array_merge($relation, ['key' => $key]);
        }, $dataSource['relations'], array_keys($dataSource['relations']));
    }

    public function getFieldMetadata(string $dataSourceKey, string $fieldKey): ?array
    {
        $dataSource = $this->getDataSource($dataSourceKey);
        
        if (!$dataSource || !isset($dataSource['fields'][$fieldKey])) {
            return null;
        }

        return array_merge(
            $dataSource['fields'][$fieldKey],
            [
                'key' => $fieldKey,
                'full_name' => "{$dataSourceKey}.{$fieldKey}",
            ]
        );
    }

    public function isFieldAggregatable(string $dataSourceKey, string $fieldKey): bool
    {
        $metadata = $this->getFieldMetadata($dataSourceKey, $fieldKey);
        return $metadata && ($metadata['aggregatable'] ?? false);
    }

    public function isFieldFilterable(string $dataSourceKey, string $fieldKey): bool
    {
        $metadata = $this->getFieldMetadata($dataSourceKey, $fieldKey);
        return $metadata && ($metadata['filterable'] ?? false);
    }

    public function isFieldSortable(string $dataSourceKey, string $fieldKey): bool
    {
        $metadata = $this->getFieldMetadata($dataSourceKey, $fieldKey);
        return $metadata && ($metadata['sortable'] ?? false);
    }

    public function validateDataSource(string $dataSourceKey): bool
    {
        $source = $this->getDataSource($dataSourceKey);
        return $source && $this->validateDataSourceStructure($source);
    }

    protected function validateDataSourceStructure(array $source): bool
    {
        return isset($source['table']) && 
               isset($source['model']) && 
               isset($source['fields']) && 
               is_array($source['fields']) &&
               !empty($source['fields']);
    }

    public function validateRelation(string $fromSource, string $relationKey): bool
    {
        $dataSource = $this->getDataSource($fromSource);
        
        if (!$dataSource || !isset($dataSource['relations'][$relationKey])) {
            return false;
        }

        $relation = $dataSource['relations'][$relationKey];
        $targetSource = $relation['target'] ?? null;

        return $targetSource && $this->validateDataSource($targetSource);
    }

    public function getDefaultFilters(string $dataSourceKey): array
    {
        $dataSource = $this->getDataSource($dataSourceKey);
        return $dataSource['default_filters'] ?? [];
    }

    public function getTableName(string $dataSourceKey): ?string
    {
        $dataSource = $this->getDataSource($dataSourceKey);
        return $dataSource['table'] ?? null;
    }

    public function getModelClass(string $dataSourceKey): ?string
    {
        $dataSource = $this->getDataSource($dataSourceKey);
        return $dataSource['model'] ?? null;
    }

    public function getDataSourcesByCategory(string $category): array
    {
        return array_filter($this->dataSources, function ($source) use ($category) {
            return ($source['category'] ?? null) === $category;
        });
    }

    public function getAllCategories(): array
    {
        return $this->categories;
    }

    public function parseFieldName(string $fullFieldName): array
    {
        $parts = explode('.', $fullFieldName);
        
        if (count($parts) !== 2) {
            return ['source' => null, 'field' => $fullFieldName];
        }

        return [
            'source' => $parts[0],
            'field' => $parts[1],
        ];
    }

    public function validateField(string $dataSourceKey, string $fieldKey): bool
    {
        return $this->getFieldMetadata($dataSourceKey, $fieldKey) !== null;
    }

    public function getAggregatableFields(string $dataSourceKey): array
    {
        $fields = $this->getAvailableFields($dataSourceKey);
        
        return array_filter($fields, function ($field) {
            return $field['aggregatable'] ?? false;
        });
    }

    public function getFilterableFields(string $dataSourceKey): array
    {
        $fields = $this->getAvailableFields($dataSourceKey);
        
        return array_filter($fields, function ($field) {
            return $field['filterable'] ?? false;
        });
    }
}

