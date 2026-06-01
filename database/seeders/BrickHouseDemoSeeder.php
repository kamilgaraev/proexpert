<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Enums\Contract\ContractSideTypeEnum;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\ContractWorkTypeCategoryEnum;
use App\Enums\OrganizationCapability;
use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use App\Models\Estimate;
use App\Models\Material;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class BrickHouseDemoSeeder extends Seeder
{
    private const PASSWORD = 'ProhelperDemo123!';

    private Carbon $now;

    /**
     * @var array<string, int>
     */
    private array $totals = [
        'accounts' => 0,
        'projects' => 0,
        'contracts' => 0,
        'estimates' => 0,
        'estimate_items' => 0,
        'schedule_tasks' => 0,
        'warehouse_movements' => 0,
        'site_requests' => 0,
        'journal_entries' => 0,
        'completed_works' => 0,
        'performance_acts' => 0,
        'payment_documents' => 0,
        'activity_events' => 0,
    ];

    public function run(): void
    {
        $this->now = now();
        $this->assertRequiredTables();

        $result = DB::transaction(function (): array {
            $general = $this->seedAccount($this->generalContractorAccount());
            $contractor = $this->seedAccount($this->contractorAccount());

            foreach ([$general, $contractor] as $account) {
                $this->activateModules($account['organization_id']);
                $this->seedSiteRequestStatuses($account['organization_id']);
            }

            $generalUnits = $this->seedMeasurementUnits($general['organization_id']);
            $contractorUnits = $this->seedMeasurementUnits($contractor['organization_id']);

            $generalMaterials = $this->seedMaterials($general['organization_id'], $generalUnits, 'GP');
            $contractorMaterials = $this->seedMaterials($contractor['organization_id'], $contractorUnits, 'SUB');

            $generalWorkTypes = $this->seedWorkTypes($general['organization_id'], $generalUnits, 'GP');
            $contractorWorkTypes = $this->seedWorkTypes($contractor['organization_id'], $contractorUnits, 'SUB');

            $projectId = $this->seedProject($general);
            $this->seedProjectMembership($projectId, $general, $contractor);

            $contractors = $this->seedContractorCards($general, $contractor);
            $contracts = $this->seedContracts($projectId, $general, $contractor, $contractors);

            $generalEstimate = $this->seedEstimate(
                organizationId: $general['organization_id'],
                projectId: $projectId,
                contractId: $contracts['general_contract_id'],
                userId: $general['user_id'],
                units: $generalUnits,
                workTypes: $generalWorkTypes,
                materials: $generalMaterials,
                number: 'КД-СМ-2026-001',
                name: 'Смета генподряда на строительство кирпичного дома',
                sections: $this->generalEstimateSections()
            );

            $contractorEstimate = $this->seedEstimate(
                organizationId: $contractor['organization_id'],
                projectId: $projectId,
                contractId: $contracts['contractor_contract_id'],
                userId: $contractor['user_id'],
                units: $contractorUnits,
                workTypes: $contractorWorkTypes,
                materials: $contractorMaterials,
                number: 'КД-ПДР-2026-014',
                name: 'Смета подрядчика на кирпичную кладку и армопояса',
                sections: $this->contractorEstimateSections()
            );

            $generalScheduleTasks = $this->seedSchedule(
                organizationId: $general['organization_id'],
                projectId: $projectId,
                userId: $general['user_id'],
                units: $generalUnits,
                workTypes: $generalWorkTypes,
                materials: $generalMaterials,
                scope: 'GP'
            );

            $contractorScheduleTasks = $this->seedSchedule(
                organizationId: $contractor['organization_id'],
                projectId: $projectId,
                userId: $contractor['user_id'],
                units: $contractorUnits,
                workTypes: $contractorWorkTypes,
                materials: $contractorMaterials,
                scope: 'SUB'
            );

            $generalWarehouse = $this->seedWarehouse(
                organizationId: $general['organization_id'],
                userId: $general['user_id'],
                projectId: $projectId,
                materials: $generalMaterials,
                scope: 'GP'
            );

            $contractorWarehouse = $this->seedWarehouse(
                organizationId: $contractor['organization_id'],
                userId: $contractor['user_id'],
                projectId: $projectId,
                materials: $contractorMaterials,
                scope: 'SUB'
            );

            $generalRequests = $this->seedSiteRequests(
                organizationId: $general['organization_id'],
                projectId: $projectId,
                userId: $general['user_id'],
                assignedUserId: $general['user_id'],
                materials: $generalMaterials,
                estimateItems: $generalEstimate['items'],
                scope: 'GP'
            );

            $contractorRequests = $this->seedSiteRequests(
                organizationId: $contractor['organization_id'],
                projectId: $projectId,
                userId: $contractor['user_id'],
                assignedUserId: $contractor['user_id'],
                materials: $contractorMaterials,
                estimateItems: $contractorEstimate['items'],
                scope: 'SUB'
            );

            $this->seedProjectMaterialDeliveries(
                organizationId: $general['organization_id'],
                projectId: $projectId,
                userId: $general['user_id'],
                warehouse: $generalWarehouse,
                siteRequests: $generalRequests,
                materials: $generalMaterials,
                scope: 'GP'
            );

            $this->seedProjectMaterialDeliveries(
                organizationId: $contractor['organization_id'],
                projectId: $projectId,
                userId: $contractor['user_id'],
                warehouse: $contractorWarehouse,
                siteRequests: $contractorRequests,
                materials: $contractorMaterials,
                scope: 'SUB'
            );

            $generalJournal = $this->seedConstructionJournal(
                organizationId: $general['organization_id'],
                projectId: $projectId,
                contractId: $contracts['general_contract_id'],
                contractorId: $contractors['contractor_in_general_id'],
                userId: $general['user_id'],
                scheduleTasks: $generalScheduleTasks,
                estimate: $generalEstimate,
                units: $generalUnits,
                workTypes: $generalWorkTypes,
                materials: $generalMaterials,
                scope: 'GP'
            );

            $contractorJournal = $this->seedConstructionJournal(
                organizationId: $contractor['organization_id'],
                projectId: $projectId,
                contractId: $contracts['contractor_contract_id'],
                contractorId: $contractors['general_in_contractor_id'],
                userId: $contractor['user_id'],
                scheduleTasks: $contractorScheduleTasks,
                estimate: $contractorEstimate,
                units: $contractorUnits,
                workTypes: $contractorWorkTypes,
                materials: $contractorMaterials,
                scope: 'SUB'
            );

            $acts = [
                'general' => $this->seedPerformanceAct(
                    contractId: $contracts['general_contract_id'],
                    projectId: $projectId,
                    userId: $general['user_id'],
                    completedWorks: $generalJournal['completed_works'],
                    number: 'КС-2-КД-ГП-01',
                    description: 'Акт приемки выполненных работ по фундаменту и кирпичной кладке первого этажа'
                ),
                'contractor' => $this->seedPerformanceAct(
                    contractId: $contracts['contractor_contract_id'],
                    projectId: $projectId,
                    userId: $contractor['user_id'],
                    completedWorks: $contractorJournal['completed_works'],
                    number: 'КС-2-КД-ПДР-01',
                    description: 'Акт подрядчика по кладке наружных и внутренних стен первого этажа'
                ),
            ];

            $payments = $this->seedPaymentDocuments(
                projectId: $projectId,
                general: $general,
                contractor: $contractor,
                contracts: $contracts,
                contractors: $contractors,
                estimates: [
                    'general' => $generalEstimate['id'],
                    'contractor' => $contractorEstimate['id'],
                ],
                acts: $acts,
                siteRequests: [
                    'general' => $generalRequests,
                    'contractor' => $contractorRequests,
                ]
            );

            $this->seedActivityEvents(
                projectId: $projectId,
                general: $general,
                contractor: $contractor,
                contracts: $contracts,
                estimates: [
                    'general' => $generalEstimate['id'],
                    'contractor' => $contractorEstimate['id'],
                ],
                payments: $payments
            );

            return [
                'project_id' => $projectId,
                'accounts' => [
                    [
                        'Контур' => 'Генподряд',
                        'Email' => $general['email'],
                        'Пароль' => self::PASSWORD,
                        'Организация' => $general['organization_name'],
                    ],
                    [
                        'Контур' => 'Подряд',
                        'Email' => $contractor['email'],
                        'Пароль' => self::PASSWORD,
                        'Организация' => $contractor['organization_name'],
                    ],
                ],
                'totals' => $this->totals,
            ];
        });

        $this->command?->newLine();
        $this->command?->info('Демо кирпичного дома создано или обновлено.');
        $this->command?->table(['Контур', 'Email', 'Пароль', 'Организация'], $result['accounts']);
        $this->command?->newLine();
        $this->command?->info(sprintf('ID проекта: %d', $result['project_id']));
        $this->command?->table(
            ['Данные', 'Количество'],
            array_map(
                static fn (string $key, int $value): array => [$key, (string) $value],
                array_keys($result['totals']),
                $result['totals']
            )
        );
    }

    private function assertRequiredTables(): void
    {
        $requiredTables = [
            'organizations',
            'users',
            'organization_user',
            'modules',
            'organization_module_activations',
            'projects',
            'project_organization',
            'project_user',
            'contractors',
            'contracts',
            'measurement_units',
            'materials',
            'work_types',
            'estimates',
            'estimate_sections',
            'estimate_items',
            'contract_estimate_items',
            'project_schedules',
            'schedule_tasks',
            'task_dependencies',
            'task_resources',
            'task_milestones',
            'organization_warehouses',
            'warehouse_balances',
            'warehouse_movements',
            'warehouse_project_allocations',
            'asset_reservations',
            'project_material_deliveries',
            'project_material_delivery_events',
            'site_request_statuses',
            'site_request_status_transitions',
            'site_requests',
            'site_request_history',
            'site_request_calendar_events',
            'construction_journals',
            'construction_journal_entries',
            'journal_work_volumes',
            'journal_workers',
            'journal_equipment',
            'journal_materials',
            'completed_works',
            'completed_work_materials',
            'contract_performance_acts',
            'performance_act_completed_works',
            'performance_act_lines',
            'payment_documents',
            'payment_document_site_requests',
            'activity_events',
        ];

        $missingTables = array_values(array_filter(
            $requiredTables,
            static fn (string $table): bool => !Schema::hasTable($table)
        ));

        if ($missingTables !== []) {
            throw new RuntimeException(
                'BrickHouseDemoSeeder требует подготовленную базу проекта. Не найдены таблицы: '
                . implode(', ', $missingTables)
            );
        }
    }

    /**
     * @param array<string, mixed> $account
     * @return array<string, mixed>
     */
    private function seedAccount(array $account): array
    {
        $organizationId = $this->upsert('organizations', [
            'tax_number' => $account['tax_number'],
        ], [
            'name' => $account['organization_name'],
            'legal_name' => $account['legal_name'],
            'tax_number' => $account['tax_number'],
            'registration_number' => $account['registration_number'],
            'phone' => $account['phone'],
            'email' => $account['organization_email'],
            'address' => $account['address'],
            'city' => 'Москва',
            'postal_code' => $account['postal_code'],
            'country' => 'RU',
            'description' => $account['description'],
            'is_active' => true,
            'subscription_expires_at' => $this->now->copy()->addYear(),
            'is_verified' => true,
            'verified_at' => $this->now,
            'verification_status' => 'verified',
            'verification_data' => $this->json([
                'source' => 'brick_house_demo_seeder',
                'scenario' => 'brick_house',
            ]),
            'organization_type' => 'single',
            'is_holding' => false,
            'hierarchy_level' => 0,
            'capabilities' => $this->json($account['capabilities']),
            'primary_business_type' => $account['primary_business_type'],
            'specializations' => $this->json($account['specializations']),
            'certifications' => $this->json($account['certifications']),
            'profile_completeness' => 100,
            'onboarding_completed' => true,
            'onboarding_completed_at' => $this->now,
            'is_onboarding_demo' => true,
        ]);

        $userId = $this->upsert('users', [
            'email' => $account['email'],
        ], [
            'name' => $account['name'],
            'email' => $account['email'],
            'email_verified_at' => $this->now,
            'password' => Hash::make(self::PASSWORD),
            'phone' => $account['user_phone'],
            'position' => $account['position'],
            'avatar_path' => null,
            'is_active' => true,
            'current_organization_id' => $organizationId,
            'settings' => $this->json([
                'demo_account' => true,
                'scenario' => 'brick_house',
                'role' => $account['contour'],
            ]),
            'has_completed_onboarding' => true,
        ]);

        $this->upsert('organization_user', [
            'organization_id' => $organizationId,
            'user_id' => $userId,
        ], [
            'is_owner' => true,
            'is_active' => true,
            'settings' => $this->json([
                'demo_account' => true,
                'scenario' => 'brick_house',
                'role' => $account['contour'],
            ]),
            'project_access_mode' => 'all_projects',
        ]);

        $this->assignOrganizationRole($userId, $organizationId, 'organization_owner');
        $this->totals['accounts']++;

        return [
            'organization_id' => $organizationId,
            'organization_name' => $account['organization_name'],
            'tax_number' => $account['tax_number'],
            'user_id' => $userId,
            'email' => $account['email'],
            'name' => $account['name'],
        ];
    }

    private function assignOrganizationRole(int $userId, int $organizationId, string $roleSlug): void
    {
        if (!Schema::hasTable('authorization_contexts') || !Schema::hasTable('user_role_assignments')) {
            return;
        }

        $context = AuthorizationContext::getOrganizationContext($organizationId);

        UserRoleAssignment::query()->updateOrCreate(
            [
                'user_id' => $userId,
                'role_slug' => $roleSlug,
                'context_id' => $context->id,
            ],
            [
                'role_type' => UserRoleAssignment::TYPE_SYSTEM,
                'assigned_by' => null,
                'expires_at' => null,
                'is_active' => true,
            ]
        );
    }

    private function activateModules(int $organizationId): void
    {
        $modules = [
            ['slug' => 'project-management', 'name' => 'Управление проектами', 'type' => 'feature', 'category' => 'core'],
            ['slug' => 'contract-management', 'name' => 'Договоры', 'type' => 'feature', 'category' => 'contracts'],
            ['slug' => 'budget-estimates', 'name' => 'Сметы', 'type' => 'feature', 'category' => 'estimates'],
            ['slug' => 'schedule-management', 'name' => 'Календарные графики', 'type' => 'feature', 'category' => 'planning'],
            ['slug' => 'basic-warehouse', 'name' => 'Складской учет', 'type' => 'feature', 'category' => 'warehouse'],
            ['slug' => 'site-requests', 'name' => 'Заявки с объекта', 'type' => 'feature', 'category' => 'field'],
            ['slug' => 'payments', 'name' => 'Платежи', 'type' => 'core', 'category' => 'finance'],
            ['slug' => 'reports', 'name' => 'Отчеты', 'type' => 'core', 'category' => 'analytics'],
            ['slug' => 'dashboard-widgets', 'name' => 'Виджеты дашборда', 'type' => 'service', 'category' => 'analytics'],
            ['slug' => 'ai-assistant', 'name' => 'AI Ассистент', 'type' => 'addon', 'category' => 'ai'],
            ['slug' => 'data-filters', 'name' => 'Фильтры данных', 'type' => 'service', 'category' => 'productivity'],
            ['slug' => 'catalog-management', 'name' => 'Каталог материалов', 'type' => 'feature', 'category' => 'catalog'],
            ['slug' => 'data-export', 'name' => 'Экспорт данных', 'type' => 'service', 'category' => 'documents'],
        ];

        foreach ($modules as $index => $module) {
            $moduleId = $this->upsert('modules', [
                'slug' => $module['slug'],
            ], [
                'name' => $module['name'],
                'version' => '1.0.0',
                'type' => $module['type'],
                'billing_model' => 'free',
                'category' => $module['category'],
                'description' => $module['name'],
                'pricing_config' => $this->json(['base_price' => 0, 'currency' => 'RUB']),
                'features' => $this->json(['demo_data' => true, 'scenario' => 'brick_house']),
                'permissions' => $this->json(['*']),
                'dependencies' => $this->json([]),
                'conflicts' => $this->json([]),
                'limits' => $this->json([]),
                'display_order' => $index + 1,
                'is_active' => true,
                'is_system_module' => false,
                'can_deactivate' => true,
            ]);

            if ($moduleId === 0 || !Schema::hasTable('organization_module_activations')) {
                continue;
            }

            if (!Schema::hasColumn('organization_module_activations', 'module_id')) {
                continue;
            }

            $this->upsert('organization_module_activations', [
                'organization_id' => $organizationId,
                'module_id' => $moduleId,
            ], [
                'status' => 'active',
                'activated_at' => $this->now->copy()->subMonths(5),
                'expires_at' => null,
                'trial_ends_at' => null,
                'last_used_at' => $this->now->copy()->subHours(2),
                'paid_amount' => 0,
                'payment_details' => $this->json(['source' => 'brick_house_demo']),
                'module_settings' => $this->json(['demo_enabled' => true]),
                'usage_stats' => $this->json(['demo_records' => true]),
                'is_bundled_with_plan' => true,
                'is_auto_renew_enabled' => false,
            ]);
        }
    }

    /**
     * @return array<string, int>
     */
    private function seedMeasurementUnits(int $organizationId): array
    {
        $units = [
            'm3' => ['name' => 'Кубический метр', 'short_name' => 'м³', 'type' => 'work'],
            'm2' => ['name' => 'Квадратный метр', 'short_name' => 'м²', 'type' => 'work'],
            'm' => ['name' => 'Метр', 'short_name' => 'м', 'type' => 'work'],
            'pcs' => ['name' => 'Штука', 'short_name' => 'шт', 'type' => 'material'],
            'ton' => ['name' => 'Тонна', 'short_name' => 'т', 'type' => 'material'],
            'pack' => ['name' => 'Упаковка', 'short_name' => 'упак', 'type' => 'material'],
            'hour' => ['name' => 'Человеко-час', 'short_name' => 'чел-ч', 'type' => 'labor'],
            'shift' => ['name' => 'Машино-смена', 'short_name' => 'смена', 'type' => 'equipment'],
        ];

        $ids = [];

        foreach ($units as $key => $unit) {
            $ids[$key] = $this->upsert('measurement_units', [
                'organization_id' => $organizationId,
                'short_name' => $unit['short_name'],
            ], [
                'name' => $unit['name'],
                'type' => $unit['type'],
                'description' => 'Единица измерения для демонстрационного кирпичного дома',
                'is_default' => $key === 'pcs',
                'is_system' => false,
            ]);
        }

        return $ids;
    }

    /**
     * @param array<string, int> $units
     * @return array<string, int>
     */
    private function seedMaterials(int $organizationId, array $units, string $scope): array
    {
        $materials = [
            'brick' => ['code' => "{$scope}-MAT-BRICK-M150", 'name' => 'Кирпич керамический полнотелый М150', 'unit' => 'pcs', 'category' => 'Кладочные материалы', 'price' => 34.50],
            'facing_brick' => ['code' => "{$scope}-MAT-FACE-BRICK", 'name' => 'Кирпич облицовочный лицевой М175', 'unit' => 'pcs', 'category' => 'Фасадные материалы', 'price' => 52.00],
            'mortar' => ['code' => "{$scope}-MAT-MORTAR-M100", 'name' => 'Раствор кладочный М100', 'unit' => 'm3', 'category' => 'Растворы', 'price' => 5100.00],
            'concrete' => ['code' => "{$scope}-MAT-CONCRETE-B25", 'name' => 'Бетон B25 П4 F200 W6', 'unit' => 'm3', 'category' => 'Бетон', 'price' => 6800.00],
            'rebar' => ['code' => "{$scope}-MAT-REBAR-A500C", 'name' => 'Арматура А500С 12 мм', 'unit' => 'ton', 'category' => 'Металлопрокат', 'price' => 74200.00],
            'mesh' => ['code' => "{$scope}-MAT-MESH-50", 'name' => 'Сетка кладочная 50x50x4', 'unit' => 'm2', 'category' => 'Армирование', 'price' => 185.00],
            'insulation' => ['code' => "{$scope}-MAT-WOOL-100", 'name' => 'Минеральная вата 100 мм', 'unit' => 'pack', 'category' => 'Теплоизоляция', 'price' => 1520.00],
            'slab' => ['code' => "{$scope}-MAT-SLAB-PB", 'name' => 'Плиты перекрытия ПБ', 'unit' => 'pcs', 'category' => 'ЖБИ', 'price' => 18600.00],
            'roofing' => ['code' => "{$scope}-MAT-ROOF-METAL", 'name' => 'Металлочерепица 0,5 мм', 'unit' => 'm2', 'category' => 'Кровля', 'price' => 920.00],
            'cement' => ['code' => "{$scope}-MAT-CEMENT-M500", 'name' => 'Цемент М500 Д0', 'unit' => 'ton', 'category' => 'Сухие смеси', 'price' => 8200.00],
        ];

        $ids = [];

        foreach ($materials as $key => $material) {
            $ids[$key] = $this->upsert('materials', [
                'organization_id' => $organizationId,
                'code' => $material['code'],
            ], [
                'name' => $material['name'],
                'measurement_unit_id' => $units[$material['unit']] ?? null,
                'description' => 'Материал демонстрационного объекта "Кирпичный дом"',
                'category' => $material['category'],
                'default_price' => $material['price'],
                'additional_properties' => $this->json([
                    'demo' => true,
                    'scenario' => 'brick_house',
                    'unit_key' => $material['unit'],
                ]),
                'is_active' => true,
                'is_onboarding_demo' => true,
                'use_in_accounting_reports' => true,
            ]);
        }

        return $ids;
    }

    /**
     * @param array<string, int> $units
     * @return array<string, int>
     */
    private function seedWorkTypes(int $organizationId, array $units, string $scope): array
    {
        $workTypes = [
            'site_preparation' => ['code' => "{$scope}-WORK-SITE", 'name' => 'Подготовка строительной площадки', 'unit' => 'm2', 'category' => 'Подготовительные работы', 'price' => 380.00],
            'earthworks' => ['code' => "{$scope}-WORK-EARTH", 'name' => 'Разработка котлована и планировка', 'unit' => 'm3', 'category' => 'Земляные работы', 'price' => 620.00],
            'foundation' => ['code' => "{$scope}-WORK-FOUND", 'name' => 'Устройство монолитного фундамента', 'unit' => 'm3', 'category' => 'Фундамент', 'price' => 5200.00],
            'waterproofing' => ['code' => "{$scope}-WORK-WATERPROOF", 'name' => 'Гидроизоляция фундамента', 'unit' => 'm2', 'category' => 'Фундамент', 'price' => 740.00],
            'masonry_outer' => ['code' => "{$scope}-WORK-MASON-OUT", 'name' => 'Кладка наружных кирпичных стен', 'unit' => 'm3', 'category' => 'Каменные работы', 'price' => 6200.00],
            'masonry_inner' => ['code' => "{$scope}-WORK-MASON-IN", 'name' => 'Кладка внутренних кирпичных перегородок', 'unit' => 'm2', 'category' => 'Каменные работы', 'price' => 1450.00],
            'belt' => ['code' => "{$scope}-WORK-BELT", 'name' => 'Армопояса и перемычки', 'unit' => 'm3', 'category' => 'Железобетон', 'price' => 7600.00],
            'slabs' => ['code' => "{$scope}-WORK-SLABS", 'name' => 'Монтаж плит перекрытия', 'unit' => 'pcs', 'category' => 'Монтаж ЖБИ', 'price' => 4800.00],
            'roof' => ['code' => "{$scope}-WORK-ROOF", 'name' => 'Устройство скатной кровли', 'unit' => 'm2', 'category' => 'Кровля', 'price' => 2100.00],
            'facade' => ['code' => "{$scope}-WORK-FACADE", 'name' => 'Утепление и облицовка фасада', 'unit' => 'm2', 'category' => 'Фасад', 'price' => 2350.00],
            'engineering' => ['code' => "{$scope}-WORK-ENG", 'name' => 'Инженерные вводы и закладные', 'unit' => 'm', 'category' => 'Инженерные сети', 'price' => 1900.00],
            'quality' => ['code' => "{$scope}-WORK-QA", 'name' => 'Исполнительная съемка и контроль качества', 'unit' => 'pcs', 'category' => 'ПТО', 'price' => 12500.00],
        ];

        $ids = [];

        foreach ($workTypes as $key => $workType) {
            $ids[$key] = $this->upsert('work_types', [
                'organization_id' => $organizationId,
                'code' => $workType['code'],
            ], [
                'name' => $workType['name'],
                'measurement_unit_id' => $units[$workType['unit']] ?? null,
                'description' => 'Вид работ демонстрационного объекта "Кирпичный дом"',
                'category' => $workType['category'],
                'default_price' => $workType['price'],
                'additional_properties' => $this->json([
                    'demo' => true,
                    'scenario' => 'brick_house',
                    'unit_key' => $workType['unit'],
                ]),
                'is_active' => true,
                'is_onboarding_demo' => true,
            ]);
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $general
     */
    private function seedProject(array $general): int
    {
        $projectId = $this->upsert('projects', [
            'organization_id' => $general['organization_id'],
            'name' => 'Кирпичный дом "Лесной двор"',
        ], [
            'external_code' => 'BRICK-HOUSE-DEMO-2026',
            'address' => 'Московская область, Истринский район, квартал Лесной двор, участок 17',
            'latitude' => 55.91642,
            'longitude' => 36.84618,
            'geocoded_at' => $this->now->copy()->subMonths(5),
            'geocoding_status' => 'success',
            'description' => 'Демонстрационный проект: двухэтажный кирпичный дом с монолитным фундаментом, скатной кровлей, облицовочным фасадом и благоустройством участка.',
            'customer' => 'Частный заказчик "Лесной двор"',
            'designer' => 'Архитектурная мастерская "Дом и линия"',
            'budget_amount' => 84200000,
            'site_area_m2' => 1860,
            'start_date' => $this->now->copy()->subMonths(5)->startOfMonth()->toDateString(),
            'end_date' => $this->now->copy()->addMonths(7)->endOfMonth()->toDateString(),
            'status' => 'active',
            'additional_info' => $this->json([
                'demo' => true,
                'scenario' => 'brick_house',
                'readiness_percent' => 46,
                'object_type' => 'Двухэтажный кирпичный дом',
                'total_area_m2' => 412,
                'floors' => 2,
                'milestones' => [
                    'Фундамент принят',
                    'Кладка первого этажа завершена',
                    'Кладка второго этажа в работе',
                ],
                'risks' => [
                    'Поставка облицовочного кирпича идет с опережением на 3 дня',
                    'По кровельным работам ожидается уточнение цвета металлочерепицы',
                ],
            ]),
            'is_archived' => false,
            'is_onboarding_demo' => true,
            'is_head' => true,
            'customer_organization' => 'Семья Романовы',
            'customer_representative' => 'Дмитрий Романов',
            'contract_number' => 'ГП-ЛД-04/2026',
            'contract_date' => $this->now->copy()->subMonths(5)->toDateString(),
        ]);

        $this->totals['projects']++;

        return $projectId;
    }

    /**
     * @param array<string, mixed> $general
     * @param array<string, mixed> $contractor
     */
    private function seedProjectMembership(int $projectId, array $general, array $contractor): void
    {
        $this->upsert('project_organization', [
            'project_id' => $projectId,
            'organization_id' => $general['organization_id'],
        ], [
            'role' => 'owner',
            'role_new' => 'general_contractor',
            'permissions' => $this->json(['*']),
            'is_active' => true,
            'added_by_user_id' => $general['user_id'],
            'invited_at' => $this->now->copy()->subMonths(5),
            'accepted_at' => $this->now->copy()->subMonths(5)->addHours(2),
            'metadata' => $this->json([
                'demo' => true,
                'responsibility' => 'Генподряд, координация, снабжение, приемка работ',
            ]),
        ]);

        $this->upsert('project_organization', [
            'project_id' => $projectId,
            'organization_id' => $contractor['organization_id'],
        ], [
            'role' => 'contractor',
            'role_new' => 'contractor',
            'permissions' => $this->json([
                'project.view',
                'schedule.view',
                'site_requests.manage',
                'construction_journal.manage',
                'completed_works.manage',
                'estimates.view',
            ]),
            'is_active' => true,
            'added_by_user_id' => $general['user_id'],
            'invited_at' => $this->now->copy()->subMonths(4)->subDays(20),
            'accepted_at' => $this->now->copy()->subMonths(4)->subDays(19),
            'metadata' => $this->json([
                'demo' => true,
                'responsibility' => 'Кирпичная кладка, армопояса, перемычки и исполнительный журнал',
            ]),
        ]);

        $this->upsert('project_user', [
            'project_id' => $projectId,
            'user_id' => $general['user_id'],
        ], [
            'role' => 'project_manager',
        ]);

        $this->upsert('project_user', [
            'project_id' => $projectId,
            'user_id' => $contractor['user_id'],
        ], [
            'role' => 'contractor_manager',
        ]);
    }

    /**
     * @param array<string, mixed> $general
     * @param array<string, mixed> $contractor
     * @return array<string, int>
     */
    private function seedContractorCards(array $general, array $contractor): array
    {
        $contractorInGeneralId = $this->upsert('contractors', [
            'organization_id' => $general['organization_id'],
            'inn' => $contractor['tax_number'],
        ], [
            'source_organization_id' => $contractor['organization_id'],
            'name' => $contractor['organization_name'],
            'contact_person' => $contractor['name'],
            'phone' => '+7 495 210-44-02',
            'email' => $contractor['email'],
            'legal_address' => 'Москва, ул. Каменщиков, 14',
            'kpp' => '770101002',
            'bank_details' => 'АО "Демо Банк", р/с 40702810900000000002, БИК 044525002',
            'notes' => 'Подрядчик подключен к демо-проекту кирпичного дома.',
            'contractor_type' => 'invited_organization',
            'connected_at' => $this->now->copy()->subMonths(4)->subDays(19),
            'sync_settings' => $this->json(['sync_fields' => ['name', 'phone', 'email', 'legal_address', 'inn', 'kpp']]),
            'last_sync_at' => $this->now->copy()->subHours(4),
        ]);

        $generalInContractorId = $this->upsert('contractors', [
            'organization_id' => $contractor['organization_id'],
            'inn' => $general['tax_number'],
        ], [
            'source_organization_id' => $general['organization_id'],
            'name' => $general['organization_name'],
            'contact_person' => $general['name'],
            'phone' => '+7 495 210-44-01',
            'email' => $general['email'],
            'legal_address' => 'Москва, ул. Строителей, 22',
            'kpp' => '770101001',
            'bank_details' => 'АО "Демо Банк", р/с 40702810900000000001, БИК 044525001',
            'notes' => 'Генподрядчик-заказчик работ по демо-проекту.',
            'contractor_type' => 'invited_organization',
            'connected_at' => $this->now->copy()->subMonths(4)->subDays(19),
            'sync_settings' => $this->json(['sync_fields' => ['name', 'phone', 'email', 'legal_address', 'inn', 'kpp']]),
            'last_sync_at' => $this->now->copy()->subHours(4),
        ]);

        return [
            'contractor_in_general_id' => $contractorInGeneralId,
            'general_in_contractor_id' => $generalInContractorId,
        ];
    }

    /**
     * @param array<string, mixed> $general
     * @param array<string, mixed> $contractor
     * @param array<string, int> $contractors
     * @return array<string, int>
     */
    private function seedContracts(int $projectId, array $general, array $contractor, array $contractors): array
    {
        $contractAmount = 37280000.00;
        $advanceAmount = 7456000.00;

        $generalContractId = $this->upsert('contracts', [
            'organization_id' => $general['organization_id'],
            'number' => 'ГП-ПДР-ЛД-02/2026',
        ], [
            'project_id' => $projectId,
            'contractor_id' => $contractors['contractor_in_general_id'],
            'number' => 'ГП-ПДР-ЛД-02/2026',
            'date' => $this->now->copy()->subMonths(4)->subDays(18)->toDateString(),
            'type' => 'contract',
            'subject' => 'Кладочные работы, армопояса, перемычки и подготовка под монтаж перекрытий кирпичного дома "Лесной двор".',
            'work_type_category' => ContractWorkTypeCategoryEnum::GENERAL_CONSTRUCTION->value,
            'payment_terms' => 'Аванс 20%, промежуточные оплаты по актам КС-2, окончательный расчет после приемки кладки второго этажа.',
            'payment_terms_days' => 10,
            'advance_payment_percent' => 20,
            'auto_create_invoices' => true,
            'base_amount' => $contractAmount,
            'total_amount' => $contractAmount,
            'gp_percentage' => 0,
            'gp_calculation_type' => 'percentage',
            'gp_coefficient' => null,
            'subcontract_amount' => $contractAmount,
            'planned_advance_amount' => $advanceAmount,
            'actual_advance_amount' => $advanceAmount,
            'status' => ContractStatusEnum::ACTIVE->value,
            'start_date' => $this->now->copy()->subMonths(4)->subDays(12)->toDateString(),
            'end_date' => $this->now->copy()->addMonths(2)->addDays(10)->toDateString(),
            'notes' => 'Демо-договор связан с проектом, сметой, актами, платежами и журналом работ.',
            'contract_category' => 'work',
            'contract_side_type' => ContractSideTypeEnum::GENERAL_CONTRACTOR_TO_CONTRACTOR->value,
            'requires_contract_side_review' => false,
            'contract_side_review_reason' => null,
            'is_onboarding_demo' => true,
            'is_fixed_amount' => true,
            'is_multi_project' => false,
            'is_self_execution' => false,
        ]);

        $contractorContractId = $this->upsert('contracts', [
            'organization_id' => $contractor['organization_id'],
            'number' => 'ГП-ПДР-ЛД-02/2026',
        ], [
            'project_id' => $projectId,
            'contractor_id' => $contractors['general_in_contractor_id'],
            'number' => 'ГП-ПДР-ЛД-02/2026',
            'date' => $this->now->copy()->subMonths(4)->subDays(18)->toDateString(),
            'type' => 'contract',
            'subject' => 'Выполнение кирпичной кладки и железобетонных включений по проекту "Лесной двор".',
            'work_type_category' => ContractWorkTypeCategoryEnum::GENERAL_CONSTRUCTION->value,
            'payment_terms' => 'Входящий договор от генподрядчика: аванс получен, следующий платеж после акта за кладку первого этажа.',
            'payment_terms_days' => 10,
            'advance_payment_percent' => 20,
            'auto_create_invoices' => true,
            'base_amount' => $contractAmount,
            'total_amount' => $contractAmount,
            'gp_percentage' => 0,
            'gp_calculation_type' => 'percentage',
            'gp_coefficient' => null,
            'subcontract_amount' => $contractAmount,
            'planned_advance_amount' => $advanceAmount,
            'actual_advance_amount' => $advanceAmount,
            'status' => ContractStatusEnum::ACTIVE->value,
            'start_date' => $this->now->copy()->subMonths(4)->subDays(12)->toDateString(),
            'end_date' => $this->now->copy()->addMonths(2)->addDays(10)->toDateString(),
            'notes' => 'Зеркальная карточка договора для демо-аккаунта подрядчика.',
            'contract_category' => 'work',
            'contract_side_type' => null,
            'requires_contract_side_review' => false,
            'contract_side_review_reason' => null,
            'is_onboarding_demo' => true,
            'is_fixed_amount' => true,
            'is_multi_project' => false,
            'is_self_execution' => false,
        ]);

        $this->totals['contracts'] += 2;

        return [
            'general_contract_id' => $generalContractId,
            'contractor_contract_id' => $contractorContractId,
        ];
    }

    /**
     * @param array<string, int> $units
     * @param array<string, int> $workTypes
     * @param array<string, int> $materials
     * @param array<int, array<string, mixed>> $sections
     * @return array{id: int, items: array<string, int>, item_details: array<string, array<string, mixed>>}
     */
    private function seedEstimate(
        int $organizationId,
        int $projectId,
        int $contractId,
        int $userId,
        array $units,
        array $workTypes,
        array $materials,
        string $number,
        string $name,
        array $sections
    ): array {
        if (!Schema::hasTable('estimates')) {
            return ['id' => 0, 'items' => [], 'item_details' => []];
        }

        $totals = $this->calculateEstimateTotals($sections);

        $estimateId = $this->upsert('estimates', [
            'organization_id' => $organizationId,
            'number' => $number,
        ], [
            'project_id' => $projectId,
            'contract_id' => $contractId,
            'name' => $name,
            'description' => 'Демонстрационная смета с разделами, ресурсами, материалами и связью с договором.',
            'type' => 'contractual',
            'status' => 'approved',
            'version' => 3,
            'parent_estimate_id' => null,
            'estimate_date' => $this->now->copy()->subMonths(4)->subDays(8)->toDateString(),
            'base_price_date' => $this->now->copy()->subMonths(5)->startOfMonth()->toDateString(),
            'total_direct_costs' => $totals['direct'],
            'total_overhead_costs' => $totals['overhead'],
            'total_estimated_profit' => $totals['profit'],
            'total_amount' => $totals['total'],
            'total_amount_with_vat' => $totals['with_vat'],
            'vat_rate' => 20,
            'overhead_rate' => 8,
            'profit_rate' => 6,
            'calculation_method' => 'resource',
            'approved_at' => $this->now->copy()->subMonths(4)->subDays(4),
            'approved_by_user_id' => $userId,
            'metadata' => $this->json([
                'demo' => true,
                'scenario' => 'brick_house',
                'revision' => 'После уточнения ведомости кирпичной кладки',
            ]),
        ]);

        $items = [];
        $itemDetails = [];

        foreach ($sections as $sectionIndex => $section) {
            $sectionId = $this->upsert('estimate_sections', [
                'estimate_id' => $estimateId,
                'section_number' => (string) $section['number'],
            ], [
                'parent_section_id' => null,
                'full_section_number' => (string) $section['number'],
                'name' => $section['name'],
                'description' => $section['description'] ?? null,
                'sort_order' => $sectionIndex + 1,
                'is_summary' => false,
                'section_total_amount' => $this->calculateSectionTotal($section['items']),
            ]);

            foreach ($section['items'] as $item) {
                $calculated = $this->calculateItemAmounts((float) $item['quantity'], (float) $item['unit_price']);
                $unitId = $units[$item['unit']] ?? null;
                $workTypeId = isset($item['work']) ? ($workTypes[$item['work']] ?? null) : null;
                $materialId = isset($item['material']) ? ($materials[$item['material']] ?? null) : null;

                $itemId = $this->upsert('estimate_items', [
                    'estimate_id' => $estimateId,
                    'position_number' => $item['position'],
                ], [
                    'estimate_section_id' => $sectionId,
                    'item_type' => $item['type'],
                    'name' => $item['name'],
                    'description' => $item['description'] ?? null,
                    'work_type_id' => $workTypeId,
                    'measurement_unit_id' => $unitId,
                    'material_id' => $materialId,
                    'quantity' => $item['quantity'],
                    'quantity_coefficient' => 1,
                    'quantity_total' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'base_unit_price' => $item['unit_price'],
                    'price_index' => 1,
                    'current_unit_price' => $item['unit_price'],
                    'price_coefficient' => 1,
                    'direct_costs' => $calculated['direct'],
                    'materials_cost' => $item['type'] === 'material' ? $calculated['direct'] : round($calculated['direct'] * 0.42, 2),
                    'machinery_cost' => $item['type'] === 'equipment' ? $calculated['direct'] : round($calculated['direct'] * 0.12, 2),
                    'labor_cost' => $item['type'] === 'labor' ? $calculated['direct'] : round($calculated['direct'] * 0.46, 2),
                    'equipment_cost' => $item['type'] === 'equipment' ? $calculated['direct'] : 0,
                    'labor_hours' => $item['labor_hours'] ?? round((float) $item['quantity'] * 1.8, 2),
                    'machinery_hours' => $item['machinery_hours'] ?? round((float) $item['quantity'] * 0.25, 2),
                    'base_materials_cost' => $item['type'] === 'material' ? $calculated['direct'] : round($calculated['direct'] * 0.42, 2),
                    'base_machinery_cost' => $item['type'] === 'equipment' ? $calculated['direct'] : round($calculated['direct'] * 0.12, 2),
                    'base_labor_cost' => $item['type'] === 'labor' ? $calculated['direct'] : round($calculated['direct'] * 0.46, 2),
                    'materials_index' => 1,
                    'machinery_index' => 1,
                    'labor_index' => 1,
                    'overhead_amount' => $calculated['overhead'],
                    'profit_amount' => $calculated['profit'],
                    'total_amount' => $calculated['total'],
                    'current_total_amount' => $calculated['total'],
                    'justification' => $item['justification'] ?? null,
                    'is_manual' => true,
                    'applied_coefficients' => $this->json(['demo' => true, 'winter' => 1.0]),
                    'coefficient_total' => 1,
                    'resource_calculation' => $this->json([
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'unit' => $item['unit'],
                    ]),
                    'custom_resources' => $this->json($item['resources'] ?? []),
                    'metadata' => $this->json([
                        'demo' => true,
                        'scenario' => 'brick_house',
                        'section' => $section['name'],
                    ]),
                    'notes' => $item['notes'] ?? null,
                    'code' => $item['code'] ?? null,
                    'normative_rate_code' => $item['normative'] ?? null,
                    'is_not_accounted' => false,
                ]);

                $items[$item['position']] = $itemId;
                $itemDetails[$item['position']] = [
                    'id' => $itemId,
                    'title' => $item['name'],
                    'unit' => $this->unitShortName($item['unit']),
                    'quantity' => (float) $item['quantity'],
                    'unit_price' => (float) $item['unit_price'],
                    'amount' => $calculated['total'],
                    'work_type_id' => $workTypeId,
                    'material_id' => $materialId,
                ];

                if ($contractId > 0) {
                    $this->upsert('contract_estimate_items', [
                        'contract_id' => $contractId,
                        'estimate_item_id' => $itemId,
                    ], [
                        'estimate_id' => $estimateId,
                        'quantity' => $item['quantity'],
                        'amount' => $calculated['total'],
                        'notes' => 'Позиция включена в демо-договор кирпичного дома',
                    ]);
                }

                $this->totals['estimate_items']++;
            }
        }

        $this->totals['estimates']++;

        return [
            'id' => $estimateId,
            'items' => $items,
            'item_details' => $itemDetails,
        ];
    }

    /**
     * @param array<string, int> $units
     * @param array<string, int> $workTypes
     * @param array<string, int> $materials
     * @return array<string, int>
     */
    private function seedSchedule(
        int $organizationId,
        int $projectId,
        int $userId,
        array $units,
        array $workTypes,
        array $materials,
        string $scope
    ): array {
        if (!Schema::hasTable('project_schedules') || !Schema::hasTable('schedule_tasks')) {
            return [];
        }

        $name = $scope === 'GP'
            ? 'Сводный график строительства кирпичного дома'
            : 'График подрядчика по кладочным работам';

        $scheduleId = $this->upsert('project_schedules', [
            'project_id' => $projectId,
            'organization_id' => $organizationId,
            'name' => $name,
        ], [
            'created_by_user_id' => $userId,
            'description' => $scope === 'GP'
                ? 'Сводный график с критическим путем, поставками и контрольными точками генподряда.'
                : 'Рабочий график подрядчика: кладка, армопояса, перемычки и сдача захваток.',
            'planned_start_date' => $this->now->copy()->subMonths(5)->startOfMonth()->toDateString(),
            'planned_end_date' => $this->now->copy()->addMonths(7)->endOfMonth()->toDateString(),
            'baseline_start_date' => $this->now->copy()->subMonths(5)->startOfMonth()->toDateString(),
            'baseline_end_date' => $this->now->copy()->addMonths(7)->endOfMonth()->toDateString(),
            'baseline_saved_at' => $this->now->copy()->subMonths(4),
            'baseline_saved_by_user_id' => $userId,
            'actual_start_date' => $this->now->copy()->subMonths(5)->startOfMonth()->addDays(2)->toDateString(),
            'status' => 'active',
            'is_template' => false,
            'calculation_settings' => $this->json(['calendar' => 'six_days', 'shift_hours' => 8]),
            'display_settings' => $this->json(['view' => 'gantt', 'show_critical_path' => true, 'group_by' => 'phase']),
            'critical_path_calculated' => true,
            'critical_path_updated_at' => $this->now->copy()->subHours(5),
            'critical_path_duration_days' => $scope === 'GP' ? 286 : 94,
            'total_estimated_cost' => $scope === 'GP' ? 84200000 : 37280000,
            'total_actual_cost' => $scope === 'GP' ? 31860000 : 12640000,
            'overall_progress_percent' => $scope === 'GP' ? 46 : 39,
        ]);

        $tasks = $scope === 'GP' ? $this->generalScheduleTasks() : $this->contractorScheduleTasks();
        $taskIds = [];

        foreach ($tasks as $index => $task) {
            $start = $this->now->copy()->addDays($task['start_offset']);
            $end = $start->copy()->addDays($task['duration']);
            $progress = (float) $task['progress'];

            $taskId = $this->upsert('schedule_tasks', [
                'schedule_id' => $scheduleId,
                'wbs_code' => $task['wbs'],
            ], [
                'organization_id' => $organizationId,
                'parent_task_id' => null,
                'work_type_id' => $workTypes[$task['work']] ?? null,
                'assigned_user_id' => $userId,
                'created_by_user_id' => $userId,
                'name' => $task['name'],
                'description' => $task['description'],
                'task_type' => $task['type'] ?? 'task',
                'planned_start_date' => $start->toDateString(),
                'planned_end_date' => $end->toDateString(),
                'planned_duration_days' => $task['duration'],
                'planned_work_hours' => $task['duration'] * 8,
                'baseline_start_date' => $start->copy()->subDays($task['baseline_shift'] ?? 0)->toDateString(),
                'baseline_end_date' => $end->copy()->subDays($task['baseline_shift'] ?? 0)->toDateString(),
                'baseline_duration_days' => $task['duration'],
                'actual_start_date' => $progress > 0 ? $start->copy()->addDay()->toDateString() : null,
                'actual_end_date' => $progress >= 100 ? $end->copy()->addDays($task['actual_shift'] ?? 0)->toDateString() : null,
                'actual_duration_days' => $progress >= 100 ? $task['duration'] + ($task['actual_shift'] ?? 0) : null,
                'actual_work_hours' => round($task['duration'] * 8 * ($progress / 100), 2),
                'early_start_date' => $start->toDateString(),
                'early_finish_date' => $end->toDateString(),
                'late_start_date' => $task['critical'] ? $start->toDateString() : $start->copy()->addDays(6)->toDateString(),
                'late_finish_date' => $task['critical'] ? $end->toDateString() : $end->copy()->addDays(6)->toDateString(),
                'total_float_days' => $task['critical'] ? 0 : 6,
                'free_float_days' => $task['critical'] ? 0 : 3,
                'is_critical' => $task['critical'],
                'is_milestone_critical' => false,
                'progress_percent' => $progress,
                'status' => $task['status'],
                'priority' => $task['priority'],
                'estimated_cost' => $task['cost'],
                'actual_cost' => round($task['cost'] * ($progress / 100), 2),
                'earned_value' => round($task['cost'] * ($progress / 100), 2),
                'required_resources' => $this->json($task['resources']),
                'constraint_type' => 'none',
                'custom_fields' => $this->json([
                    'demo' => true,
                    'phase' => $task['phase'],
                    'responsible' => $scope === 'GP' ? 'Генподряд' : 'Подрядчик',
                ]),
                'notes' => $task['notes'] ?? null,
                'tags' => $this->json(['brick-house-demo', $task['phase']]),
                'level' => 1,
                'sort_order' => $index + 1,
            ]);

            $taskIds[$task['key']] = $taskId;
            $this->seedTaskResources($taskId, $scheduleId, $organizationId, $userId, $task, $materials);
            $this->totals['schedule_tasks']++;
        }

        $previousTaskId = null;
        foreach ($taskIds as $taskKey => $taskId) {
            if ($previousTaskId !== null) {
                $this->upsert('task_dependencies', [
                    'predecessor_task_id' => $previousTaskId,
                    'successor_task_id' => $taskId,
                    'dependency_type' => 'FS',
                ], [
                    'schedule_id' => $scheduleId,
                    'organization_id' => $organizationId,
                    'created_by_user_id' => $userId,
                    'lag_days' => in_array($taskKey, ['masonry_inner', 'belt'], true) ? -2 : 0,
                    'lag_hours' => 0,
                    'lag_type' => 'days',
                    'is_critical' => true,
                    'is_hard_constraint' => true,
                    'priority' => 10,
                    'description' => 'Демо-связь графика кирпичного дома',
                    'constraint_reason' => null,
                    'is_active' => true,
                    'validation_status' => 'valid',
                    'advanced_settings' => $this->json(['demo' => true]),
                ]);
            }

            $previousTaskId = $taskId;
        }

        $this->seedMilestones($scheduleId, $organizationId, $userId, $taskIds, $scope);

        return $taskIds;
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, int> $materials
     */
    private function seedTaskResources(
        int $taskId,
        int $scheduleId,
        int $organizationId,
        int $userId,
        array $task,
        array $materials
    ): void {
        if (!Schema::hasTable('task_resources')) {
            return;
        }

        foreach (($task['material_resources'] ?? []) as $resource) {
            $materialId = $materials[$resource['material']] ?? null;
            if (!$materialId) {
                continue;
            }

            $this->upsert('task_resources', [
                'task_id' => $taskId,
                'resource_type' => 'material',
                'role' => $resource['role'],
            ], [
                'schedule_id' => $scheduleId,
                'organization_id' => $organizationId,
                'assigned_by_user_id' => $userId,
                'resource_id' => $materialId,
                'resource_model' => Material::class,
                'user_id' => null,
                'material_id' => $materialId,
                'equipment_name' => null,
                'external_resource_name' => null,
                'allocated_units' => $resource['quantity'],
                'allocated_hours' => null,
                'actual_hours' => 0,
                'allocation_percent' => 100,
                'assignment_start_date' => $this->now->copy()->addDays($task['start_offset'])->toDateString(),
                'assignment_end_date' => $this->now->copy()->addDays($task['start_offset'] + $task['duration'])->toDateString(),
                'cost_per_hour' => null,
                'cost_per_unit' => $resource['unit_price'],
                'total_planned_cost' => round($resource['quantity'] * $resource['unit_price'], 2),
                'total_actual_cost' => round($resource['quantity'] * $resource['unit_price'] * ((float) $task['progress'] / 100), 2),
                'assignment_status' => $task['progress'] >= 100 ? 'completed' : ($task['progress'] > 0 ? 'in_progress' : 'planned'),
                'priority' => $task['priority'],
                'requirements' => $this->json(['demo' => true]),
                'working_calendar' => $this->json(['calendar' => 'six_days']),
                'daily_working_hours' => 8,
                'has_conflicts' => false,
                'conflict_details' => $this->json([]),
                'notes' => 'Материал зарезервирован под задачу графика',
                'allocation_details' => $this->json(['source' => 'brick_house_demo']),
            ]);
        }

        foreach (($task['equipment_resources'] ?? []) as $resource) {
            $this->upsert('task_resources', [
                'task_id' => $taskId,
                'resource_type' => 'equipment',
                'role' => $resource['role'],
            ], [
                'schedule_id' => $scheduleId,
                'organization_id' => $organizationId,
                'assigned_by_user_id' => $userId,
                'resource_id' => 0,
                'resource_model' => 'equipment',
                'user_id' => null,
                'material_id' => null,
                'equipment_name' => $resource['name'],
                'external_resource_name' => null,
                'allocated_units' => $resource['units'],
                'allocated_hours' => $resource['hours'],
                'actual_hours' => round($resource['hours'] * ((float) $task['progress'] / 100), 2),
                'allocation_percent' => 100,
                'assignment_start_date' => $this->now->copy()->addDays($task['start_offset'])->toDateString(),
                'assignment_end_date' => $this->now->copy()->addDays($task['start_offset'] + $task['duration'])->toDateString(),
                'cost_per_hour' => $resource['rate'],
                'cost_per_unit' => null,
                'total_planned_cost' => round($resource['hours'] * $resource['rate'], 2),
                'total_actual_cost' => round($resource['hours'] * $resource['rate'] * ((float) $task['progress'] / 100), 2),
                'assignment_status' => $task['progress'] >= 100 ? 'completed' : ($task['progress'] > 0 ? 'in_progress' : 'planned'),
                'priority' => $task['priority'],
                'requirements' => $this->json(['operator' => true]),
                'working_calendar' => $this->json(['calendar' => 'six_days']),
                'daily_working_hours' => 8,
                'has_conflicts' => false,
                'conflict_details' => $this->json([]),
                'notes' => 'Техника назначена на задачу графика',
                'allocation_details' => $this->json(['source' => 'brick_house_demo']),
            ]);
        }
    }

    /**
     * @param array<string, int> $taskIds
     */
    private function seedMilestones(int $scheduleId, int $organizationId, int $userId, array $taskIds, string $scope): void
    {
        if (!Schema::hasTable('task_milestones') || $taskIds === []) {
            return;
        }

        $milestones = $scope === 'GP'
            ? [
                ['task' => 'foundation', 'name' => 'Фундамент принят заказчиком', 'type' => 'approval', 'offset' => -64, 'status' => 'achieved', 'percent' => 100, 'payment' => 12400000],
                ['task' => 'masonry_outer', 'name' => 'Кладка первого этажа завершена', 'type' => 'deliverable', 'offset' => -5, 'status' => 'achieved', 'percent' => 100, 'payment' => 8600000],
                ['task' => 'roof', 'name' => 'Закрыть контур дома', 'type' => 'phase_end', 'offset' => 82, 'status' => 'pending', 'percent' => 28, 'payment' => 11200000],
            ]
            : [
                ['task' => 'masonry_outer', 'name' => 'Сдать кладку первого этажа', 'type' => 'deliverable', 'offset' => -5, 'status' => 'achieved', 'percent' => 100, 'payment' => 8600000],
                ['task' => 'belt', 'name' => 'Армопояс под плиты готов', 'type' => 'approval', 'offset' => 23, 'status' => 'in_progress', 'percent' => 45, 'payment' => 5200000],
            ];

        foreach ($milestones as $milestone) {
            $taskId = $taskIds[$milestone['task']] ?? reset($taskIds);

            $this->upsert('task_milestones', [
                'task_id' => $taskId,
                'name' => $milestone['name'],
            ], [
                'schedule_id' => $scheduleId,
                'organization_id' => $organizationId,
                'created_by_user_id' => $userId,
                'description' => 'Контрольная точка демонстрационного графика кирпичного дома',
                'milestone_type' => $milestone['type'],
                'target_date' => $this->now->copy()->addDays($milestone['offset'])->toDateString(),
                'baseline_date' => $this->now->copy()->addDays($milestone['offset'] - 2)->toDateString(),
                'actual_date' => $milestone['status'] === 'achieved' ? $this->now->copy()->addDays($milestone['offset'] + 1)->toDateString() : null,
                'status' => $milestone['status'],
                'priority' => 'high',
                'is_critical' => true,
                'is_external' => false,
                'completion_criteria' => $this->json(['Акт осмотра', 'Фотофиксация', 'Запись в журнале работ']),
                'completion_percent' => $milestone['percent'],
                'responsible_user_id' => $userId,
                'stakeholders' => $this->json(['Генподрядчик', 'Подрядчик', 'Заказчик']),
                'deliverables' => $this->json(['Исполнительная схема', 'Журнал работ']),
                'approvals_required' => $this->json(['ПТО', 'Прораб']),
                'notification_settings' => $this->json(['days_before' => [7, 2]]),
                'alert_days_before' => 7,
                'risk_level' => $milestone['status'] === 'pending' ? 'medium' : 'low',
                'risk_description' => $milestone['status'] === 'pending' ? 'Зависит от поставки материалов и погоды' : null,
                'mitigation_plan' => $milestone['status'] === 'pending' ? 'Материалы зарезервированы на складе, поставка подтверждена' : null,
                'budget_impact' => $milestone['payment'],
                'triggers_payment' => true,
                'payment_amount' => $milestone['payment'],
                'notes' => 'Демо-веха используется для презентации графика и оплат',
                'custom_fields' => $this->json(['demo' => true]),
                'tags' => $this->json(['brick-house-demo']),
                'external_id' => "BH-{$scope}-MS-" . md5($milestone['name']),
                'external_data' => $this->json(['source' => 'brick_house_demo']),
            ]);
        }
    }

    /**
     * @param array<string, int> $materials
     * @return array<string, int>
     */
    private function seedWarehouse(int $organizationId, int $userId, int $projectId, array $materials, string $scope): array
    {
        if (!Schema::hasTable('organization_warehouses') || !Schema::hasTable('warehouse_balances')) {
            return [];
        }

        $warehouseId = $this->upsert('organization_warehouses', [
            'organization_id' => $organizationId,
            'code' => "{$scope}-BRICK-HOUSE",
        ], [
            'name' => $scope === 'GP' ? 'Склад генподряда "Лесной двор"' : 'Склад подрядчика на объекте',
            'address' => $scope === 'GP'
                ? 'Московская область, Истринский район, строительная площадка "Лесной двор", зона разгрузки 1'
                : 'Московская область, Истринский район, строительная площадка "Лесной двор", бытовой городок подрядчика',
            'description' => 'Склад демонстрационного объекта с партиями, резервами и движениями материалов.',
            'warehouse_type' => 'project',
            'is_main' => true,
            'is_active' => true,
            'settings' => $this->json(['demo' => true, 'fifo' => true, 'project_id' => $projectId]),
            'contact_person' => $scope === 'GP' ? 'Илья Смирнов' : 'Павел Никитин',
            'contact_phone' => $scope === 'GP' ? '+7 916 210-44-11' : '+7 916 210-44-22',
            'working_hours' => 'Пн-Сб 08:00-20:00',
            'storage_conditions' => $this->json(['covered_zone' => true, 'security' => true, 'batch_tracking' => true]),
        ]);

        $stocks = [
            'brick' => ['available' => $scope === 'GP' ? 28400 : 12600, 'reserved' => $scope === 'GP' ? 6200 : 4800, 'price' => 34.50, 'min' => 9000, 'max' => 52000, 'loc' => 'A-01', 'batch' => "{$scope}-BRICK-0426"],
            'mortar' => ['available' => $scope === 'GP' ? 42.5 : 18.0, 'reserved' => $scope === 'GP' ? 9.0 : 6.5, 'price' => 5100.00, 'min' => 8, 'max' => 70, 'loc' => 'B-03', 'batch' => "{$scope}-MORTAR-0526"],
            'rebar' => ['available' => $scope === 'GP' ? 8.7 : 2.6, 'reserved' => $scope === 'GP' ? 1.8 : 0.8, 'price' => 74200.00, 'min' => 1.5, 'max' => 16, 'loc' => 'C-02', 'batch' => "{$scope}-REBAR-0326"],
            'mesh' => ['available' => $scope === 'GP' ? 740 : 260, 'reserved' => $scope === 'GP' ? 160 : 90, 'price' => 185.00, 'min' => 120, 'max' => 1200, 'loc' => 'C-05', 'batch' => "{$scope}-MESH-0526"],
            'insulation' => ['available' => $scope === 'GP' ? 96 : 24, 'reserved' => $scope === 'GP' ? 18 : 6, 'price' => 1520.00, 'min' => 20, 'max' => 180, 'loc' => 'D-01', 'batch' => "{$scope}-WOOL-0626"],
        ];

        foreach ($stocks as $materialKey => $stock) {
            $materialId = $materials[$materialKey] ?? null;
            if (!$materialId) {
                continue;
            }

            $this->upsert('warehouse_balances', [
                'warehouse_id' => $warehouseId,
                'material_id' => $materialId,
                'batch_number' => $stock['batch'],
            ], [
                'organization_id' => $organizationId,
                'available_quantity' => $stock['available'],
                'reserved_quantity' => $stock['reserved'],
                'average_price' => $stock['price'],
                'unit_price' => $stock['price'],
                'min_stock_level' => $stock['min'],
                'max_stock_level' => $stock['max'],
                'location_code' => $stock['loc'],
                'serial_number' => null,
                'expiry_date' => $this->now->copy()->addMonths(10)->toDateString(),
                'last_movement_at' => $this->now->copy()->subDays(1),
            ]);

            if (Schema::hasTable('warehouse_project_allocations')) {
                $this->upsert('warehouse_project_allocations', [
                    'warehouse_id' => $warehouseId,
                    'material_id' => $materialId,
                    'project_id' => $projectId,
                ], [
                    'organization_id' => $organizationId,
                    'allocated_quantity' => $stock['reserved'],
                    'allocated_by_user_id' => $userId,
                    'allocated_at' => $this->now->copy()->subDays(6),
                    'notes' => 'Резерв под демонстрационный проект кирпичного дома',
                ]);
            }

            $this->seedWarehouseMovement(
                organizationId: $organizationId,
                warehouseId: $warehouseId,
                materialId: $materialId,
                projectId: $projectId,
                userId: $userId,
                documentNumber: "{$scope}-WH-IN-" . strtoupper($materialKey),
                type: 'receipt',
                quantity: (float) $stock['available'] + (float) $stock['reserved'],
                price: (float) $stock['price'],
                reason: 'Поступление материала на объект "Лесной двор"'
            );

            $this->seedWarehouseMovement(
                organizationId: $organizationId,
                warehouseId: $warehouseId,
                materialId: $materialId,
                projectId: $projectId,
                userId: $userId,
                documentNumber: "{$scope}-WH-RES-" . strtoupper($materialKey),
                type: 'adjustment',
                quantity: (float) $stock['reserved'],
                price: (float) $stock['price'],
                reason: 'Резервирование под ближайшие работы графика'
            );
        }

        if (Schema::hasTable('asset_reservations')) {
            foreach (['brick', 'mortar', 'mesh'] as $materialKey) {
                $materialId = $materials[$materialKey] ?? null;
                if (!$materialId) {
                    continue;
                }

                $this->upsert('asset_reservations', [
                    'organization_id' => $organizationId,
                    'warehouse_id' => $warehouseId,
                    'material_id' => $materialId,
                    'project_id' => $projectId,
                ], [
                    'quantity' => $materialKey === 'brick' ? 4200 : ($materialKey === 'mortar' ? 8.5 : 90),
                    'reserved_by' => $userId,
                    'status' => 'active',
                    'reserved_at' => $this->now->copy()->subDays(5),
                    'expires_at' => $this->now->copy()->addDays(12),
                    'fulfilled_at' => null,
                    'cancelled_at' => null,
                    'reason' => 'Резерв на кладку второго этажа',
                    'metadata' => $this->json(['demo' => true, 'scenario' => 'brick_house']),
                ]);
            }
        }

        return ['warehouse_id' => $warehouseId];
    }

    private function seedWarehouseMovement(
        int $organizationId,
        int $warehouseId,
        int $materialId,
        int $projectId,
        int $userId,
        string $documentNumber,
        string $type,
        float $quantity,
        float $price,
        string $reason
    ): int {
        if (!Schema::hasTable('warehouse_movements')) {
            return 0;
        }

        $movementId = $this->upsert('warehouse_movements', [
            'document_number' => $documentNumber,
        ], [
            'organization_id' => $organizationId,
            'warehouse_id' => $warehouseId,
            'material_id' => $materialId,
            'movement_type' => $type,
            'quantity' => $quantity,
            'price' => $price,
            'from_warehouse_id' => null,
            'to_warehouse_id' => null,
            'project_id' => $projectId,
            'user_id' => $userId,
            'reason' => $reason,
            'metadata' => $this->json(['demo' => true, 'scenario' => 'brick_house']),
            'movement_date' => $this->now->copy()->subDays($type === 'receipt' ? 9 : 3),
        ]);

        $this->totals['warehouse_movements']++;

        return $movementId;
    }

    /**
     * @param array<string, int> $materials
     * @param array<string, int> $estimateItems
     * @return array<string, int>
     */
    private function seedSiteRequests(
        int $organizationId,
        int $projectId,
        int $userId,
        int $assignedUserId,
        array $materials,
        array $estimateItems,
        string $scope
    ): array {
        if (!Schema::hasTable('site_requests')) {
            return [];
        }

        $requests = [
            'brick_delivery' => [
                'title' => $scope === 'GP' ? 'Доставить кирпич М150 на кладку второго этажа' : 'Получить кирпич М150 на захватку Б',
                'type' => 'material_request',
                'status' => 'fulfilled',
                'priority' => 'high',
                'material' => 'brick',
                'estimate_item' => '3.1',
                'quantity' => $scope === 'GP' ? 6200 : 4200,
                'unit' => 'шт',
                'required_offset' => -3,
                'description' => 'Материал нужен для продолжения кладки без простоя бригады.',
            ],
            'mortar_today' => [
                'title' => 'Раствор М100 на дневную смену кладки',
                'type' => 'material_request',
                'status' => 'in_progress',
                'priority' => 'urgent',
                'material' => 'mortar',
                'estimate_item' => '3.2',
                'quantity' => 8.5,
                'unit' => 'м³',
                'required_offset' => 1,
                'description' => 'Поставка раствора к 09:30, разгрузка у оси Г-7.',
            ],
            'masons' => [
                'title' => 'Усилить бригаду каменщиков на армопояс',
                'type' => 'personnel_request',
                'status' => 'approved',
                'priority' => 'medium',
                'material' => null,
                'estimate_item' => '3.5',
                'quantity' => null,
                'unit' => null,
                'required_offset' => 4,
                'description' => 'Нужно добавить 4 каменщика на 6 смен для закрытия контрольной точки.',
            ],
            'crane' => [
                'title' => 'Автокран 25 т для перемычек и плит',
                'type' => 'equipment_request',
                'status' => 'pending',
                'priority' => 'high',
                'material' => null,
                'estimate_item' => '4.2',
                'quantity' => null,
                'unit' => null,
                'required_offset' => 10,
                'description' => 'Кран нужен под монтаж перемычек и разгрузку плит перекрытия.',
            ],
            'quality_issue' => [
                'title' => 'Проверить геометрию кладки по оси Д',
                'type' => 'issue_report',
                'status' => 'completed',
                'priority' => 'medium',
                'material' => null,
                'estimate_item' => '3.1',
                'quantity' => null,
                'unit' => null,
                'required_offset' => -1,
                'description' => 'Прораб отметил отклонение по шнуру, ПТО подтвердило корректировку ряда.',
            ],
        ];

        $ids = [];

        foreach ($requests as $key => $request) {
            $materialId = $request['material'] ? ($materials[$request['material']] ?? null) : null;
            $estimateItemId = $request['estimate_item'] ? ($estimateItems[$request['estimate_item']] ?? null) : null;

            $ids[$key] = $this->upsert('site_requests', [
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'title' => $request['title'],
            ], [
                'user_id' => $userId,
                'assigned_to' => $assignedUserId,
                'description' => $request['description'],
                'status' => $request['status'],
                'priority' => $request['priority'],
                'request_type' => $request['type'],
                'required_date' => $this->now->copy()->addDays($request['required_offset'])->toDateString(),
                'notes' => 'Демо-заявка с объекта кирпичного дома',
                'material_id' => $materialId,
                'estimate_item_id' => $estimateItemId,
                'material_name' => $materialId ? null : ($request['type'] === 'personnel_request' ? 'Каменщики 4-5 разряда' : null),
                'material_quantity' => $request['quantity'],
                'material_unit' => $request['unit'],
                'delivery_address' => 'Объект "Лесной двор", зона разгрузки у временной дороги',
                'delivery_time_from' => '08:30:00',
                'delivery_time_to' => '12:30:00',
                'contact_person_name' => $scope === 'GP' ? 'Илья Смирнов' : 'Павел Никитин',
                'contact_person_phone' => $scope === 'GP' ? '+7 916 210-44-11' : '+7 916 210-44-22',
                'personnel_type' => $request['type'] === 'personnel_request' ? 'mason' : null,
                'personnel_count' => $request['type'] === 'personnel_request' ? 4 : null,
                'personnel_requirements' => $request['type'] === 'personnel_request' ? 'Опыт кладки несущих стен, допуск к работе на лесах' : null,
                'hourly_rate' => $request['type'] === 'personnel_request' ? 850 : null,
                'work_hours_per_day' => $request['type'] === 'personnel_request' ? 8 : null,
                'work_start_date' => $request['type'] === 'personnel_request' ? $this->now->copy()->addDays(3)->toDateString() : null,
                'work_end_date' => $request['type'] === 'personnel_request' ? $this->now->copy()->addDays(9)->toDateString() : null,
                'work_location' => $request['type'] === 'personnel_request' ? 'Второй этаж, оси Б-Е' : null,
                'additional_conditions' => $request['type'] === 'personnel_request' ? 'Инструмент и СИЗ на стороне подрядчика' : null,
                'equipment_type' => $request['type'] === 'equipment_request' ? 'mobile_crane' : null,
                'equipment_count' => $request['type'] === 'equipment_request' ? 1 : null,
                'equipment_specs' => $request['type'] === 'equipment_request' ? 'Грузоподъемность 25 т, стрела от 21 м' : null,
                'rental_start_date' => $request['type'] === 'equipment_request' ? $this->now->copy()->addDays(9)->toDateString() : null,
                'rental_end_date' => $request['type'] === 'equipment_request' ? $this->now->copy()->addDays(11)->toDateString() : null,
                'rental_hours_per_day' => $request['type'] === 'equipment_request' ? 8 : null,
                'with_operator' => $request['type'] === 'equipment_request',
                'equipment_location' => $request['type'] === 'equipment_request' ? 'Пятно застройки, южный фасад' : null,
                'metadata' => $this->json([
                    'demo' => true,
                    'scenario' => 'brick_house',
                    'scope' => $scope,
                    'dialog' => [
                        'Прораб создал заявку',
                        'Снабжение подтвердило наличие',
                        'ПТО привязало к позиции сметы',
                    ],
                ]),
                'template_id' => null,
                'created_by_user_id' => $userId,
            ]);

            $this->seedSiteRequestHistory($ids[$key], $userId, $request['status']);
            $this->seedSiteRequestCalendarEvent($ids[$key], $organizationId, $projectId, $request);
            $this->totals['site_requests']++;
        }

        return $ids;
    }

    private function seedSiteRequestStatuses(int $organizationId): void
    {
        if (!Schema::hasTable('site_request_statuses')) {
            return;
        }

        $statuses = [
            'draft' => ['name' => 'Черновик', 'color' => '#9E9E9E', 'icon' => 'file-alt', 'initial' => true, 'final' => false, 'order' => 1],
            'pending' => ['name' => 'Ожидает обработки', 'color' => '#FF9800', 'icon' => 'clock', 'initial' => false, 'final' => false, 'order' => 2],
            'approved' => ['name' => 'Одобрена', 'color' => '#4CAF50', 'icon' => 'check-circle', 'initial' => false, 'final' => false, 'order' => 3],
            'in_progress' => ['name' => 'В исполнении', 'color' => '#03A9F4', 'icon' => 'spinner', 'initial' => false, 'final' => false, 'order' => 4],
            'fulfilled' => ['name' => 'Выполнена', 'color' => '#8BC34A', 'icon' => 'check-double', 'initial' => false, 'final' => false, 'order' => 5],
            'completed' => ['name' => 'Закрыта', 'color' => '#4CAF50', 'icon' => 'flag-checkered', 'initial' => false, 'final' => true, 'order' => 6],
            'cancelled' => ['name' => 'Отменена', 'color' => '#795548', 'icon' => 'ban', 'initial' => false, 'final' => true, 'order' => 7],
        ];

        $statusIds = [];

        foreach ($statuses as $slug => $status) {
            $statusIds[$slug] = $this->upsert('site_request_statuses', [
                'organization_id' => $organizationId,
                'slug' => $slug,
            ], [
                'name' => $status['name'],
                'description' => 'Демо-статус workflow заявок с объекта',
                'color' => $status['color'],
                'icon' => $status['icon'],
                'is_initial' => $status['initial'],
                'is_final' => $status['final'],
                'display_order' => $status['order'],
            ]);
        }

        if (!Schema::hasTable('site_request_status_transitions')) {
            return;
        }

        $transitions = [
            ['draft', 'pending'],
            ['pending', 'approved'],
            ['approved', 'in_progress'],
            ['in_progress', 'fulfilled'],
            ['fulfilled', 'completed'],
            ['pending', 'cancelled'],
            ['approved', 'cancelled'],
        ];

        foreach ($transitions as [$from, $to]) {
            $this->upsert('site_request_status_transitions', [
                'organization_id' => $organizationId,
                'from_status_id' => $statusIds[$from],
                'to_status_id' => $statusIds[$to],
            ], [
                'required_permission' => 'site-requests.manage',
                'is_active' => true,
            ]);
        }
    }

    private function seedSiteRequestHistory(int $siteRequestId, int $userId, string $finalStatus): void
    {
        if (!Schema::hasTable('site_request_history') || $siteRequestId === 0) {
            return;
        }

        $events = [
            ['action' => 'created', 'old' => null, 'new' => ['status' => 'draft'], 'notes' => 'Заявка создана прорабом', 'days' => 5],
            ['action' => 'status_changed', 'old' => ['status' => 'draft'], 'new' => ['status' => 'pending'], 'notes' => 'Передана в обработку', 'days' => 4],
        ];

        if (in_array($finalStatus, ['approved', 'in_progress', 'fulfilled', 'completed'], true)) {
            $events[] = ['action' => 'status_changed', 'old' => ['status' => 'pending'], 'new' => ['status' => 'approved'], 'notes' => 'Заявка согласована', 'days' => 3];
        }

        if (in_array($finalStatus, ['in_progress', 'fulfilled', 'completed'], true)) {
            $events[] = ['action' => 'status_changed', 'old' => ['status' => 'approved'], 'new' => ['status' => 'in_progress'], 'notes' => 'Исполнение начато', 'days' => 2];
        }

        if (in_array($finalStatus, ['fulfilled', 'completed'], true)) {
            $events[] = ['action' => 'status_changed', 'old' => ['status' => 'in_progress'], 'new' => ['status' => 'fulfilled'], 'notes' => 'Исполнение подтверждено', 'days' => 1];
        }

        if ($finalStatus === 'completed') {
            $events[] = ['action' => 'status_changed', 'old' => ['status' => 'fulfilled'], 'new' => ['status' => 'completed'], 'notes' => 'Заявка закрыта', 'days' => 0];
        }

        foreach ($events as $event) {
            $this->upsert('site_request_history', [
                'site_request_id' => $siteRequestId,
                'action' => $event['action'],
                'created_at' => $this->now->copy()->subDays($event['days']),
            ], [
                'user_id' => $userId,
                'old_value' => $this->json($event['old'] ?? []),
                'new_value' => $this->json($event['new']),
                'notes' => $event['notes'],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $request
     */
    private function seedSiteRequestCalendarEvent(int $siteRequestId, int $organizationId, int $projectId, array $request): void
    {
        if (!Schema::hasTable('site_request_calendar_events') || $siteRequestId === 0) {
            return;
        }

        $eventType = match ($request['type']) {
            'material_request' => 'material_delivery',
            'personnel_request' => 'personnel_work',
            'equipment_request' => 'equipment_rental',
            default => 'deadline',
        };

        $date = $this->now->copy()->addDays($request['required_offset']);

        $this->upsert('site_request_calendar_events', [
            'site_request_id' => $siteRequestId,
            'event_type' => $eventType,
        ], [
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'title' => $request['title'],
            'description' => $request['description'],
            'color' => match ($request['priority']) {
                'urgent' => '#F44336',
                'high' => '#FF5722',
                'medium' => '#FF9800',
                default => '#4CAF50',
            },
            'start_date' => $date->toDateString(),
            'end_date' => in_array($request['type'], ['personnel_request', 'equipment_request'], true)
                ? $date->copy()->addDays(2)->toDateString()
                : $date->toDateString(),
            'start_time' => '08:30:00',
            'end_time' => '12:30:00',
            'all_day' => false,
            'schedule_event_id' => null,
        ]);
    }

    /**
     * @param array<string, int> $siteRequests
     * @param array<string, int> $materials
     * @param array<string, int> $warehouse
     */
    private function seedProjectMaterialDeliveries(
        int $organizationId,
        int $projectId,
        int $userId,
        array $warehouse,
        array $siteRequests,
        array $materials,
        string $scope
    ): void {
        if (!Schema::hasTable('project_material_deliveries') || !isset($warehouse['warehouse_id'])) {
            return;
        }

        $deliveries = [
            'brick_delivery' => ['material' => 'brick', 'status' => 'accepted', 'requested' => $scope === 'GP' ? 6200 : 4200, 'reserved' => $scope === 'GP' ? 6200 : 4200, 'shipped' => $scope === 'GP' ? 6200 : 4200, 'accepted' => $scope === 'GP' ? 6200 : 4200],
            'mortar_today' => ['material' => 'mortar', 'status' => 'in_transit', 'requested' => 8.5, 'reserved' => 8.5, 'shipped' => 8.5, 'accepted' => 0],
        ];

        foreach ($deliveries as $requestKey => $delivery) {
            $siteRequestId = $siteRequests[$requestKey] ?? 0;
            $materialId = $materials[$delivery['material']] ?? 0;

            if ($siteRequestId === 0 || $materialId === 0) {
                continue;
            }

            $deliveryId = $this->upsert('project_material_deliveries', [
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'site_request_id' => $siteRequestId,
                'material_id' => $materialId,
            ], [
                'warehouse_id' => $warehouse['warehouse_id'],
                'warehouse_project_allocation_id' => null,
                'purchase_request_id' => null,
                'purchase_order_id' => null,
                'outbound_movement_id' => null,
                'inbound_movement_id' => null,
                'source_type' => 'warehouse',
                'status' => $delivery['status'],
                'requested_quantity' => $delivery['requested'],
                'reserved_quantity' => $delivery['reserved'],
                'shipped_quantity' => $delivery['shipped'],
                'accepted_quantity' => $delivery['accepted'],
                'planned_delivery_date' => $this->now->copy()->addDays($requestKey === 'brick_delivery' ? -3 : 1)->toDateString(),
                'shipped_at' => $this->now->copy()->subDays($requestKey === 'brick_delivery' ? 4 : 0),
                'delivered_at' => $requestKey === 'brick_delivery' ? $this->now->copy()->subDays(3) : null,
                'accepted_at' => $requestKey === 'brick_delivery' ? $this->now->copy()->subDays(2) : null,
                'responsible_user_id' => $userId,
                'receiver_user_id' => $userId,
                'notes' => 'Демо-доставка материала по заявке с объекта',
                'metadata' => $this->json(['demo' => true, 'scope' => $scope]),
            ]);

            if (Schema::hasTable('project_material_delivery_events')) {
                foreach (['reserved', 'shipped', $delivery['status']] as $index => $status) {
                    $this->upsert('project_material_delivery_events', [
                        'project_material_delivery_id' => $deliveryId,
                        'event_type' => "status_{$status}",
                    ], [
                        'user_id' => $userId,
                        'from_status' => $index === 0 ? 'requested' : null,
                        'to_status' => $status,
                        'quantity' => $status === 'accepted' ? $delivery['accepted'] : $delivery['reserved'],
                        'notes' => 'Демо-событие доставки материала',
                        'metadata' => $this->json(['demo' => true]),
                        'occurred_at' => $this->now->copy()->subDays(max(0, 3 - $index)),
                    ]);
                }
            }
        }
    }

    /**
     * @param array<string, int> $scheduleTasks
     * @param array{id: int, items: array<string, int>, item_details: array<string, array<string, mixed>>} $estimate
     * @param array<string, int> $units
     * @param array<string, int> $workTypes
     * @param array<string, int> $materials
     * @return array{journal_id: int, completed_works: array<int, array<string, mixed>>}
     */
    private function seedConstructionJournal(
        int $organizationId,
        int $projectId,
        int $contractId,
        int $contractorId,
        int $userId,
        array $scheduleTasks,
        array $estimate,
        array $units,
        array $workTypes,
        array $materials,
        string $scope
    ): array {
        if (!Schema::hasTable('construction_journals') || !Schema::hasTable('construction_journal_entries')) {
            return ['journal_id' => 0, 'completed_works' => []];
        }

        $journalId = $this->upsert('construction_journals', [
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'journal_number' => "ОЖР-{$scope}-ЛД-2026",
        ], [
            'contract_id' => $contractId,
            'name' => $scope === 'GP' ? 'Общий журнал работ "Лесной двор"' : 'Журнал подрядчика по кладочным работам',
            'start_date' => $this->now->copy()->subMonths(5)->startOfMonth()->toDateString(),
            'end_date' => null,
            'status' => 'active',
            'created_by_user_id' => $userId,
        ]);

        $entries = [
            [
                'number' => 1,
                'date_offset' => -28,
                'task' => 'foundation',
                'item' => '2.1',
                'work' => 'foundation',
                'material' => 'concrete',
                'quantity' => 54.6,
                'unit' => 'м³',
                'description' => 'Выполнено бетонирование фундаментной плиты, приняты контрольные кубики, вибрирование выполнено по карте.',
                'workers' => ['Бетонщики' => 8, 'Арматурщики' => 4],
                'equipment' => ['Бетононасос' => 1, 'Глубинный вибратор' => 4],
                'status' => 'approved',
            ],
            [
                'number' => 2,
                'date_offset' => -18,
                'task' => 'masonry_outer',
                'item' => '3.1',
                'work' => 'masonry_outer',
                'material' => 'brick',
                'quantity' => 42.2,
                'unit' => 'м³',
                'description' => 'Завершена кладка наружных стен первого этажа по осям А-Е, выполнена перевязка углов и проемов.',
                'workers' => ['Каменщики' => 10, 'Подсобные рабочие' => 4],
                'equipment' => ['Растворосмеситель' => 1, 'Леса фасадные' => 2],
                'status' => 'approved',
            ],
            [
                'number' => 3,
                'date_offset' => -11,
                'task' => 'masonry_inner',
                'item' => '3.3',
                'work' => 'masonry_inner',
                'material' => 'brick',
                'quantity' => 168.0,
                'unit' => 'м²',
                'description' => 'Выполнены внутренние перегородки первого этажа, оставлены технологические проемы под инженерные сети.',
                'workers' => ['Каменщики' => 8, 'Подсобные рабочие' => 3],
                'equipment' => ['Растворосмеситель' => 1],
                'status' => 'approved',
            ],
            [
                'number' => 4,
                'date_offset' => -4,
                'task' => 'belt',
                'item' => '3.5',
                'work' => 'belt',
                'material' => 'rebar',
                'quantity' => 12.4,
                'unit' => 'м³',
                'description' => 'Начато устройство армопояса по наружному контуру, установлены каркасы и опалубка на северном фасаде.',
                'workers' => ['Арматурщики' => 5, 'Плотники' => 3],
                'equipment' => ['Вибратор для бетона' => 2],
                'status' => 'submitted',
            ],
        ];

        $completedWorks = [];

        foreach ($entries as $entry) {
            $scheduleTaskId = $scheduleTasks[$entry['task']] ?? null;
            $estimateItemId = $estimate['items'][$entry['item']] ?? null;
            $workTypeId = $workTypes[$entry['work']] ?? reset($workTypes);
            $materialId = $materials[$entry['material']] ?? null;
            $entryDate = $this->now->copy()->addDays($entry['date_offset']);
            $isApproved = $entry['status'] === 'approved';

            $entryId = $this->upsert('construction_journal_entries', [
                'journal_id' => $journalId,
                'entry_number' => $entry['number'],
            ], [
                'schedule_task_id' => $scheduleTaskId,
                'estimate_id' => $estimate['id'],
                'entry_date' => $entryDate->toDateString(),
                'work_description' => $entry['description'],
                'status' => $entry['status'],
                'created_by_user_id' => $userId,
                'approved_by_user_id' => $isApproved ? $userId : null,
                'approved_at' => $isApproved ? $entryDate->copy()->addHours(7) : null,
                'weather_conditions' => $this->json([
                    'temperature' => $entry['date_offset'] < -10 ? '+12 C' : '+18 C',
                    'precipitation' => 'без осадков',
                    'wind' => '3 м/с',
                ]),
                'problems_description' => $entry['status'] === 'submitted' ? 'Ожидается приемка армирования перед бетонированием.' : null,
                'safety_notes' => 'Инструктаж проведен, замечаний по СИЗ нет.',
                'visitors_notes' => $entry['number'] === 2 ? 'На объекте был представитель заказчика, замечаний к кладке нет.' : null,
                'quality_notes' => 'Геометрия и перевязка проверены прорабом и ПТО.',
                'rejection_reason' => null,
            ]);

            $workVolumeId = $this->upsert('journal_work_volumes', [
                'journal_entry_id' => $entryId,
                'estimate_item_id' => $estimateItemId,
            ], [
                'work_type_id' => $workTypeId,
                'quantity' => $entry['quantity'],
                'measurement_unit_id' => $units[$this->unitKeyByShortName($entry['unit'])] ?? null,
                'notes' => 'Объем из демо-журнала работ',
            ]);

            foreach ($entry['workers'] as $specialty => $count) {
                $this->upsert('journal_workers', [
                    'journal_entry_id' => $entryId,
                    'specialty' => $specialty,
                ], [
                    'estimate_item_id' => $estimateItemId,
                    'workers_count' => $count,
                    'hours_worked' => $count * 8,
                ]);
            }

            foreach ($entry['equipment'] as $equipmentName => $count) {
                $this->upsert('journal_equipment', [
                    'journal_entry_id' => $entryId,
                    'equipment_name' => $equipmentName,
                ], [
                    'estimate_item_id' => $estimateItemId,
                    'equipment_type' => 'construction_equipment',
                    'quantity' => $count,
                    'hours_used' => $count * 8,
                ]);
            }

            if ($materialId) {
                $journalMaterialId = $this->upsert('journal_materials', [
                    'journal_entry_id' => $entryId,
                    'material_name' => $entry['material'] === 'brick' ? 'Кирпич керамический полнотелый М150' : ($entry['material'] === 'concrete' ? 'Бетон B25 П4 F200 W6' : 'Арматура А500С 12 мм'),
                ], [
                    'material_id' => $materialId,
                    'estimate_item_id' => $estimateItemId,
                    'quantity' => $entry['material'] === 'brick' ? $entry['quantity'] * 395 : ($entry['material'] === 'concrete' ? $entry['quantity'] : 1.7),
                    'measurement_unit' => $entry['material'] === 'brick' ? 'шт' : ($entry['material'] === 'concrete' ? 'м³' : 'т'),
                    'notes' => 'Материал списан по демо-журналу',
                ]);
            } else {
                $journalMaterialId = null;
            }

            if ($isApproved) {
                $detail = $estimate['item_details'][$entry['item']] ?? null;
                $unitPrice = $detail ? (float) $detail['unit_price'] : 0.0;
                $amount = round((float) $entry['quantity'] * $unitPrice * 1.14, 2);

                $completedWorkId = $this->upsert('completed_works', [
                    'organization_id' => $organizationId,
                    'project_id' => $projectId,
                    'estimate_item_id' => $estimateItemId,
                    'completion_date' => $entryDate->toDateString(),
                ], [
                    'contract_id' => $contractId,
                    'schedule_task_id' => $scheduleTaskId,
                    'work_type_id' => $workTypeId,
                    'user_id' => $userId,
                    'contractor_id' => $contractorId,
                    'quantity' => $entry['quantity'],
                    'completed_quantity' => $entry['quantity'],
                    'price' => $unitPrice,
                    'total_amount' => $amount,
                    'notes' => $entry['description'],
                    'status' => 'confirmed',
                    'additional_info' => $this->json(['demo' => true, 'journal_entry_number' => $entry['number']]),
                    'journal_entry_id' => $entryId,
                    'journal_work_volume_id' => $workVolumeId,
                    'journal_material_id' => $journalMaterialId,
                    'work_origin_type' => 'journal',
                    'planning_status' => 'actual',
                ]);

                if ($materialId) {
                    $this->upsert('completed_work_materials', [
                        'completed_work_id' => $completedWorkId,
                        'material_id' => $materialId,
                    ], [
                        'quantity' => $entry['material'] === 'brick' ? $entry['quantity'] * 395 : ($entry['material'] === 'concrete' ? $entry['quantity'] : 1.7),
                        'unit_price' => $entry['material'] === 'brick' ? 34.50 : ($entry['material'] === 'concrete' ? 6800.00 : 74200.00),
                        'total_amount' => $entry['material'] === 'brick'
                            ? round($entry['quantity'] * 395 * 34.50, 2)
                            : ($entry['material'] === 'concrete' ? round($entry['quantity'] * 6800.00, 2) : round(1.7 * 74200.00, 2)),
                        'notes' => 'Материал из демо-списания по выполненной работе',
                    ]);
                }

                $completedWorks[] = [
                    'id' => $completedWorkId,
                    'estimate_item_id' => $estimateItemId,
                    'title' => $detail['title'] ?? $entry['description'],
                    'unit' => $entry['unit'],
                    'quantity' => (float) $entry['quantity'],
                    'unit_price' => $unitPrice,
                    'amount' => $amount,
                ];

                $this->totals['completed_works']++;
            }

            $this->totals['journal_entries']++;
        }

        return [
            'journal_id' => $journalId,
            'completed_works' => $completedWorks,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $completedWorks
     */
    private function seedPerformanceAct(
        int $contractId,
        int $projectId,
        int $userId,
        array $completedWorks,
        string $number,
        string $description
    ): int {
        if (!Schema::hasTable('contract_performance_acts') || $completedWorks === []) {
            return 0;
        }

        $amount = round(array_sum(array_map(static fn (array $work): float => (float) $work['amount'], $completedWorks)), 2);

        $actId = $this->upsert('contract_performance_acts', [
            'contract_id' => $contractId,
            'act_document_number' => $number,
        ], [
            'project_id' => $projectId,
            'act_date' => $this->now->copy()->subDays(3)->toDateString(),
            'period_start' => $this->now->copy()->subDays(30)->toDateString(),
            'period_end' => $this->now->copy()->subDays(3)->toDateString(),
            'amount' => $amount,
            'description' => $description,
            'status' => 'approved',
            'is_approved' => true,
            'approval_date' => $this->now->copy()->subDays(2)->toDateString(),
            'created_by_user_id' => $userId,
            'submitted_by_user_id' => $userId,
            'submitted_at' => $this->now->copy()->subDays(3)->addHours(2),
            'approved_by_user_id' => $userId,
            'rejected_by_user_id' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
            'signed_by_user_id' => $userId,
            'signed_at' => $this->now->copy()->subDays(2)->addHours(4),
            'locked_by_user_id' => $userId,
            'locked_at' => $this->now->copy()->subDays(2)->addHours(5),
        ]);

        foreach ($completedWorks as $work) {
            $this->upsert('performance_act_completed_works', [
                'performance_act_id' => $actId,
                'completed_work_id' => $work['id'],
            ], [
                'included_quantity' => $work['quantity'],
                'included_amount' => $work['amount'],
                'notes' => 'Работа включена в демо-акт',
            ]);

            $this->upsert('performance_act_lines', [
                'performance_act_id' => $actId,
                'completed_work_id' => $work['id'],
            ], [
                'estimate_item_id' => $work['estimate_item_id'],
                'line_type' => 'completed_work',
                'title' => $work['title'],
                'unit' => $work['unit'],
                'quantity' => $work['quantity'],
                'unit_price' => $work['unit_price'],
                'amount' => $work['amount'],
                'manual_reason' => null,
                'created_by' => $userId,
            ]);
        }

        $this->totals['performance_acts']++;

        return $actId;
    }

    /**
     * @param array<string, mixed> $general
     * @param array<string, mixed> $contractor
     * @param array<string, int> $contracts
     * @param array<string, int> $contractors
     * @param array<string, int> $estimates
     * @param array<string, int> $acts
     * @param array<string, array<string, int>> $siteRequests
     * @return array<string, int>
     */
    private function seedPaymentDocuments(
        int $projectId,
        array $general,
        array $contractor,
        array $contracts,
        array $contractors,
        array $estimates,
        array $acts,
        array $siteRequests
    ): array {
        if (!Schema::hasTable('payment_documents')) {
            return [];
        }

        $documents = [
            'gp_advance' => [
                'organization_id' => $general['organization_id'],
                'project_id' => $projectId,
                'estimate_id' => $estimates['general'],
                'document_type' => 'payment_order',
                'document_number' => 'ПП-ГП-ЛД-001',
                'document_date' => $this->now->copy()->subMonths(4)->subDays(9)->toDateString(),
                'direction' => 'outgoing',
                'invoice_type' => 'advance',
                'payer_organization_id' => $general['organization_id'],
                'payee_organization_id' => $contractor['organization_id'],
                'payee_contractor_id' => $contractors['contractor_in_general_id'],
                'amount' => 7456000.00,
                'paid_amount' => 7456000.00,
                'status' => 'paid',
                'source_type' => Contract::class,
                'source_id' => $contracts['general_contract_id'],
                'description' => 'Аванс подрядчику по договору кладочных работ',
                'purpose' => 'Аванс 20% по договору ГП-ПДР-ЛД-02/2026',
                'due_offset' => -122,
                'paid_offset' => -121,
                'user_id' => $general['user_id'],
                'site_request_id' => null,
            ],
            'gp_act_payment' => [
                'organization_id' => $general['organization_id'],
                'project_id' => $projectId,
                'estimate_id' => $estimates['general'],
                'document_type' => 'payment_order',
                'document_number' => 'ПП-ГП-ЛД-014',
                'document_date' => $this->now->copy()->subDays(2)->toDateString(),
                'direction' => 'outgoing',
                'invoice_type' => 'act',
                'payer_organization_id' => $general['organization_id'],
                'payee_organization_id' => $contractor['organization_id'],
                'payee_contractor_id' => $contractors['contractor_in_general_id'],
                'amount' => 8620000.00,
                'paid_amount' => 0,
                'status' => 'approved',
                'source_type' => ContractPerformanceAct::class,
                'source_id' => $acts['general'],
                'description' => 'Оплата акта по кладке первого этажа',
                'purpose' => 'Оплата по акту КС-2-КД-ГП-01',
                'due_offset' => 5,
                'paid_offset' => null,
                'user_id' => $general['user_id'],
                'site_request_id' => null,
            ],
            'gp_materials' => [
                'organization_id' => $general['organization_id'],
                'project_id' => $projectId,
                'estimate_id' => $estimates['general'],
                'document_type' => 'expense',
                'document_number' => 'РКО-ГП-ЛД-008',
                'document_date' => $this->now->copy()->subDays(7)->toDateString(),
                'direction' => 'outgoing',
                'invoice_type' => 'material_purchase',
                'payer_organization_id' => $general['organization_id'],
                'payee_organization_id' => null,
                'payee_contractor_id' => null,
                'amount' => 2146000.00,
                'paid_amount' => 2146000.00,
                'status' => 'paid',
                'source_type' => 'project',
                'source_id' => $projectId,
                'description' => 'Поставка кирпича и раствора на объект',
                'purpose' => 'Закупка материалов по заявкам с объекта',
                'due_offset' => -7,
                'paid_offset' => -6,
                'user_id' => $general['user_id'],
                'site_request_id' => $siteRequests['general']['brick_delivery'] ?? null,
            ],
            'sub_advance_income' => [
                'organization_id' => $contractor['organization_id'],
                'project_id' => $projectId,
                'estimate_id' => $estimates['contractor'],
                'document_type' => 'incoming_payment',
                'document_number' => 'ВХ-ПДР-ЛД-001',
                'document_date' => $this->now->copy()->subMonths(4)->subDays(8)->toDateString(),
                'direction' => 'incoming',
                'invoice_type' => 'advance',
                'payer_organization_id' => $general['organization_id'],
                'payee_organization_id' => $contractor['organization_id'],
                'payer_contractor_id' => $contractors['general_in_contractor_id'],
                'amount' => 7456000.00,
                'paid_amount' => 7456000.00,
                'status' => 'paid',
                'source_type' => Contract::class,
                'source_id' => $contracts['contractor_contract_id'],
                'description' => 'Получен аванс от генподрядчика',
                'purpose' => 'Аванс по договору на кладочные работы',
                'due_offset' => -122,
                'paid_offset' => -121,
                'user_id' => $contractor['user_id'],
                'site_request_id' => null,
            ],
            'sub_act_invoice' => [
                'organization_id' => $contractor['organization_id'],
                'project_id' => $projectId,
                'estimate_id' => $estimates['contractor'],
                'document_type' => 'invoice',
                'document_number' => 'СЧ-ПДР-ЛД-006',
                'document_date' => $this->now->copy()->subDays(2)->toDateString(),
                'direction' => 'incoming',
                'invoice_type' => 'act',
                'payer_organization_id' => $general['organization_id'],
                'payee_organization_id' => $contractor['organization_id'],
                'payer_contractor_id' => $contractors['general_in_contractor_id'],
                'amount' => 8620000.00,
                'paid_amount' => 0,
                'status' => 'approved',
                'source_type' => ContractPerformanceAct::class,
                'source_id' => $acts['contractor'],
                'description' => 'Счет подрядчика по акту за кладку первого этажа',
                'purpose' => 'Оплата по акту КС-2-КД-ПДР-01',
                'due_offset' => 5,
                'paid_offset' => null,
                'user_id' => $contractor['user_id'],
                'site_request_id' => null,
            ],
        ];

        $ids = [];

        foreach ($documents as $key => $document) {
            $vatAmount = round($document['amount'] * 20 / 120, 2);
            $paidAmount = (float) $document['paid_amount'];
            $remainingAmount = round((float) $document['amount'] - $paidAmount, 2);
            $paidAt = $document['paid_offset'] === null ? null : $this->now->copy()->addDays($document['paid_offset']);

            $ids[$key] = $this->upsert('payment_documents', [
                'document_number' => $document['document_number'],
            ], [
                'organization_id' => $document['organization_id'],
                'project_id' => $document['project_id'],
                'estimate_id' => $document['estimate_id'],
                'document_type' => $document['document_type'],
                'document_date' => $document['document_date'],
                'direction' => $document['direction'],
                'invoice_type' => $document['invoice_type'],
                'invoiceable_type' => Contract::class,
                'invoiceable_id' => $document['source_id'],
                'payer_organization_id' => $document['payer_organization_id'],
                'payer_contractor_id' => $document['payer_contractor_id'] ?? null,
                'payee_organization_id' => $document['payee_organization_id'],
                'payee_contractor_id' => $document['payee_contractor_id'] ?? null,
                'counterparty_organization_id' => $document['direction'] === 'outgoing' ? $document['payee_organization_id'] : $document['payer_organization_id'],
                'contractor_id' => $document['payee_contractor_id'] ?? ($document['payer_contractor_id'] ?? null),
                'amount' => $document['amount'],
                'currency' => 'RUB',
                'vat_amount' => $vatAmount,
                'vat_rate' => 20,
                'amount_without_vat' => round((float) $document['amount'] - $vatAmount, 2),
                'paid_amount' => $paidAmount,
                'remaining_amount' => $remainingAmount,
                'status' => $document['status'],
                'workflow_stage' => $document['status'] === 'paid' ? 'completed' : 'payment',
                'source_type' => $document['source_type'],
                'source_id' => $document['source_id'],
                'due_date' => $this->now->copy()->addDays($document['due_offset'])->toDateString(),
                'payment_terms_days' => 10,
                'description' => $document['description'],
                'payment_purpose' => $document['purpose'],
                'payment_terms' => 'Оплата по договору демо-проекта "Лесной двор"',
                'attached_documents' => $this->json(['Акт КС-2', 'Счет', 'Реестр заявок']),
                'bank_account' => '40702810900000000001',
                'bank_bik' => '044525001',
                'bank_correspondent_account' => '30101810400000000225',
                'bank_name' => 'АО "Демо Банк"',
                'metadata' => $this->json(['demo' => true, 'scenario' => 'brick_house']),
                'notes' => 'Демо-платеж связан с договором, сметой или заявкой',
                'created_by_user_id' => $document['user_id'],
                'approved_by_user_id' => $document['status'] === 'approved' || $document['status'] === 'paid' ? $document['user_id'] : null,
                'submitted_at' => $this->now->copy()->subDays(3),
                'approved_at' => $document['status'] === 'approved' || $document['status'] === 'paid' ? $this->now->copy()->subDays(2) : null,
                'issued_at' => $this->now->copy()->subDays(3),
                'scheduled_at' => $document['status'] === 'approved' ? $this->now->copy()->subDay() : null,
                'paid_at' => $paidAt,
                'overdue_since' => null,
                'recipient_organization_id' => $document['payee_organization_id'],
                'recipient_notified_at' => $document['payee_organization_id'] ? $this->now->copy()->subDays(2) : null,
                'recipient_viewed_at' => $document['payee_organization_id'] ? $this->now->copy()->subDay() : null,
                'recipient_confirmed_at' => $document['status'] === 'paid' && $document['payee_organization_id'] ? $paidAt : null,
                'recipient_confirmation_comment' => $document['status'] === 'paid' ? 'Поступление подтверждено в демо-сценарии' : null,
                'recipient_confirmed_by_user_id' => $document['status'] === 'paid' ? $document['user_id'] : null,
            ]);

            if ($document['site_request_id']) {
                $this->linkPaymentToSiteRequest($ids[$key], (int) $document['site_request_id'], (float) $document['amount']);
            }

            $this->totals['payment_documents']++;
        }

        return $ids;
    }

    private function linkPaymentToSiteRequest(int $paymentDocumentId, int $siteRequestId, float $amount): void
    {
        if (Schema::hasTable('payment_document_site_requests')) {
            $this->upsert('payment_document_site_requests', [
                'payment_document_id' => $paymentDocumentId,
                'site_request_id' => $siteRequestId,
            ], [
                'amount' => $amount,
            ]);
        }

        if (Schema::hasTable('site_requests') && Schema::hasColumn('site_requests', 'payment_document_id')) {
            DB::table('site_requests')
                ->where('id', $siteRequestId)
                ->update($this->withTimestamps('site_requests', ['payment_document_id' => $paymentDocumentId], true));
        }
    }

    /**
     * @param array<string, mixed> $general
     * @param array<string, mixed> $contractor
     * @param array<string, int> $contracts
     * @param array<string, int> $estimates
     * @param array<string, int> $payments
     */
    private function seedActivityEvents(
        int $projectId,
        array $general,
        array $contractor,
        array $contracts,
        array $estimates,
        array $payments
    ): void {
        if (!Schema::hasTable('activity_events')) {
            return;
        }

        $events = [
            [
                'organization' => $general,
                'module' => 'project-management',
                'event_type' => 'project.created',
                'action' => 'create',
                'subject_type' => 'project',
                'subject_id' => $projectId,
                'subject_label' => 'Кирпичный дом "Лесной двор"',
                'title' => 'Создан проект кирпичного дома',
                'description' => 'Генподрядчик завел объект, сроки, бюджет и участников.',
                'days' => 150,
                'correlation' => 'brick-house-project-created',
            ],
            [
                'organization' => $general,
                'module' => 'contract-management',
                'event_type' => 'contract.signed',
                'action' => 'approve',
                'subject_type' => Contract::class,
                'subject_id' => $contracts['general_contract_id'],
                'subject_label' => 'ГП-ПДР-ЛД-02/2026',
                'title' => 'Подрядчик подключен и договор активирован',
                'description' => 'Карточка подрядчика синхронизирована, договор переведен в работу.',
                'days' => 138,
                'correlation' => 'brick-house-contract-active',
            ],
            [
                'organization' => $contractor,
                'module' => 'budget-estimates',
                'event_type' => 'estimate.approved',
                'action' => 'approve',
                'subject_type' => Estimate::class,
                'subject_id' => $estimates['contractor'],
                'subject_label' => 'КД-ПДР-2026-014',
                'title' => 'Смета подрядчика согласована',
                'description' => 'Подрядчик согласовал ресурсную смету на кладку и армопояса.',
                'days' => 132,
                'correlation' => 'brick-house-sub-estimate-approved',
            ],
            [
                'organization' => $general,
                'module' => 'payments',
                'event_type' => 'payment.paid',
                'action' => 'pay',
                'subject_type' => 'payment_document',
                'subject_id' => $payments['gp_advance'] ?? null,
                'subject_label' => 'ПП-ГП-ЛД-001',
                'title' => 'Аванс подрядчику оплачен',
                'description' => 'Оплачен аванс 20% по договору кладочных работ.',
                'days' => 121,
                'correlation' => 'brick-house-advance-paid',
            ],
            [
                'organization' => $general,
                'module' => 'basic-warehouse',
                'event_type' => 'warehouse.receipt',
                'action' => 'create',
                'subject_type' => 'warehouse_movement',
                'subject_id' => null,
                'subject_label' => 'Поставка кирпича',
                'title' => 'Поступила партия кирпича М150',
                'description' => 'Кладочные материалы приняты на склад объекта и зарезервированы под график.',
                'days' => 9,
                'correlation' => 'brick-house-brick-received',
            ],
            [
                'organization' => $contractor,
                'module' => 'site-requests',
                'event_type' => 'site_request.completed',
                'action' => 'complete',
                'subject_type' => 'site_request',
                'subject_id' => null,
                'subject_label' => 'Проверка геометрии кладки',
                'title' => 'Закрыта заявка по контролю кладки',
                'description' => 'ПТО подтвердило корректировку ряда по оси Д, замечание закрыто.',
                'days' => 1,
                'correlation' => 'brick-house-site-request-completed',
            ],
            [
                'organization' => $general,
                'module' => 'contract-management',
                'event_type' => 'performance_act.approved',
                'action' => 'approve',
                'subject_type' => ContractPerformanceAct::class,
                'subject_id' => null,
                'subject_label' => 'КС-2-КД-ГП-01',
                'title' => 'Подписан акт выполненных работ',
                'description' => 'Приняты фундамент, наружная кладка и перегородки первого этажа.',
                'days' => 2,
                'correlation' => 'brick-house-act-approved',
            ],
        ];

        foreach ($events as $event) {
            $organization = $event['organization'];
            $this->upsert('activity_events', [
                'organization_id' => $organization['organization_id'],
                'correlation_id' => $event['correlation'],
            ], [
                'actor_user_id' => $organization['user_id'],
                'actor_type' => 'user',
                'actor_name' => $organization['name'],
                'actor_email' => $organization['email'],
                'interface' => 'admin',
                'module' => $event['module'],
                'event_type' => $event['event_type'],
                'action' => $event['action'],
                'result' => 'success',
                'severity' => 'info',
                'subject_type' => $event['subject_type'],
                'subject_id' => $event['subject_id'],
                'subject_label' => $event['subject_label'],
                'project_id' => $projectId,
                'target_user_id' => null,
                'title' => $event['title'],
                'description' => $event['description'],
                'changes' => $this->json(['demo' => true]),
                'context' => $this->json([
                    'scenario' => 'brick_house',
                    'project_name' => 'Кирпичный дом "Лесной двор"',
                ]),
                'ip_address' => '127.0.0.1',
                'user_agent' => 'BrickHouseDemoSeeder',
                'occurred_at' => $this->now->copy()->subDays($event['days']),
            ]);

            $this->totals['activity_events']++;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $sections
     * @return array{direct: float, overhead: float, profit: float, total: float, with_vat: float}
     */
    private function calculateEstimateTotals(array $sections): array
    {
        $direct = 0.0;
        $overhead = 0.0;
        $profit = 0.0;
        $total = 0.0;

        foreach ($sections as $section) {
            foreach ($section['items'] as $item) {
                $amounts = $this->calculateItemAmounts((float) $item['quantity'], (float) $item['unit_price']);
                $direct += $amounts['direct'];
                $overhead += $amounts['overhead'];
                $profit += $amounts['profit'];
                $total += $amounts['total'];
            }
        }

        return [
            'direct' => round($direct, 2),
            'overhead' => round($overhead, 2),
            'profit' => round($profit, 2),
            'total' => round($total, 2),
            'with_vat' => round($total * 1.2, 2),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function calculateSectionTotal(array $items): float
    {
        $total = 0.0;

        foreach ($items as $item) {
            $total += $this->calculateItemAmounts((float) $item['quantity'], (float) $item['unit_price'])['total'];
        }

        return round($total, 2);
    }

    /**
     * @return array{direct: float, overhead: float, profit: float, total: float}
     */
    private function calculateItemAmounts(float $quantity, float $unitPrice): array
    {
        $direct = round($quantity * $unitPrice, 2);
        $overhead = round($direct * 0.08, 2);
        $profit = round($direct * 0.06, 2);

        return [
            'direct' => $direct,
            'overhead' => $overhead,
            'profit' => $profit,
            'total' => round($direct + $overhead + $profit, 2),
        ];
    }

    private function unitShortName(string $unitKey): string
    {
        return match ($unitKey) {
            'm3' => 'м³',
            'm2' => 'м²',
            'm' => 'м',
            'pcs' => 'шт',
            'ton' => 'т',
            'pack' => 'упак',
            'hour' => 'чел-ч',
            'shift' => 'смена',
            default => $unitKey,
        };
    }

    private function unitKeyByShortName(string $shortName): string
    {
        return match ($shortName) {
            'м³' => 'm3',
            'м²' => 'm2',
            'м' => 'm',
            'шт' => 'pcs',
            'т' => 'ton',
            'упак' => 'pack',
            'чел-ч' => 'hour',
            'смена' => 'shift',
            default => 'pcs',
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function generalEstimateSections(): array
    {
        return [
            [
                'number' => '1',
                'name' => 'Подготовка и земляные работы',
                'description' => 'Подготовка участка, временные дороги, котлован и основание.',
                'items' => [
                    ['position' => '1.1', 'type' => 'work', 'work' => 'site_preparation', 'unit' => 'm2', 'quantity' => 1860, 'unit_price' => 380, 'name' => 'Подготовка площадки и временных проездов', 'code' => 'ГП-01-001', 'normative' => 'ДЕМО-01-01'],
                    ['position' => '1.2', 'type' => 'work', 'work' => 'earthworks', 'unit' => 'm3', 'quantity' => 690, 'unit_price' => 620, 'name' => 'Разработка котлована под фундамент', 'code' => 'ГП-01-002', 'normative' => 'ДЕМО-01-02'],
                    ['position' => '1.3', 'type' => 'equipment', 'work' => 'earthworks', 'unit' => 'shift', 'quantity' => 12, 'unit_price' => 42000, 'name' => 'Экскаватор и вывоз грунта', 'code' => 'ГП-01-003', 'normative' => 'ДЕМО-01-03'],
                ],
            ],
            [
                'number' => '2',
                'name' => 'Фундамент и подземная часть',
                'description' => 'Монолитная плита, арматура, бетон и гидроизоляция.',
                'items' => [
                    ['position' => '2.1', 'type' => 'work', 'work' => 'foundation', 'material' => 'concrete', 'unit' => 'm3', 'quantity' => 154, 'unit_price' => 13200, 'name' => 'Монолитная фундаментная плита B25', 'code' => 'ГП-02-001', 'normative' => 'ДЕМО-02-01'],
                    ['position' => '2.2', 'type' => 'material', 'material' => 'rebar', 'unit' => 'ton', 'quantity' => 18.4, 'unit_price' => 74200, 'name' => 'Арматура А500С для фундамента', 'code' => 'ГП-02-002', 'normative' => 'ДЕМО-02-02'],
                    ['position' => '2.3', 'type' => 'work', 'work' => 'waterproofing', 'unit' => 'm2', 'quantity' => 512, 'unit_price' => 740, 'name' => 'Гидроизоляция фундаментной плиты', 'code' => 'ГП-02-003', 'normative' => 'ДЕМО-02-03'],
                ],
            ],
            [
                'number' => '3',
                'name' => 'Кирпичные конструкции',
                'description' => 'Несущие стены, перегородки, армирование кладки, перемычки и армопояса.',
                'items' => [
                    ['position' => '3.1', 'type' => 'work', 'work' => 'masonry_outer', 'material' => 'brick', 'unit' => 'm3', 'quantity' => 188, 'unit_price' => 10300, 'name' => 'Кладка наружных стен из кирпича М150', 'code' => 'ГП-03-001', 'normative' => 'ДЕМО-03-01'],
                    ['position' => '3.2', 'type' => 'material', 'material' => 'mortar', 'unit' => 'm3', 'quantity' => 74, 'unit_price' => 5100, 'name' => 'Раствор кладочный М100', 'code' => 'ГП-03-002', 'normative' => 'ДЕМО-03-02'],
                    ['position' => '3.3', 'type' => 'work', 'work' => 'masonry_inner', 'material' => 'brick', 'unit' => 'm2', 'quantity' => 420, 'unit_price' => 1450, 'name' => 'Кладка внутренних перегородок', 'code' => 'ГП-03-003', 'normative' => 'ДЕМО-03-03'],
                    ['position' => '3.4', 'type' => 'material', 'material' => 'mesh', 'unit' => 'm2', 'quantity' => 680, 'unit_price' => 185, 'name' => 'Сетка кладочная для армирования рядов', 'code' => 'ГП-03-004', 'normative' => 'ДЕМО-03-04'],
                    ['position' => '3.5', 'type' => 'work', 'work' => 'belt', 'material' => 'rebar', 'unit' => 'm3', 'quantity' => 42, 'unit_price' => 14800, 'name' => 'Армопояса и монолитные перемычки', 'code' => 'ГП-03-005', 'normative' => 'ДЕМО-03-05'],
                ],
            ],
            [
                'number' => '4',
                'name' => 'Контур, кровля и фасад',
                'description' => 'Плиты, кровля, фасадное утепление, облицовка и инженерные вводы.',
                'items' => [
                    ['position' => '4.1', 'type' => 'work', 'work' => 'slabs', 'material' => 'slab', 'unit' => 'pcs', 'quantity' => 32, 'unit_price' => 23800, 'name' => 'Монтаж плит перекрытия', 'code' => 'ГП-04-001', 'normative' => 'ДЕМО-04-01'],
                    ['position' => '4.2', 'type' => 'equipment', 'work' => 'slabs', 'unit' => 'shift', 'quantity' => 8, 'unit_price' => 52000, 'name' => 'Автокран 25 т для монтажа плит', 'code' => 'ГП-04-002', 'normative' => 'ДЕМО-04-02'],
                    ['position' => '4.3', 'type' => 'work', 'work' => 'roof', 'material' => 'roofing', 'unit' => 'm2', 'quantity' => 318, 'unit_price' => 2100, 'name' => 'Устройство скатной кровли с металлочерепицей', 'code' => 'ГП-04-003', 'normative' => 'ДЕМО-04-03'],
                    ['position' => '4.4', 'type' => 'work', 'work' => 'facade', 'material' => 'insulation', 'unit' => 'm2', 'quantity' => 620, 'unit_price' => 2350, 'name' => 'Утепление и облицовка фасада кирпичом', 'code' => 'ГП-04-004', 'normative' => 'ДЕМО-04-04'],
                    ['position' => '4.5', 'type' => 'work', 'work' => 'engineering', 'unit' => 'm', 'quantity' => 146, 'unit_price' => 1900, 'name' => 'Инженерные вводы и закладные', 'code' => 'ГП-04-005', 'normative' => 'ДЕМО-04-05'],
                    ['position' => '4.6', 'type' => 'work', 'work' => 'quality', 'unit' => 'pcs', 'quantity' => 18, 'unit_price' => 12500, 'name' => 'Исполнительная документация и контроль качества', 'code' => 'ГП-04-006', 'normative' => 'ДЕМО-04-06'],
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function contractorEstimateSections(): array
    {
        return [
            [
                'number' => '1',
                'name' => 'Кладка и армирование первого этажа',
                'description' => 'Наружные стены, перегородки, сетка, раствор и перемычки первого этажа.',
                'items' => [
                    ['position' => '3.1', 'type' => 'work', 'work' => 'masonry_outer', 'material' => 'brick', 'unit' => 'm3', 'quantity' => 96, 'unit_price' => 9800, 'name' => 'Кладка наружных стен первого этажа', 'code' => 'ПДР-01-001', 'normative' => 'ДЕМО-ПДР-01'],
                    ['position' => '3.2', 'type' => 'material', 'material' => 'mortar', 'unit' => 'm3', 'quantity' => 38, 'unit_price' => 5100, 'name' => 'Раствор кладочный М100 для первого этажа', 'code' => 'ПДР-01-002', 'normative' => 'ДЕМО-ПДР-02'],
                    ['position' => '3.3', 'type' => 'work', 'work' => 'masonry_inner', 'material' => 'brick', 'unit' => 'm2', 'quantity' => 236, 'unit_price' => 1420, 'name' => 'Кладка внутренних перегородок первого этажа', 'code' => 'ПДР-01-003', 'normative' => 'ДЕМО-ПДР-03'],
                    ['position' => '3.4', 'type' => 'material', 'material' => 'mesh', 'unit' => 'm2', 'quantity' => 360, 'unit_price' => 185, 'name' => 'Сетка кладочная первого этажа', 'code' => 'ПДР-01-004', 'normative' => 'ДЕМО-ПДР-04'],
                    ['position' => '3.5', 'type' => 'work', 'work' => 'belt', 'material' => 'rebar', 'unit' => 'm3', 'quantity' => 19, 'unit_price' => 14600, 'name' => 'Армопояса и перемычки первого этажа', 'code' => 'ПДР-01-005', 'normative' => 'ДЕМО-ПДР-05'],
                ],
            ],
            [
                'number' => '2',
                'name' => 'Кладка второго этажа',
                'description' => 'Второй этаж, подготовка под перекрытия и контроль качества.',
                'items' => [
                    ['position' => '4.1', 'type' => 'work', 'work' => 'masonry_outer', 'material' => 'brick', 'unit' => 'm3', 'quantity' => 92, 'unit_price' => 10100, 'name' => 'Кладка наружных стен второго этажа', 'code' => 'ПДР-02-001', 'normative' => 'ДЕМО-ПДР-06'],
                    ['position' => '4.2', 'type' => 'equipment', 'work' => 'slabs', 'unit' => 'shift', 'quantity' => 5, 'unit_price' => 52000, 'name' => 'Автокран для перемычек и разгрузки плит', 'code' => 'ПДР-02-002', 'normative' => 'ДЕМО-ПДР-07'],
                    ['position' => '4.3', 'type' => 'work', 'work' => 'quality', 'unit' => 'pcs', 'quantity' => 10, 'unit_price' => 9500, 'name' => 'Исполнительная съемка кладки', 'code' => 'ПДР-02-003', 'normative' => 'ДЕМО-ПДР-08'],
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function generalScheduleTasks(): array
    {
        return [
            ['key' => 'site_preparation', 'wbs' => '1.1', 'name' => 'Подготовка площадки', 'description' => 'Ограждение, временные дороги, бытовой городок.', 'phase' => 'подготовка', 'work' => 'site_preparation', 'start_offset' => -150, 'duration' => 18, 'progress' => 100, 'status' => 'completed', 'priority' => 'high', 'critical' => true, 'cost' => 1300000, 'resources' => ['people' => 8], 'material_resources' => [], 'equipment_resources' => [['role' => 'Погрузчик', 'name' => 'Фронтальный погрузчик', 'units' => 1, 'hours' => 96, 'rate' => 2500]]],
            ['key' => 'earthworks', 'wbs' => '1.2', 'name' => 'Котлован и основание', 'description' => 'Разработка котлована, песчаная подготовка, геодезия.', 'phase' => 'подготовка', 'work' => 'earthworks', 'start_offset' => -132, 'duration' => 24, 'progress' => 100, 'status' => 'completed', 'priority' => 'high', 'critical' => true, 'cost' => 1850000, 'resources' => ['people' => 7], 'material_resources' => [], 'equipment_resources' => [['role' => 'Экскаватор', 'name' => 'Экскаватор 20 т', 'units' => 1, 'hours' => 144, 'rate' => 3200]]],
            ['key' => 'foundation', 'wbs' => '2.1', 'name' => 'Монолитный фундамент', 'description' => 'Арматура, опалубка, бетон, уход за бетоном.', 'phase' => 'фундамент', 'work' => 'foundation', 'start_offset' => -108, 'duration' => 38, 'progress' => 100, 'status' => 'completed', 'priority' => 'critical', 'critical' => true, 'cost' => 14600000, 'resources' => ['people' => 18], 'material_resources' => [['role' => 'Бетон B25', 'material' => 'concrete', 'quantity' => 154, 'unit_price' => 6800], ['role' => 'Арматура', 'material' => 'rebar', 'quantity' => 18.4, 'unit_price' => 74200]], 'equipment_resources' => [['role' => 'Бетононасос', 'name' => 'Бетононасос 42 м', 'units' => 1, 'hours' => 64, 'rate' => 4200]]],
            ['key' => 'waterproofing', 'wbs' => '2.2', 'name' => 'Гидроизоляция и обратная засыпка', 'description' => 'Гидроизоляция плиты, дренаж, обратная засыпка.', 'phase' => 'фундамент', 'work' => 'waterproofing', 'start_offset' => -70, 'duration' => 16, 'progress' => 100, 'status' => 'completed', 'priority' => 'high', 'critical' => true, 'cost' => 3100000, 'resources' => ['people' => 8], 'material_resources' => [], 'equipment_resources' => []],
            ['key' => 'masonry_outer', 'wbs' => '3.1', 'name' => 'Наружные кирпичные стены', 'description' => 'Кладка первого и второго этажей с армированием рядов.', 'phase' => 'коробка', 'work' => 'masonry_outer', 'start_offset' => -54, 'duration' => 62, 'progress' => 64, 'status' => 'in_progress', 'priority' => 'critical', 'critical' => true, 'cost' => 18600000, 'resources' => ['people' => 16], 'material_resources' => [['role' => 'Кирпич М150', 'material' => 'brick', 'quantity' => 74260, 'unit_price' => 34.5], ['role' => 'Раствор М100', 'material' => 'mortar', 'quantity' => 74, 'unit_price' => 5100], ['role' => 'Сетка кладочная', 'material' => 'mesh', 'quantity' => 680, 'unit_price' => 185]], 'equipment_resources' => [['role' => 'Леса', 'name' => 'Фасадные леса', 'units' => 2, 'hours' => 360, 'rate' => 420]]],
            ['key' => 'masonry_inner', 'wbs' => '3.2', 'name' => 'Внутренние перегородки', 'description' => 'Перегородки первого этажа завершены, второй этаж в работе.', 'phase' => 'коробка', 'work' => 'masonry_inner', 'start_offset' => -32, 'duration' => 44, 'progress' => 48, 'status' => 'in_progress', 'priority' => 'high', 'critical' => false, 'cost' => 4200000, 'resources' => ['people' => 10], 'material_resources' => [['role' => 'Кирпич М150', 'material' => 'brick', 'quantity' => 31800, 'unit_price' => 34.5]], 'equipment_resources' => []],
            ['key' => 'belt', 'wbs' => '3.3', 'name' => 'Армопояса и перемычки', 'description' => 'Армопояса первого этажа, перемычки проемов.', 'phase' => 'коробка', 'work' => 'belt', 'start_offset' => -6, 'duration' => 26, 'progress' => 35, 'status' => 'in_progress', 'priority' => 'critical', 'critical' => true, 'cost' => 5200000, 'resources' => ['people' => 12], 'material_resources' => [['role' => 'Арматура', 'material' => 'rebar', 'quantity' => 4.8, 'unit_price' => 74200]], 'equipment_resources' => [['role' => 'Вибратор', 'name' => 'Вибратор для бетона', 'units' => 2, 'hours' => 72, 'rate' => 300]]],
            ['key' => 'slabs', 'wbs' => '4.1', 'name' => 'Плиты перекрытия', 'description' => 'Разгрузка и монтаж плит перекрытия.', 'phase' => 'контур', 'work' => 'slabs', 'start_offset' => 18, 'duration' => 12, 'progress' => 0, 'status' => 'waiting', 'priority' => 'high', 'critical' => true, 'cost' => 3900000, 'resources' => ['people' => 8], 'material_resources' => [['role' => 'Плиты ПБ', 'material' => 'slab', 'quantity' => 32, 'unit_price' => 18600]], 'equipment_resources' => [['role' => 'Автокран', 'name' => 'Автокран 25 т', 'units' => 1, 'hours' => 80, 'rate' => 5200]]],
            ['key' => 'roof', 'wbs' => '4.2', 'name' => 'Кровля', 'description' => 'Стропильная система, утепление и металлочерепица.', 'phase' => 'контур', 'work' => 'roof', 'start_offset' => 34, 'duration' => 34, 'progress' => 0, 'status' => 'not_started', 'priority' => 'normal', 'critical' => true, 'cost' => 6800000, 'resources' => ['people' => 9], 'material_resources' => [['role' => 'Металлочерепица', 'material' => 'roofing', 'quantity' => 318, 'unit_price' => 920]], 'equipment_resources' => []],
            ['key' => 'facade', 'wbs' => '5.1', 'name' => 'Фасад и утепление', 'description' => 'Минвата, облицовочный кирпич, расшивка швов.', 'phase' => 'фасад', 'work' => 'facade', 'start_offset' => 72, 'duration' => 48, 'progress' => 0, 'status' => 'not_started', 'priority' => 'normal', 'critical' => false, 'cost' => 11800000, 'resources' => ['people' => 14], 'material_resources' => [['role' => 'Минвата', 'material' => 'insulation', 'quantity' => 96, 'unit_price' => 1520], ['role' => 'Облицовочный кирпич', 'material' => 'facing_brick', 'quantity' => 28400, 'unit_price' => 52]], 'equipment_resources' => []],
            ['key' => 'engineering', 'wbs' => '6.1', 'name' => 'Инженерные вводы', 'description' => 'Закладные, проходки, вводы воды, канализации и электрики.', 'phase' => 'инженерия', 'work' => 'engineering', 'start_offset' => 54, 'duration' => 32, 'progress' => 12, 'status' => 'waiting', 'priority' => 'normal', 'critical' => false, 'cost' => 3900000, 'resources' => ['people' => 6], 'material_resources' => [], 'equipment_resources' => []],
            ['key' => 'quality', 'wbs' => '7.1', 'name' => 'Исполнительная документация', 'description' => 'Схемы, акты скрытых работ, фотофиксация и отчеты.', 'phase' => 'ПТО', 'work' => 'quality', 'start_offset' => -50, 'duration' => 196, 'progress' => 41, 'status' => 'in_progress', 'priority' => 'normal', 'critical' => false, 'cost' => 1600000, 'resources' => ['people' => 3], 'material_resources' => [], 'equipment_resources' => []],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function contractorScheduleTasks(): array
    {
        return [
            ['key' => 'masonry_outer', 'wbs' => '1.1', 'name' => 'Наружные стены первого этажа', 'description' => 'Кладка наружных стен, контроль геометрии, армирование рядов.', 'phase' => 'кладка', 'work' => 'masonry_outer', 'start_offset' => -54, 'duration' => 30, 'progress' => 100, 'status' => 'completed', 'priority' => 'critical', 'critical' => true, 'cost' => 9200000, 'resources' => ['people' => 14], 'material_resources' => [['role' => 'Кирпич М150', 'material' => 'brick', 'quantity' => 37920, 'unit_price' => 34.5], ['role' => 'Раствор М100', 'material' => 'mortar', 'quantity' => 38, 'unit_price' => 5100]], 'equipment_resources' => []],
            ['key' => 'masonry_inner', 'wbs' => '1.2', 'name' => 'Перегородки первого этажа', 'description' => 'Внутренние перегородки и проемы под инженерные коммуникации.', 'phase' => 'кладка', 'work' => 'masonry_inner', 'start_offset' => -28, 'duration' => 22, 'progress' => 100, 'status' => 'completed', 'priority' => 'high', 'critical' => false, 'cost' => 3600000, 'resources' => ['people' => 9], 'material_resources' => [['role' => 'Кирпич М150', 'material' => 'brick', 'quantity' => 18200, 'unit_price' => 34.5]], 'equipment_resources' => []],
            ['key' => 'belt', 'wbs' => '1.3', 'name' => 'Армопояс первого этажа', 'description' => 'Каркасы, опалубка, бетонирование армопояса.', 'phase' => 'железобетон', 'work' => 'belt', 'start_offset' => -6, 'duration' => 18, 'progress' => 45, 'status' => 'in_progress', 'priority' => 'critical', 'critical' => true, 'cost' => 5100000, 'resources' => ['people' => 10], 'material_resources' => [['role' => 'Арматура', 'material' => 'rebar', 'quantity' => 2.8, 'unit_price' => 74200], ['role' => 'Сетка', 'material' => 'mesh', 'quantity' => 140, 'unit_price' => 185]], 'equipment_resources' => [['role' => 'Вибратор', 'name' => 'Вибратор для бетона', 'units' => 2, 'hours' => 48, 'rate' => 300]]],
            ['key' => 'slabs', 'wbs' => '1.4', 'name' => 'Подготовка к плитам перекрытия', 'description' => 'Разметка опорных зон, подача перемычек, координация автокрана.', 'phase' => 'перекрытия', 'work' => 'slabs', 'start_offset' => 18, 'duration' => 10, 'progress' => 0, 'status' => 'waiting', 'priority' => 'high', 'critical' => true, 'cost' => 2600000, 'resources' => ['people' => 8], 'material_resources' => [], 'equipment_resources' => [['role' => 'Автокран', 'name' => 'Автокран 25 т', 'units' => 1, 'hours' => 40, 'rate' => 5200]]],
            ['key' => 'quality', 'wbs' => '1.5', 'name' => 'Исполнительная документация кладки', 'description' => 'Фотофиксация, схемы, акты скрытых работ по армированию.', 'phase' => 'ПТО', 'work' => 'quality', 'start_offset' => -54, 'duration' => 82, 'progress' => 52, 'status' => 'in_progress', 'priority' => 'normal', 'critical' => false, 'cost' => 840000, 'resources' => ['people' => 2], 'material_resources' => [], 'equipment_resources' => []],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function generalContractorAccount(): array
    {
        return [
            'contour' => 'general_contractor',
            'email' => 'demo.general-contractor@prohelper.test',
            'name' => 'Алексей Мельников',
            'position' => 'Руководитель проекта генподряда',
            'organization_name' => 'Демо Генподряд "Кирпичный квартал"',
            'legal_name' => 'ООО "Кирпичный квартал Генподряд"',
            'tax_number' => '7701000001',
            'registration_number' => '1027701000001',
            'organization_email' => 'office.gp@prohelper.test',
            'phone' => '+7 495 210-44-01',
            'user_phone' => '+7 916 210-44-01',
            'address' => 'Москва, ул. Строителей, 22',
            'postal_code' => '101000',
            'description' => 'Генподрядная организация демо-сценария: управляет проектом, договорами, сметами, графиком, складом и приемкой работ.',
            'capabilities' => [
                OrganizationCapability::GENERAL_CONTRACTING->value,
                OrganizationCapability::SUBCONTRACTING->value,
                OrganizationCapability::MATERIALS_SUPPLY->value,
            ],
            'primary_business_type' => OrganizationCapability::GENERAL_CONTRACTING->value,
            'specializations' => ['Генеральный подряд', 'Управление строительством', 'Склад и снабжение', 'ПТО'],
            'certifications' => ['СРО строительство', 'ISO 9001', 'Допуск к организации строительства'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function contractorAccount(): array
    {
        return [
            'contour' => 'contractor',
            'email' => 'demo.contractor@prohelper.test',
            'name' => 'Ирина Соколова',
            'position' => 'Директор подрядной организации',
            'organization_name' => 'Демо Подряд "МастерКлад"',
            'legal_name' => 'ООО "МастерКлад Подряд"',
            'tax_number' => '7701000002',
            'registration_number' => '1027701000002',
            'organization_email' => 'office.sub@prohelper.test',
            'phone' => '+7 495 210-44-02',
            'user_phone' => '+7 916 210-44-02',
            'address' => 'Москва, ул. Каменщиков, 14',
            'postal_code' => '101000',
            'description' => 'Подрядная организация демо-сценария: выполняет кладочные работы, ведет журнал, заявки, склад и акты.',
            'capabilities' => [
                OrganizationCapability::SUBCONTRACTING->value,
                OrganizationCapability::GENERAL_CONTRACTING->value,
            ],
            'primary_business_type' => OrganizationCapability::SUBCONTRACTING->value,
            'specializations' => ['Кирпичная кладка', 'Армопояса и перемычки', 'Исполнительная документация'],
            'certifications' => ['СРО строительство', 'НАКС для ответственных специалистов'],
        ];
    }

    private function upsert(string $table, array $keys, array $values): int
    {
        if (!Schema::hasTable($table)) {
            return 0;
        }

        $keys = $this->filterColumns($table, $keys);
        $values = $this->filterColumns($table, $values);

        if ($keys === []) {
            throw new RuntimeException(sprintf('No usable upsert keys for table [%s].', $table));
        }

        $exists = DB::table($table)->where($keys)->exists();
        $values = $this->withTimestamps($table, $values, $exists);

        DB::table($table)->updateOrInsert($keys, $values);

        $id = DB::table($table)->where($keys)->value('id');

        return $id === null ? 0 : (int) $id;
    }

    private function filterColumns(string $table, array $values): array
    {
        return array_filter(
            $values,
            static fn (string $column): bool => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_KEY
        );
    }

    private function withTimestamps(string $table, array $values, bool $exists): array
    {
        if (!$exists && Schema::hasColumn($table, 'created_at') && !array_key_exists('created_at', $values)) {
            $values['created_at'] = $this->now;
        }

        if (Schema::hasColumn($table, 'updated_at')) {
            $values['updated_at'] = $this->now;
        }

        return $values;
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
