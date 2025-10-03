<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\CustomReport;
use App\Models\Organization;
use App\Models\User;

class CustomReportTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::first();
        $user = User::first();

        if (!$organization || !$user) {
            $this->command->warn('Организация или пользователь не найдены. Пропуск seeder.');
            return;
        }

        $templates = $this->getTemplates($organization->id, $user->id);

        foreach ($templates as $template) {
            CustomReport::updateOrCreate(
                [
                    'name' => $template['name'],
                    'organization_id' => $organization->id,
                ],
                $template
            );
        }

        $this->command->info('Созданы шаблоны отчетов: ' . count($templates));
    }

    protected function getTemplates(int $organizationId, int $userId): array
    {
        return [
            [
                'name' => 'Финансовый отчет по проектам',
                'description' => 'Суммы по контрактам и выполненным работам по проектам',
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'report_category' => 'finances',
                'data_sources' => [
                    'primary' => 'projects',
                    'joins' => [
                        [
                            'table' => 'contracts',
                            'type' => 'left',
                            'on' => ['projects.id', 'contracts.project_id'],
                        ],
                        [
                            'table' => 'completed_works',
                            'type' => 'left',
                            'on' => ['projects.id', 'completed_works.project_id'],
                        ],
                    ],
                ],
                'columns_config' => [
                    ['field' => 'projects.name', 'label' => 'Проект', 'order' => 1, 'format' => 'text'],
                    ['field' => 'projects.budget_amount', 'label' => 'Бюджет', 'order' => 2, 'format' => 'currency'],
                    ['field' => 'contracts.total_amount', 'label' => 'Сумма контрактов', 'order' => 3, 'format' => 'currency', 'aggregation' => 'sum'],
                    ['field' => 'completed_works.total_cost', 'label' => 'Стоимость работ', 'order' => 4, 'format' => 'currency', 'aggregation' => 'sum'],
                ],
                'filters_config' => [
                    ['field' => 'projects.status', 'label' => 'Статус проекта', 'type' => 'select', 'required' => false],
                    ['field' => 'projects.start_date', 'label' => 'Период', 'type' => 'date_range', 'required' => true],
                ],
                'aggregations_config' => [
                    'group_by' => ['projects.id', 'projects.name', 'projects.budget_amount'],
                    'aggregations' => [
                        ['field' => 'contracts.total_amount', 'function' => 'sum', 'alias' => 'total_contracts'],
                        ['field' => 'completed_works.total_cost', 'function' => 'sum', 'alias' => 'total_works'],
                    ],
                ],
                'sorting_config' => [
                    ['field' => 'total_contracts', 'direction' => 'desc'],
                ],
                'is_shared' => true,
            ],
            
            [
                'name' => 'Использование материалов по проектам',
                'description' => 'Приход и расход материалов по проектам',
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'report_category' => 'materials',
                'data_sources' => [
                    'primary' => 'materials',
                    'joins' => [
                        [
                            'table' => 'material_receipts',
                            'type' => 'left',
                            'on' => ['materials.id', 'material_receipts.material_id'],
                        ],
                        [
                            'table' => 'projects',
                            'type' => 'inner',
                            'on' => ['material_receipts.project_id', 'projects.id'],
                        ],
                    ],
                ],
                'columns_config' => [
                    ['field' => 'materials.name', 'label' => 'Материал', 'order' => 1, 'format' => 'text'],
                    ['field' => 'projects.name', 'label' => 'Проект', 'order' => 2, 'format' => 'text'],
                    ['field' => 'material_receipts.quantity', 'label' => 'Количество', 'order' => 3, 'format' => 'number', 'aggregation' => 'sum'],
                    ['field' => 'material_receipts.total_price', 'label' => 'Стоимость', 'order' => 4, 'format' => 'currency', 'aggregation' => 'sum'],
                ],
                'filters_config' => [
                    ['field' => 'material_receipts.receipt_date', 'label' => 'Период', 'type' => 'date_range', 'required' => true],
                    ['field' => 'materials.category', 'label' => 'Категория материала', 'type' => 'text', 'required' => false],
                ],
                'aggregations_config' => [
                    'group_by' => ['materials.id', 'materials.name', 'projects.id', 'projects.name'],
                    'aggregations' => [
                        ['field' => 'material_receipts.quantity', 'function' => 'sum', 'alias' => 'total_quantity'],
                        ['field' => 'material_receipts.total_price', 'function' => 'sum', 'alias' => 'total_cost'],
                    ],
                ],
                'sorting_config' => [
                    ['field' => 'total_cost', 'direction' => 'desc'],
                ],
                'is_shared' => true,
            ],
            
            [
                'name' => 'Активность прорабов',
                'description' => 'Рабочее время и выполненные работы по прорабам',
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'report_category' => 'staff',
                'data_sources' => [
                    'primary' => 'users',
                    'joins' => [
                        [
                            'table' => 'time_entries',
                            'type' => 'left',
                            'on' => ['users.id', 'time_entries.user_id'],
                        ],
                        [
                            'table' => 'completed_works',
                            'type' => 'left',
                            'on' => ['users.id', 'completed_works.user_id'],
                        ],
                    ],
                ],
                'columns_config' => [
                    ['field' => 'users.name', 'label' => 'Прораб', 'order' => 1, 'format' => 'text'],
                    ['field' => 'time_entries.hours', 'label' => 'Отработано часов', 'order' => 2, 'format' => 'number', 'aggregation' => 'sum'],
                    ['field' => 'completed_works.id', 'label' => 'Количество работ', 'order' => 3, 'format' => 'number', 'aggregation' => 'count'],
                    ['field' => 'completed_works.total_cost', 'label' => 'Стоимость работ', 'order' => 4, 'format' => 'currency', 'aggregation' => 'sum'],
                ],
                'filters_config' => [
                    ['field' => 'time_entries.date', 'label' => 'Период', 'type' => 'date_range', 'required' => true],
                ],
                'aggregations_config' => [
                    'group_by' => ['users.id', 'users.name'],
                    'aggregations' => [
                        ['field' => 'time_entries.hours', 'function' => 'sum', 'alias' => 'total_hours'],
                        ['field' => 'completed_works.id', 'function' => 'count', 'alias' => 'works_count'],
                        ['field' => 'completed_works.total_cost', 'function' => 'sum', 'alias' => 'total_revenue'],
                    ],
                ],
                'sorting_config' => [
                    ['field' => 'total_revenue', 'direction' => 'desc'],
                ],
                'is_shared' => true,
            ],
        ];
    }
}

