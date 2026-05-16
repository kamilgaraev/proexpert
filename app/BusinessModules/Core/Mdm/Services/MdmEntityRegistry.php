<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Services;

use App\Models\Contractor;
use App\Models\CostCategory;
use App\Models\EstimatePositionCatalog;
use App\Models\EstimatePositionCatalogCategory;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Supplier;
use App\Models\WorkType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class MdmEntityRegistry
{
    public function all(): array
    {
        return [
            'contractor' => [
                'model' => Contractor::class,
                'title' => 'Контрагенты',
                'display_field' => 'name',
                'code_fields' => ['inn', 'kpp', 'email', 'phone'],
                'required_fields' => ['name'],
            ],
            'supplier' => [
                'model' => Supplier::class,
                'title' => 'Поставщики',
                'display_field' => 'name',
                'code_fields' => ['inn', 'tax_number', 'email', 'phone'],
                'required_fields' => ['name'],
            ],
            'material' => [
                'model' => Material::class,
                'title' => 'Материалы и складская номенклатура',
                'display_field' => 'name',
                'code_fields' => ['code', 'external_code', 'measurement_unit_id'],
                'required_fields' => ['name', 'measurement_unit_id'],
            ],
            'measurement_unit' => [
                'model' => MeasurementUnit::class,
                'title' => 'Единицы измерения',
                'display_field' => 'name',
                'code_fields' => ['short_name', 'type'],
                'required_fields' => ['name', 'short_name', 'type'],
            ],
            'work_type' => [
                'model' => WorkType::class,
                'title' => 'Виды работ',
                'display_field' => 'name',
                'code_fields' => ['code', 'measurement_unit_id'],
                'required_fields' => ['name', 'measurement_unit_id'],
            ],
            'cost_category' => [
                'model' => CostCategory::class,
                'title' => 'Категории затрат',
                'display_field' => 'name',
                'code_fields' => ['code', 'external_code', 'parent_id'],
                'required_fields' => ['name'],
            ],
            'estimate_position' => [
                'model' => EstimatePositionCatalog::class,
                'title' => 'Сметные позиции',
                'display_field' => 'name',
                'code_fields' => ['code', 'measurement_unit_id', 'work_type_id'],
                'required_fields' => ['name', 'code', 'measurement_unit_id'],
            ],
            'estimate_position_category' => [
                'model' => EstimatePositionCatalogCategory::class,
                'title' => 'Категории сметных позиций',
                'display_field' => 'name',
                'code_fields' => ['parent_id'],
                'required_fields' => ['name'],
            ],
        ];
    }

    public function get(string $entityType): array
    {
        $entities = $this->all();

        if (!array_key_exists($entityType, $entities)) {
            throw new InvalidArgumentException("Unsupported MDM entity type: {$entityType}");
        }

        return $entities[$entityType];
    }

    public function query(string $entityType, int $organizationId): Builder
    {
        $model = $this->get($entityType)['model'];

        return $model::query()->where('organization_id', $organizationId);
    }

    public function displayName(Model $model, string $entityType): ?string
    {
        $field = $this->get($entityType)['display_field'];
        $value = $model->getAttribute($field);

        return is_scalar($value) ? (string) $value : null;
    }

    public function inferEntityType(Model $model): ?string
    {
        foreach ($this->all() as $type => $config) {
            if ($model instanceof $config['model']) {
                return $type;
            }
        }

        return null;
    }
}
