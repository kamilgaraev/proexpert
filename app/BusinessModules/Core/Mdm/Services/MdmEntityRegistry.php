<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Services;

use App\BusinessModules\Features\Budgeting\Models\BudgetArticle;
use App\BusinessModules\Features\Budgeting\Models\ResponsibilityCenter;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\CostCategory;
use App\Models\EstimatePositionCatalog;
use App\Models\EstimatePositionCatalogCategory;
use App\Models\Material;
use App\Models\MeasurementUnit;
use App\Models\Project;
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
                'editable_fields' => [
                    'name',
                    'contact_person',
                    'phone',
                    'email',
                    'legal_address',
                    'inn',
                    'kpp',
                    'bank_details',
                    'notes',
                ],
                'field_labels' => [
                    'name' => 'Наименование',
                    'contact_person' => 'Контактное лицо',
                    'phone' => 'Телефон',
                    'email' => 'Email',
                    'legal_address' => 'Юридический адрес',
                    'inn' => 'ИНН',
                    'kpp' => 'КПП',
                    'bank_details' => 'Банковские реквизиты',
                    'notes' => 'Примечание',
                ],
            ],
            'supplier' => [
                'model' => Supplier::class,
                'title' => 'Поставщики',
                'display_field' => 'name',
                'code_fields' => ['inn', 'tax_number', 'email', 'phone'],
                'required_fields' => ['name'],
                'editable_fields' => [
                    'name',
                    'code',
                    'inn',
                    'ogrn',
                    'contact_person',
                    'phone',
                    'email',
                    'address',
                    'tax_number',
                    'description',
                    'is_active',
                ],
                'field_labels' => [
                    'name' => 'Наименование',
                    'code' => 'Код',
                    'inn' => 'ИНН',
                    'ogrn' => 'ОГРН',
                    'contact_person' => 'Контактное лицо',
                    'phone' => 'Телефон',
                    'email' => 'Email',
                    'address' => 'Адрес',
                    'tax_number' => 'Налоговый номер',
                    'description' => 'Описание',
                    'is_active' => 'Активен',
                ],
            ],
            'material' => [
                'model' => Material::class,
                'title' => 'Материалы и складская номенклатура',
                'display_field' => 'name',
                'code_fields' => ['code', 'external_code', 'measurement_unit_id'],
                'required_fields' => ['name', 'measurement_unit_id'],
                'editable_fields' => [
                    'name',
                    'code',
                    'measurement_unit_id',
                    'description',
                    'category',
                    'default_price',
                    'is_active',
                    'external_code',
                    'accounting_account',
                    'use_in_accounting_reports',
                ],
                'reference_fields' => [
                    'measurement_unit_id' => 'measurement_unit',
                ],
                'field_labels' => [
                    'name' => 'Наименование',
                    'code' => 'Код',
                    'measurement_unit_id' => 'Единица измерения',
                    'description' => 'Описание',
                    'category' => 'Категория',
                    'default_price' => 'Цена по умолчанию',
                    'is_active' => 'Активен',
                    'external_code' => 'Внешний код',
                    'accounting_account' => 'Счет учета',
                    'use_in_accounting_reports' => 'Использовать в отчетах',
                ],
            ],
            'measurement_unit' => [
                'model' => MeasurementUnit::class,
                'title' => 'Единицы измерения',
                'display_field' => 'name',
                'code_fields' => ['short_name', 'type'],
                'required_fields' => ['name', 'short_name', 'type'],
                'editable_fields' => [
                    'name',
                    'short_name',
                    'type',
                    'description',
                    'is_default',
                ],
                'field_labels' => [
                    'name' => 'Наименование',
                    'short_name' => 'Краткое обозначение',
                    'type' => 'Тип',
                    'description' => 'Описание',
                    'is_default' => 'По умолчанию',
                ],
            ],
            'work_type' => [
                'model' => WorkType::class,
                'title' => 'Виды работ',
                'display_field' => 'name',
                'code_fields' => ['code', 'measurement_unit_id'],
                'required_fields' => ['name', 'measurement_unit_id'],
                'editable_fields' => [
                    'name',
                    'code',
                    'measurement_unit_id',
                    'description',
                    'category',
                    'default_price',
                    'is_active',
                ],
                'reference_fields' => [
                    'measurement_unit_id' => 'measurement_unit',
                ],
                'field_labels' => [
                    'name' => 'Наименование',
                    'code' => 'Код',
                    'measurement_unit_id' => 'Единица измерения',
                    'description' => 'Описание',
                    'category' => 'Категория',
                    'default_price' => 'Цена по умолчанию',
                    'is_active' => 'Активен',
                ],
            ],
            'cost_category' => [
                'model' => CostCategory::class,
                'title' => 'Категории затрат',
                'display_field' => 'name',
                'code_fields' => ['code', 'external_code', 'parent_id'],
                'required_fields' => ['name'],
                'editable_fields' => [
                    'name',
                    'code',
                    'external_code',
                    'description',
                    'parent_id',
                    'is_active',
                    'sort_order',
                ],
                'reference_fields' => [
                    'parent_id' => 'cost_category',
                ],
                'field_labels' => [
                    'name' => 'Наименование',
                    'code' => 'Код',
                    'external_code' => 'Внешний код',
                    'description' => 'Описание',
                    'parent_id' => 'Родительская категория',
                    'is_active' => 'Активна',
                    'sort_order' => 'Порядок сортировки',
                ],
            ],
            'estimate_position' => [
                'model' => EstimatePositionCatalog::class,
                'title' => 'Сметные позиции',
                'display_field' => 'name',
                'code_fields' => ['code', 'measurement_unit_id', 'work_type_id'],
                'required_fields' => ['name', 'code', 'measurement_unit_id'],
                'editable_fields' => [
                    'category_id',
                    'name',
                    'code',
                    'description',
                    'item_type',
                    'measurement_unit_id',
                    'work_type_id',
                    'unit_price',
                    'direct_costs',
                    'overhead_percent',
                    'profit_percent',
                    'is_active',
                ],
                'reference_fields' => [
                    'category_id' => 'estimate_position_category',
                    'measurement_unit_id' => 'measurement_unit',
                    'work_type_id' => 'work_type',
                ],
                'field_labels' => [
                    'category_id' => 'Категория',
                    'name' => 'Наименование',
                    'code' => 'Код',
                    'description' => 'Описание',
                    'item_type' => 'Тип позиции',
                    'measurement_unit_id' => 'Единица измерения',
                    'work_type_id' => 'Вид работ',
                    'unit_price' => 'Цена',
                    'direct_costs' => 'Прямые затраты',
                    'overhead_percent' => 'Накладные расходы',
                    'profit_percent' => 'Сметная прибыль',
                    'is_active' => 'Активна',
                ],
            ],
            'estimate_position_category' => [
                'model' => EstimatePositionCatalogCategory::class,
                'title' => 'Категории сметных позиций',
                'display_field' => 'name',
                'code_fields' => ['parent_id'],
                'required_fields' => ['name'],
                'editable_fields' => [
                    'parent_id',
                    'name',
                    'description',
                    'sort_order',
                    'is_active',
                ],
                'reference_fields' => [
                    'parent_id' => 'estimate_position_category',
                ],
                'field_labels' => [
                    'parent_id' => 'Родительская категория',
                    'name' => 'Наименование',
                    'description' => 'Описание',
                    'sort_order' => 'Порядок сортировки',
                    'is_active' => 'Активна',
                ],
            ],
            'budget_article' => [
                'model' => BudgetArticle::class,
                'title' => 'Статьи бюджета',
                'display_field' => 'name',
                'code_fields' => ['code', 'budget_kind', 'flow_direction', 'parent_id'],
                'required_fields' => ['name', 'code', 'budget_kind', 'flow_direction'],
                'editable_fields' => [
                    'parent_id',
                    'code',
                    'name',
                    'budget_kind',
                    'flow_direction',
                    'is_leaf',
                    'is_active',
                    'cost_category_id',
                ],
                'reference_fields' => [
                    'parent_id' => 'budget_article',
                    'cost_category_id' => 'cost_category',
                ],
                'field_labels' => [
                    'parent_id' => 'Родительская статья',
                    'code' => 'Код',
                    'name' => 'Наименование',
                    'budget_kind' => 'Тип бюджета',
                    'flow_direction' => 'Направление движения',
                    'is_leaf' => 'Конечная статья',
                    'is_active' => 'Активна',
                    'cost_category_id' => 'Категория затрат',
                ],
            ],
            'responsibility_center' => [
                'model' => ResponsibilityCenter::class,
                'title' => 'ЦФО',
                'display_field' => 'name',
                'code_fields' => ['code', 'center_type', 'parent_id'],
                'required_fields' => ['name', 'code', 'center_type'],
                'editable_fields' => [
                    'parent_id',
                    'center_type',
                    'code',
                    'name',
                    'active_from',
                    'active_to',
                    'is_active',
                ],
                'reference_fields' => [
                    'parent_id' => 'responsibility_center',
                ],
                'field_labels' => [
                    'parent_id' => 'Родительский ЦФО',
                    'center_type' => 'Тип ЦФО',
                    'code' => 'Код',
                    'name' => 'Наименование',
                    'active_from' => 'Действует с',
                    'active_to' => 'Действует до',
                    'is_active' => 'Активен',
                ],
            ],
            'project' => [
                'model' => Project::class,
                'title' => 'Проекты',
                'display_field' => 'name',
                'code_fields' => ['external_code', 'contract_number'],
                'required_fields' => ['name'],
                'editable_fields' => [
                    'name',
                    'external_code',
                    'description',
                    'address',
                    'customer',
                    'designer',
                    'customer_organization',
                    'customer_representative',
                    'contract_number',
                    'contract_date',
                    'cost_category_id',
                    'use_in_accounting_reports',
                ],
                'reference_fields' => [
                    'cost_category_id' => 'cost_category',
                ],
                'supports_create' => false,
                'field_labels' => [
                    'name' => 'Наименование',
                    'external_code' => 'Внешний код',
                    'description' => 'Описание',
                    'address' => 'Адрес',
                    'customer' => 'Заказчик',
                    'designer' => 'Проектировщик',
                    'customer_organization' => 'Организация заказчика',
                    'customer_representative' => 'Представитель заказчика',
                    'contract_number' => 'Номер договора',
                    'contract_date' => 'Дата договора',
                    'cost_category_id' => 'Категория затрат',
                    'use_in_accounting_reports' => 'Использовать в отчетах',
                ],
            ],
            'contract' => [
                'model' => Contract::class,
                'title' => 'Договоры',
                'display_field' => 'number',
                'code_fields' => ['number', 'date', 'project_id'],
                'required_fields' => ['number'],
                'editable_fields' => [
                    'number',
                    'date',
                    'subject',
                    'payment_terms',
                    'start_date',
                    'end_date',
                    'notes',
                ],
                'supports_create' => false,
                'field_labels' => [
                    'number' => 'Номер',
                    'date' => 'Дата',
                    'subject' => 'Предмет',
                    'payment_terms' => 'Условия оплаты',
                    'start_date' => 'Дата начала',
                    'end_date' => 'Дата окончания',
                    'notes' => 'Примечание',
                ],
            ],
        ];
    }

    public function get(string $entityType): array
    {
        $entities = $this->all();

        if (! array_key_exists($entityType, $entities)) {
            throw new InvalidArgumentException("Unsupported MDM entity type: {$entityType}");
        }

        return $entities[$entityType];
    }

    public function publicDefinitions(?MdmEntityGovernanceRegistry $governanceRegistry = null): array
    {
        return collect($this->all())
            ->map(function (array $definition, string $entityType) use ($governanceRegistry): array {
                unset($definition['model']);

                if ($governanceRegistry instanceof MdmEntityGovernanceRegistry && $governanceRegistry->has($entityType)) {
                    $definition['governance'] = $governanceRegistry->publicPolicy($entityType);
                    $definition['supported_by_change_requests'] = true;
                } else {
                    $definition['supported_by_change_requests'] = false;
                }

                return $definition;
            })
            ->all();
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

    public function editableFields(string $entityType): array
    {
        return $this->get($entityType)['editable_fields'] ?? [];
    }

    public function fieldLabels(string $entityType): array
    {
        return $this->get($entityType)['field_labels'] ?? [];
    }

    public function referenceFields(string $entityType): array
    {
        return $this->get($entityType)['reference_fields'] ?? [];
    }

    public function supportsCreate(string $entityType): bool
    {
        return (bool) ($this->get($entityType)['supports_create'] ?? true);
    }

    public function sanitizeValues(string $entityType, array $values): array
    {
        $allowed = array_flip($this->editableFields($entityType));

        return array_intersect_key($values, $allowed);
    }

    public function unsupportedFields(string $entityType, array $values): array
    {
        $allowed = array_flip($this->editableFields($entityType));

        return array_values(array_diff(array_keys($values), array_keys($allowed)));
    }

    public function fieldLabel(string $entityType, string $field): string
    {
        $systemLabels = [
            'organization_id' => 'Организация',
            'source_organization_id' => 'Источник организации',
            'created_by' => 'Автор создания',
            'updated_by' => 'Автор изменения',
            'created_by_user_id' => 'Автор создания',
            'requested_by_user_id' => 'Автор заявки',
            'reviewed_by_user_id' => 'Автор решения',
            'status' => 'Статус',
            'deleted_at' => 'Дата удаления',
        ];

        return (string) ($this->fieldLabels($entityType)[$field] ?? $systemLabels[$field] ?? $field);
    }
}
