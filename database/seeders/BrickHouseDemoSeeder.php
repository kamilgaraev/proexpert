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
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class BrickHouseDemoSeeder extends Seeder
{
    private const PASSWORD = 'MostDemo123!';

    private Carbon $now;

    /**
     * @var array<string, int>
     */
    private array $totals = [
        'accounts' => 0,
        'users' => 0,
        'custom_roles' => 0,
        'commercial_packages' => 0,
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
        'workforce_records' => 0,
        'safety_records' => 0,
        'quality_defects' => 0,
        'suppliers' => 0,
        'purchase_requests' => 0,
        'supplier_proposals' => 0,
        'purchase_orders' => 0,
        'purchase_receipts' => 0,
        'budget_periods' => 0,
        'budget_versions' => 0,
        'budget_lines' => 0,
        'budget_amounts' => 0,
    ];

    public function run(): void
    {
        $this->now = now();
        $this->assertRequiredTables();

        $result = DB::transaction(function (): array {
            $general = $this->seedAccount($this->generalContractorAccount());
            $contractor = $this->seedAccount($this->contractorAccount());

            foreach ([$general, $contractor] as $account) {
                $this->seedCommercialAccess($account['organization_id'], $account['user_id']);
                $this->activateModules($account['organization_id']);
                $this->seedSiteRequestStatuses($account['organization_id']);
            }

            $general['team'] = $this->seedDemoTeam($general, 'GP');
            $contractor['team'] = $this->seedDemoTeam($contractor, 'SUB');

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
                userId: $this->actorId($general, 'pto_engineer'),
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
                userId: $this->actorId($contractor, 'pto_engineer'),
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
                createdByUserId: $this->actorId($general, 'project_manager'),
                assignedUserId: $this->actorId($general, 'foreman'),
                units: $generalUnits,
                workTypes: $generalWorkTypes,
                materials: $generalMaterials,
                scope: 'GP'
            );

            $contractorScheduleTasks = $this->seedSchedule(
                organizationId: $contractor['organization_id'],
                projectId: $projectId,
                createdByUserId: $this->actorId($contractor, 'work_manager'),
                assignedUserId: $this->actorId($contractor, 'foreman'),
                units: $contractorUnits,
                workTypes: $contractorWorkTypes,
                materials: $contractorMaterials,
                scope: 'SUB'
            );

            $generalWarehouse = $this->seedWarehouse(
                organizationId: $general['organization_id'],
                userId: $this->actorId($general, 'supply_manager'),
                projectId: $projectId,
                materials: $generalMaterials,
                scope: 'GP'
            );

            $contractorWarehouse = $this->seedWarehouse(
                organizationId: $contractor['organization_id'],
                userId: $this->actorId($contractor, 'storekeeper'),
                projectId: $projectId,
                materials: $contractorMaterials,
                scope: 'SUB'
            );

            $generalRequests = $this->seedSiteRequests(
                organizationId: $general['organization_id'],
                projectId: $projectId,
                userId: $this->actorId($general, 'foreman'),
                assignedUserId: $this->actorId($general, 'supply_manager'),
                materials: $generalMaterials,
                estimateItems: $generalEstimate['items'],
                scope: 'GP'
            );

            $contractorRequests = $this->seedSiteRequests(
                organizationId: $contractor['organization_id'],
                projectId: $projectId,
                userId: $this->actorId($contractor, 'foreman'),
                assignedUserId: $this->actorId($contractor, 'storekeeper'),
                materials: $contractorMaterials,
                estimateItems: $contractorEstimate['items'],
                scope: 'SUB'
            );

            $this->seedProjectMaterialDeliveries(
                organizationId: $general['organization_id'],
                projectId: $projectId,
                userId: $this->actorId($general, 'supply_manager'),
                warehouse: $generalWarehouse,
                siteRequests: $generalRequests,
                materials: $generalMaterials,
                scope: 'GP'
            );

            $this->seedProjectMaterialDeliveries(
                organizationId: $contractor['organization_id'],
                projectId: $projectId,
                userId: $this->actorId($contractor, 'storekeeper'),
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
                createdByUserId: $this->actorId($general, 'foreman'),
                approvedByUserId: $this->actorId($general, 'pto_engineer'),
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
                createdByUserId: $this->actorId($contractor, 'foreman'),
                approvedByUserId: $this->actorId($contractor, 'pto_engineer'),
                scheduleTasks: $contractorScheduleTasks,
                estimate: $contractorEstimate,
                units: $contractorUnits,
                workTypes: $contractorWorkTypes,
                materials: $contractorMaterials,
                scope: 'SUB'
            );

            $this->seedWorkforceDemo(
                projectId: $projectId,
                general: $general,
                contractor: $contractor
            );

            $this->seedSafetyDemo(
                projectId: $projectId,
                general: $general,
                contractor: $contractor
            );

            $this->seedQualityDemo(
                projectId: $projectId,
                general: $general,
                contractor: $contractor,
                contractors: $contractors,
                scheduleTasks: [
                    'general' => $generalScheduleTasks,
                    'contractor' => $contractorScheduleTasks,
                ],
                journals: [
                    'general' => $generalJournal,
                    'contractor' => $contractorJournal,
                ]
            );

            $acts = [
                'general' => $this->seedPerformanceAct(
                    contractId: $contracts['general_contract_id'],
                    projectId: $projectId,
                    createdByUserId: $this->actorId($general, 'pto_engineer'),
                    approvedByUserId: $this->actorId($general, 'project_manager'),
                    completedWorks: $generalJournal['completed_works'],
                    number: 'КС-2-КД-ГП-01',
                    description: 'Акт приемки выполненных работ по фундаменту и кирпичной кладке первого этажа'
                ),
                'contractor' => $this->seedPerformanceAct(
                    contractId: $contracts['contractor_contract_id'],
                    projectId: $projectId,
                    createdByUserId: $this->actorId($contractor, 'pto_engineer'),
                    approvedByUserId: $this->actorId($contractor, 'work_manager'),
                    completedWorks: $contractorJournal['completed_works'],
                    number: 'КС-2-КД-ПДР-01',
                    description: 'Акт подрядчика по кладке наружных и внутренних стен первого этажа'
                ),
            ];

            $procurement = $this->seedProcurement(
                projectId: $projectId,
                general: $general,
                contractor: $contractor,
                materials: [
                    'general' => $generalMaterials,
                    'contractor' => $contractorMaterials,
                ],
                warehouses: [
                    'general' => $generalWarehouse,
                    'contractor' => $contractorWarehouse,
                ],
                siteRequests: [
                    'general' => $generalRequests,
                    'contractor' => $contractorRequests,
                ]
            );

            $budgeting = $this->seedBudgetingDemo(
                projectId: $projectId,
                general: $general,
                contractor: $contractor,
                contracts: $contracts,
                contractors: $contractors
            );

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
                ],
                procurement: $procurement,
                budgeting: $budgeting
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
            'authorization_contexts',
            'organization_custom_roles',
            'user_role_assignments',
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
            'organization_commercial_accounts',
            'organization_package_subscriptions',
            'budget_periods',
            'budget_scenarios',
            'responsibility_centers',
            'budget_articles',
            'budget_versions',
            'budget_lines',
            'budget_amounts',
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

        $requiredColumns = [
            'payment_documents' => [
                'budget_article_id',
                'responsibility_center_id',
            ],
        ];
        $missingColumns = [];
        foreach ($requiredColumns as $table => $columns) {
            foreach ($columns as $column) {
                if (!Schema::hasColumn($table, $column)) {
                    $missingColumns[] = "{$table}.{$column}";
                }
            }
        }

        if ($missingColumns !== []) {
            throw new RuntimeException(
                'BrickHouseDemoSeeder требует актуальные миграции бюджетирования. Не найдены колонки: '
                . implode(', ', $missingColumns)
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

    private function seedCommercialAccess(int $organizationId, int $responsibleUserId): void
    {
        $periodEnd = $this->now->copy()->addYear();
        $accountId = $this->upsert('organization_commercial_accounts', [
            'organization_id' => $organizationId,
        ], [
            'responsible_user_id' => $responsibleUserId,
            'status' => 'active',
            'offer_type' => 'full_suite',
            'quote_version' => 1,
            'billing_anchor_at' => $this->now,
            'current_period_start_at' => $this->now,
            'current_period_end_at' => $periodEnd,
            'auto_renew_enabled' => false,
            'saved_payment_method_id' => null,
            'saved_payment_method_at' => null,
            'saved_payment_method_active' => false,
            'auto_renew_consented_at' => null,
            'auto_renew_terms_version' => null,
            'grace_started_at' => null,
            'grace_ends_at' => null,
        ]);

        foreach ($this->commercialPackageSlugs() as $packageSlug) {
            $this->upsert('organization_package_subscriptions', [
                'organization_id' => $organizationId,
                'package_slug' => $packageSlug,
            ], [
                'commercial_account_id' => $accountId,
                'status' => 'active',
                'access_source' => 'full_suite',
                'current_period_start_at' => $this->now,
                'current_period_end_at' => $periodEnd,
                'trial_started_at' => null,
                'trial_ends_at' => null,
                'cancel_at' => null,
                'canceled_at' => null,
                'price_paid' => 0,
            ]);
            $this->totals['commercial_packages']++;
        }
    }

    /** @return array<int, string> */
    private function commercialPackageSlugs(): array
    {
        return [
            'projects-processes', 'planning-schedules', 'estimates-norms', 'quality-safety', 'pto-handover',
            'supply-warehouse', 'finance-contracts', 'workforce-output', 'machinery', 'sales-contractors',
        ];
    }

    /**
     * @param array<string, mixed> $account
     * @return array<string, array<string, mixed>>
     */
    private function seedDemoTeam(array $account, string $scope): array
    {
        $members = $scope === 'GP'
            ? $this->generalTeamMembers()
            : $this->contractorTeamMembers();

        $team = [];

        foreach ($members as $member) {
            $roleSlug = $member['role_slug'];

            $this->seedCustomRole(
                organizationId: $account['organization_id'],
                createdByUserId: $account['user_id'],
                roleSlug: $roleSlug,
                name: $member['role_name'],
                description: $member['role_description'],
                systemPermissions: $member['system_permissions'],
                modulePermissions: $member['module_permissions'],
                interfaceAccess: $member['interface_access'],
                conditions: $member['conditions'] ?? null
            );

            $userId = $this->seedTeamUser($account, $member);
            $this->assignCustomOrganizationRole(
                userId: $userId,
                organizationId: $account['organization_id'],
                roleSlug: $roleSlug,
                assignedByUserId: $account['user_id']
            );

            foreach (($member['system_role_slugs'] ?? []) as $systemRoleSlug) {
                $this->assignOrganizationRole(
                    userId: $userId,
                    organizationId: $account['organization_id'],
                    roleSlug: $systemRoleSlug,
                    assignedBy: $account['user_id']
                );
            }

            $team[$member['key']] = [
                'user_id' => $userId,
                'name' => $member['name'],
                'email' => $member['email'],
                'role_slug' => $roleSlug,
                'project_role' => $member['project_role'],
            ];
        }

        return $team;
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, mixed> $member
     */
    private function seedTeamUser(array $account, array $member): int
    {
        $userId = $this->upsert('users', [
            'email' => $member['email'],
        ], [
            'name' => $member['name'],
            'email' => $member['email'],
            'email_verified_at' => $this->now,
            'password' => Hash::make(self::PASSWORD),
            'phone' => $member['phone'],
            'position' => $member['position'],
            'avatar_path' => null,
            'is_active' => true,
            'current_organization_id' => $account['organization_id'],
            'settings' => $this->json([
                'demo_account' => true,
                'scenario' => 'brick_house',
                'role_key' => $member['key'],
                'role_slug' => $member['role_slug'],
            ]),
            'has_completed_onboarding' => true,
        ]);

        $this->upsert('organization_user', [
            'organization_id' => $account['organization_id'],
            'user_id' => $userId,
        ], [
            'is_owner' => false,
            'is_active' => true,
            'settings' => $this->json([
                'demo_account' => true,
                'scenario' => 'brick_house',
                'role_key' => $member['key'],
                'role_slug' => $member['role_slug'],
            ]),
            'project_access_mode' => $member['project_access_mode'],
        ]);

        $this->totals['users']++;

        return $userId;
    }

    private function seedCustomRole(
        int $organizationId,
        int $createdByUserId,
        string $roleSlug,
        string $name,
        string $description,
        array $systemPermissions,
        array $modulePermissions,
        array $interfaceAccess,
        ?array $conditions
    ): void {
        if (!Schema::hasTable('organization_custom_roles')) {
            return;
        }

        $this->upsert('organization_custom_roles', [
            'organization_id' => $organizationId,
            'slug' => $roleSlug,
        ], [
            'name' => $name,
            'description' => $description,
            'system_permissions' => $this->json($systemPermissions),
            'module_permissions' => $this->json($modulePermissions),
            'interface_access' => $this->json($interfaceAccess),
            'conditions' => $conditions === null ? null : $this->json($conditions),
            'is_active' => true,
            'created_by' => $createdByUserId,
        ]);

        $this->totals['custom_roles']++;
    }

    private function assignCustomOrganizationRole(
        int $userId,
        int $organizationId,
        string $roleSlug,
        int $assignedByUserId
    ): void {
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
                'role_type' => UserRoleAssignment::TYPE_CUSTOM,
                'assigned_by' => $assignedByUserId,
                'expires_at' => null,
                'is_active' => true,
            ]
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function generalTeamMembers(): array
    {
        return [
            [
                'key' => 'project_manager',
                'role_slug' => 'brick_house_gp_project_manager',
                'role_name' => 'Руководитель проекта генподряда',
                'role_description' => 'Отвечает за сроки, бюджет, участников, договоры и ключевые согласования по объекту.',
                'name' => 'Мария Орлова',
                'email' => 'demo.gp.project-manager@most.test',
                'phone' => '+7 916 210-45-01',
                'position' => 'Руководитель проекта',
                'project_role' => 'project_manager',
                'project_access_mode' => 'all_projects',
                'interface_access' => ['admin', 'lk'],
                'system_permissions' => $this->baseAdminPermissions(),
                'module_permissions' => [
                    'project-management' => ['projects.view', 'projects.edit', 'projects.analytics', 'projects.organizations.manage', 'projects.statistics'],
                    'contract-management' => ['contracts.view', 'contracts.edit', 'contracts.analytics', 'contracts.performance_acts.view', 'contracts.performance_acts.export'],
                    'schedule-management' => ['schedule.view', 'schedule.edit', 'schedule.assign', 'schedule.approve', 'schedule.export'],
                    'dashboard-widgets' => ['dashboard.view', 'dashboard.contracts.statistics', 'dashboard.recent_activity.view'],
                    'ai-assistant' => ['ai_assistant.chat', 'admin.ai_assistant.project_pulse.view', 'admin.ai_assistant.project_pulse.generate'],
                    'site-requests' => ['site_requests.view', 'site_requests.approve', 'site_requests.assign', 'site_requests.change_status'],
                    'workforce-management' => ['workforce.view', 'workforce.employees.basic', 'workforce.attendance.manage', 'workforce.payroll-source.validate'],
                    'safety-management' => ['safety-management.view', 'safety-management.permits.manage', 'safety-management.incidents.review', 'safety-management.violations.resolve'],
                    'quality-control' => ['quality-control.view', 'quality-control.inspect', 'quality-control.approve', 'quality-control.defects.view', 'quality-control.defects.assign', 'quality-control.defects.verify'],
                    'reports' => ['reports.view', 'reports.export'],
                ],
            ],
            [
                'key' => 'pto_engineer',
                'role_slug' => 'brick_house_gp_pto_engineer',
                'role_name' => 'Инженер ПТО генподряда',
                'role_description' => 'Ведет сметы, общий журнал работ, объемы, акты и исполнительную документацию.',
                'name' => 'Сергей Волков',
                'email' => 'demo.gp.pto@most.test',
                'phone' => '+7 916 210-45-02',
                'position' => 'Ведущий инженер ПТО',
                'project_role' => 'site_engineer',
                'project_access_mode' => 'assigned_projects',
                'interface_access' => ['admin', 'mobile'],
                'system_permissions' => $this->baseAdminPermissions(),
                'module_permissions' => [
                    'budget-estimates' => ['budget-estimates.view', 'budget-estimates.view_all', 'budget-estimates.create', 'budget-estimates.edit', 'budget-estimates.approve', 'construction-journal.view', 'construction-journal.create', 'construction-journal.edit', 'construction-journal.approve', 'construction-journal.export'],
                    'contract-management' => ['contracts.view', 'contracts.completed_works.view', 'contracts.performance_acts.view', 'contracts.performance_acts.create', 'contracts.performance_acts.edit', 'contracts.performance_acts.export'],
                    'schedule-management' => ['schedule.view', 'schedule.edit', 'schedule.export'],
                    'workflow-management' => ['completed_works.view', 'completed_works.create', 'completed_works.edit', 'completed_works.materials.sync'],
                    'file-management' => ['personal_files.view', 'personal_files.upload', 'report_files.view'],
                    'quality-control' => ['quality-control.view', 'quality-control.inspect', 'quality-control.defects.view', 'quality-control.defects.resolve'],
                    'safety-management' => ['safety-management.view', 'safety-management.briefings.manage', 'safety-management.violations.create'],
                    'workforce-management' => ['workforce.view', 'workforce.employees.basic', 'workforce.production-labor.view'],
                ],
            ],
            [
                'key' => 'foreman',
                'role_slug' => 'brick_house_gp_foreman',
                'role_name' => 'Прораб участка генподряда',
                'role_description' => 'Создает заявки с объекта, заполняет журнал работ и фиксирует фактические объемы.',
                'name' => 'Илья Смирнов',
                'email' => 'demo.gp.foreman@most.test',
                'phone' => '+7 916 210-45-03',
                'position' => 'Прораб участка',
                'project_role' => 'foreman',
                'project_access_mode' => 'assigned_projects',
                'interface_access' => ['admin', 'mobile'],
                'system_role_slugs' => ['foreman'],
                'system_permissions' => $this->baseFieldPermissions(),
                'module_permissions' => [
                    'project-management' => ['projects.view', 'projects.materials.view', 'projects.work_types.view'],
                    'site-requests' => ['site_requests.view', 'site_requests.create', 'site_requests.edit', 'site_requests.files.upload', 'site_requests.calendar.view'],
                    'budget-estimates' => ['budget-estimates.view', 'construction-journal.view', 'construction-journal.create', 'construction-journal.edit', 'construction-journal.export'],
                    'workflow-management' => ['completed_works.view', 'completed_works.create', 'completed_works.edit'],
                    'basic-warehouse' => ['warehouse.view', 'warehouse.receipts', 'warehouse.write_offs', 'warehouse.view_custody'],
                    'schedule-management' => ['schedule.view', 'schedule.notifications'],
                    'workforce-management' => ['workforce.view', 'workforce.attendance.manage', 'workforce.attendance.scan-confirm'],
                    'safety-management' => ['safety-management.view', 'safety-management.incidents.create', 'safety-management.violations.create', 'safety-management.briefings.manage'],
                    'quality-control' => ['quality-control.view', 'quality-control.defects.view', 'quality-control.defects.create', 'quality-control.defects.resolve'],
                ],
                'conditions' => ['project_scope' => 'assigned_projects'],
            ],
            [
                'key' => 'supply_manager',
                'role_slug' => 'brick_house_gp_supply_manager',
                'role_name' => 'Снабженец генподряда',
                'role_description' => 'Отвечает за склад, резервы, поставки материалов и обработку заявок с объекта.',
                'name' => 'Наталья Белова',
                'email' => 'demo.gp.supply@most.test',
                'phone' => '+7 916 210-45-04',
                'position' => 'Руководитель снабжения',
                'project_role' => 'supply_manager',
                'project_access_mode' => 'assigned_projects',
                'interface_access' => ['admin', 'lk'],
                'system_permissions' => $this->baseAdminPermissions(),
                'module_permissions' => [
                    'basic-warehouse' => ['warehouse.view', 'warehouse.manage_stock', 'warehouse.receipts', 'warehouse.write_offs', 'warehouse.transfers', 'warehouse.inventory', 'warehouse.reports', 'warehouse.advanced.view', 'warehouse.advanced.reservations', 'warehouse.advanced.analytics'],
                    'site-requests' => ['site_requests.view', 'site_requests.approve', 'site_requests.assign', 'site_requests.change_status', 'site_requests.statistics', 'site_requests.calendar.view'],
                    'catalog-management' => ['materials.view', 'materials.create', 'materials.edit', 'materials.balances.view', 'suppliers.view', 'suppliers.create', 'suppliers.edit'],
                    'procurement' => ['procurement.view', 'procurement.manage', 'procurement.purchase_requests.view', 'procurement.purchase_orders.view', 'procurement.purchase_orders.receive'],
                    'material-analytics' => ['materials.analytics.summary', 'materials.analytics.low_stock', 'materials.analytics.movement_report', 'materials.analytics.inventory_report'],
                ],
            ],
            [
                'key' => 'accountant',
                'role_slug' => 'brick_house_gp_accountant',
                'role_name' => 'Бухгалтер проекта генподряда',
                'role_description' => 'Ведет платежные документы, оплаты подрядчику, сверку и финансовые отчеты.',
                'name' => 'Оксана Лебедева',
                'email' => 'demo.gp.accountant@most.test',
                'phone' => '+7 916 210-45-05',
                'position' => 'Бухгалтер проекта',
                'project_role' => 'accountant',
                'project_access_mode' => 'all_projects',
                'interface_access' => ['admin', 'lk'],
                'system_permissions' => ['admin.access', 'admin.dashboard.view', 'admin.projects.view', 'organization.view', 'billing.view', 'profile.view', 'profile.edit'],
                'module_permissions' => [
                    'payments' => ['payments.dashboard.view', 'payments.invoice.view', 'payments.invoice.create', 'payments.invoice.edit', 'payments.transaction.view', 'payments.transaction.register', 'payments.schedule.view', 'payments.reconciliation.view', 'payments.reports.view', 'payments.reports.export'],
                    'contract-management' => ['contracts.view', 'contracts.analytics', 'contracts.performance_acts.view', 'contracts.performance_acts.export', 'contracts.payments.view', 'contracts.payments.create', 'contracts.payments.edit'],
                    'budget-estimates' => ['budget-estimates.view', 'budget-estimates.view_all', 'budget-estimates.export'],
                    'basic-warehouse' => ['warehouse.view', 'warehouse.reports', 'warehouse.export_m4', 'warehouse.export_m11'],
                    'reports' => ['reports.view', 'reports.export', 'reports.official_reports'],
                    'dashboard-widgets' => ['dashboard.view', 'dashboard.contracts.statistics'],
                ],
                'conditions' => ['budget' => ['daily_limit' => 5000000]],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function contractorTeamMembers(): array
    {
        return [
            [
                'key' => 'work_manager',
                'role_slug' => 'brick_house_sub_work_manager',
                'role_name' => 'Руководитель работ подрядчика',
                'role_description' => 'Координирует договор, график, бригады, акты и коммуникацию с генподрядчиком.',
                'name' => 'Дмитрий Захаров',
                'email' => 'demo.sub.work-manager@most.test',
                'phone' => '+7 916 210-46-01',
                'position' => 'Руководитель работ',
                'project_role' => 'contractor_manager',
                'project_access_mode' => 'all_projects',
                'interface_access' => ['admin', 'lk'],
                'system_permissions' => $this->baseAdminPermissions(),
                'module_permissions' => [
                    'project-management' => ['projects.view', 'projects.statistics'],
                    'contract-management' => ['contracts.view', 'contracts.completed_works.view', 'contracts.performance_acts.view', 'contracts.performance_acts.create', 'contracts.performance_acts.edit', 'contracts.payments.view'],
                    'schedule-management' => ['schedule.view', 'schedule.edit', 'schedule.assign'],
                    'site-requests' => ['site_requests.view', 'site_requests.approve', 'site_requests.assign', 'site_requests.change_status'],
                    'workforce-management' => ['workforce.view', 'workforce.employees.basic', 'workforce.production-labor.manage', 'workforce.payroll-source.manage'],
                    'safety-management' => ['safety-management.view', 'safety-management.permits.manage', 'safety-management.incidents.review', 'safety-management.violations.resolve'],
                    'quality-control' => ['quality-control.view', 'quality-control.inspect', 'quality-control.defects.view', 'quality-control.defects.assign'],
                    'dashboard-widgets' => ['dashboard.view', 'dashboard.recent_activity.view'],
                    'ai-assistant' => ['ai_assistant.chat', 'admin.ai_assistant.project_pulse.view'],
                ],
            ],
            [
                'key' => 'pto_engineer',
                'role_slug' => 'brick_house_sub_pto_engineer',
                'role_name' => 'Инженер ПТО подрядчика',
                'role_description' => 'Готовит исполнительные объемы, акты подрядчика и подтверждает записи журнала.',
                'name' => 'Анна Егорова',
                'email' => 'demo.sub.pto@most.test',
                'phone' => '+7 916 210-46-02',
                'position' => 'Инженер ПТО',
                'project_role' => 'site_engineer',
                'project_access_mode' => 'assigned_projects',
                'interface_access' => ['admin', 'mobile'],
                'system_permissions' => $this->baseAdminPermissions(),
                'module_permissions' => [
                    'budget-estimates' => ['budget-estimates.view', 'budget-estimates.create', 'budget-estimates.edit', 'construction-journal.view', 'construction-journal.create', 'construction-journal.edit', 'construction-journal.approve', 'construction-journal.export'],
                    'contract-management' => ['contracts.view', 'contracts.completed_works.view', 'contracts.performance_acts.view', 'contracts.performance_acts.create', 'contracts.performance_acts.edit', 'contracts.performance_acts.export'],
                    'workflow-management' => ['completed_works.view', 'completed_works.create', 'completed_works.edit'],
                    'schedule-management' => ['schedule.view', 'schedule.export'],
                    'file-management' => ['personal_files.view', 'personal_files.upload', 'report_files.view'],
                    'quality-control' => ['quality-control.view', 'quality-control.inspect', 'quality-control.defects.view', 'quality-control.defects.resolve'],
                    'safety-management' => ['safety-management.view', 'safety-management.briefings.manage', 'safety-management.violations.create'],
                    'workforce-management' => ['workforce.view', 'workforce.employees.basic', 'workforce.production-labor.view'],
                ],
            ],
            [
                'key' => 'foreman',
                'role_slug' => 'brick_house_sub_foreman',
                'role_name' => 'Прораб кладочных работ',
                'role_description' => 'Заполняет журнал подрядчика, создает заявки и фиксирует сменные объемы кладки.',
                'name' => 'Андрей Кузнецов',
                'email' => 'demo.sub.foreman@most.test',
                'phone' => '+7 916 210-46-03',
                'position' => 'Прораб кладочных работ',
                'project_role' => 'foreman',
                'project_access_mode' => 'assigned_projects',
                'interface_access' => ['admin', 'mobile'],
                'system_role_slugs' => ['foreman'],
                'system_permissions' => $this->baseFieldPermissions(),
                'module_permissions' => [
                    'project-management' => ['projects.view', 'projects.materials.view', 'projects.work_types.view'],
                    'site-requests' => ['site_requests.view', 'site_requests.create', 'site_requests.edit', 'site_requests.files.upload', 'site_requests.calendar.view'],
                    'budget-estimates' => ['budget-estimates.view', 'construction-journal.view', 'construction-journal.create', 'construction-journal.edit', 'construction-journal.export'],
                    'workflow-management' => ['completed_works.view', 'completed_works.create', 'completed_works.edit'],
                    'basic-warehouse' => ['warehouse.view', 'warehouse.receipts', 'warehouse.write_offs', 'warehouse.view_custody'],
                    'schedule-management' => ['schedule.view', 'schedule.notifications'],
                    'workforce-management' => ['workforce.view', 'workforce.attendance.manage', 'workforce.attendance.scan-confirm', 'workforce.production-labor.manage'],
                    'safety-management' => ['safety-management.view', 'safety-management.incidents.create', 'safety-management.violations.create', 'safety-management.briefings.manage'],
                    'quality-control' => ['quality-control.view', 'quality-control.defects.view', 'quality-control.defects.create', 'quality-control.defects.resolve'],
                ],
                'conditions' => ['project_scope' => 'assigned_projects'],
            ],
            [
                'key' => 'storekeeper',
                'role_slug' => 'brick_house_sub_storekeeper',
                'role_name' => 'Кладовщик объекта подрядчика',
                'role_description' => 'Принимает материалы, ведет остатки, резервы и выдачу на бригады.',
                'name' => 'Роман Федоров',
                'email' => 'demo.sub.storekeeper@most.test',
                'phone' => '+7 916 210-46-04',
                'position' => 'Кладовщик объекта',
                'project_role' => 'storekeeper',
                'project_access_mode' => 'assigned_projects',
                'interface_access' => ['admin', 'mobile'],
                'system_permissions' => $this->baseFieldPermissions(),
                'module_permissions' => [
                    'basic-warehouse' => ['warehouse.view', 'warehouse.manage_stock', 'warehouse.receipts', 'warehouse.write_offs', 'warehouse.transfers', 'warehouse.inventory', 'warehouse.advanced.view', 'warehouse.advanced.barcode'],
                    'site-requests' => ['site_requests.view', 'site_requests.change_status', 'site_requests.calendar.view'],
                    'catalog-management' => ['materials.view', 'materials.balances.view', 'work_types.view'],
                    'material-analytics' => ['materials.analytics.low_stock', 'materials.analytics.movement_report'],
                ],
            ],
            [
                'key' => 'accountant',
                'role_slug' => 'brick_house_sub_accountant',
                'role_name' => 'Бухгалтер подрядчика',
                'role_description' => 'Выставляет счета, подтверждает поступления и контролирует оплату актов.',
                'name' => 'Елена Морозова',
                'email' => 'demo.sub.accountant@most.test',
                'phone' => '+7 916 210-46-05',
                'position' => 'Бухгалтер подрядчика',
                'project_role' => 'accountant',
                'project_access_mode' => 'all_projects',
                'interface_access' => ['admin', 'lk'],
                'system_permissions' => ['admin.access', 'admin.dashboard.view', 'admin.projects.view', 'organization.view', 'billing.view', 'profile.view', 'profile.edit'],
                'module_permissions' => [
                    'payments' => ['payments.dashboard.view', 'payments.invoice.view', 'payments.invoice.create', 'payments.invoice.edit', 'payments.transaction.view', 'payments.transaction.register', 'payments.schedule.view', 'payments.reports.view', 'payments.reports.export'],
                    'contract-management' => ['contracts.view', 'contracts.performance_acts.view', 'contracts.performance_acts.export', 'contracts.payments.view', 'contracts.payments.create'],
                    'budget-estimates' => ['budget-estimates.view', 'budget-estimates.export'],
                    'reports' => ['reports.view', 'reports.export'],
                    'dashboard-widgets' => ['dashboard.view', 'dashboard.contracts.statistics'],
                ],
                'conditions' => ['budget' => ['daily_limit' => 2500000]],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function baseAdminPermissions(): array
    {
        return [
            'admin.access',
            'admin.dashboard.view',
            'admin.projects.view',
            'profile.view',
            'profile.edit',
            'organization.view',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function baseFieldPermissions(): array
    {
        return [
            'admin.access',
            'admin.dashboard.view',
            'admin.projects.view',
            'profile.view',
            'profile.edit',
        ];
    }

    /**
     * @param array<string, mixed> $account
     */
    private function actorId(array $account, string $key): int
    {
        $team = $account['team'] ?? [];

        if (is_array($team) && isset($team[$key]['user_id'])) {
            return (int) $team[$key]['user_id'];
        }

        return (int) $account['user_id'];
    }

    /**
     * @param array<string, mixed> $account
     * @return array{user_id: int, name: string, email: string}
     */
    private function actor(array $account, string $key): array
    {
        $team = $account['team'] ?? [];

        if (is_array($team) && isset($team[$key])) {
            return [
                'user_id' => (int) $team[$key]['user_id'],
                'name' => (string) $team[$key]['name'],
                'email' => (string) $team[$key]['email'],
            ];
        }

        return [
            'user_id' => (int) $account['user_id'],
            'name' => (string) $account['name'],
            'email' => (string) $account['email'],
        ];
    }

    private function assignOrganizationRole(int $userId, int $organizationId, string $roleSlug, ?int $assignedBy = null): void
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
                'assigned_by' => $assignedBy,
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
            ['slug' => 'budgeting', 'name' => 'Бюджетирование', 'type' => 'feature', 'category' => 'finance'],
            ['slug' => 'schedule-management', 'name' => 'Календарные графики', 'type' => 'feature', 'category' => 'planning'],
            ['slug' => 'basic-warehouse', 'name' => 'Складской учет', 'type' => 'feature', 'category' => 'warehouse'],
            ['slug' => 'site-requests', 'name' => 'Заявки с объекта', 'type' => 'feature', 'category' => 'field'],
            ['slug' => 'workforce-management', 'name' => 'Персонал и трудозатраты', 'type' => 'feature', 'category' => 'resources'],
            ['slug' => 'production-labor', 'name' => 'Наряды и выработка', 'type' => 'feature', 'category' => 'resources'],
            ['slug' => 'safety-management', 'name' => 'Охрана труда и безопасность', 'type' => 'feature', 'category' => 'execution'],
            ['slug' => 'quality-control', 'name' => 'Контроль качества', 'type' => 'feature', 'category' => 'execution'],
            ['slug' => 'workflow-management', 'name' => 'Выполненные работы', 'type' => 'feature', 'category' => 'execution'],
            ['slug' => 'file-management', 'name' => 'Файлы и документы', 'type' => 'feature', 'category' => 'documents'],
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
                'module_settings' => $this->json(['demo_enabled' => true]),
                'usage_stats' => $this->json(['demo_records' => true]),
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

        $this->seedProjectUser($projectId, $general['user_id'], 'project_manager', $general['user_id']);
        $this->seedProjectUser($projectId, $contractor['user_id'], 'contractor_manager', $general['user_id']);

        foreach (($general['team'] ?? []) as $member) {
            $this->seedProjectUser(
                $projectId,
                (int) $member['user_id'],
                (string) $member['project_role'],
                $general['user_id']
            );
        }

        foreach (($contractor['team'] ?? []) as $member) {
            $this->seedProjectUser(
                $projectId,
                (int) $member['user_id'],
                (string) $member['project_role'],
                $contractor['user_id']
            );
        }
    }

    private function seedProjectUser(int $projectId, int $userId, string $role, int $assignedByUserId): void
    {
        $this->upsert('project_user', [
            'project_id' => $projectId,
            'user_id' => $userId,
        ], [
            'role' => $role,
            'assigned_by_user_id' => $assignedByUserId,
            'is_active' => true,
            'assigned_at' => $this->now->copy()->subMonths(4),
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
        int $createdByUserId,
        int $assignedUserId,
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
            'created_by_user_id' => $createdByUserId,
            'description' => $scope === 'GP'
                ? 'Сводный график с критическим путем, поставками и контрольными точками генподряда.'
                : 'Рабочий график подрядчика: кладка, армопояса, перемычки и сдача захваток.',
            'planned_start_date' => $this->now->copy()->subMonths(5)->startOfMonth()->toDateString(),
            'planned_end_date' => $this->now->copy()->addMonths(7)->endOfMonth()->toDateString(),
            'baseline_start_date' => $this->now->copy()->subMonths(5)->startOfMonth()->toDateString(),
            'baseline_end_date' => $this->now->copy()->addMonths(7)->endOfMonth()->toDateString(),
            'baseline_saved_at' => $this->now->copy()->subMonths(4),
            'baseline_saved_by_user_id' => $createdByUserId,
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
                'assigned_user_id' => $assignedUserId,
                'created_by_user_id' => $createdByUserId,
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
            $this->seedTaskResources($taskId, $scheduleId, $organizationId, $createdByUserId, $task, $materials);
            $this->totals['schedule_tasks']++;
        }

        if (Schema::hasTable('task_dependencies')) {
            DB::table('task_dependencies')
                ->where('schedule_id', $scheduleId)
                ->delete();
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
                    'created_by_user_id' => $createdByUserId,
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

        $this->seedMilestones($scheduleId, $organizationId, $createdByUserId, $taskIds, $scope);

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

        if ($scope === 'GP') {
            $stocks['slab'] = ['available' => 0, 'reserved' => 32, 'price' => 18600.00, 'min' => 0, 'max' => 40, 'loc' => 'E-01', 'batch' => "{$scope}-SLAB-0626"];
            $stocks['roofing'] = ['available' => 0, 'reserved' => 318, 'price' => 920.00, 'min' => 0, 'max' => 360, 'loc' => 'F-02', 'batch' => "{$scope}-ROOF-0726"];
            $stocks['facing_brick'] = ['available' => 0, 'reserved' => 28400, 'price' => 52.00, 'min' => 0, 'max' => 32000, 'loc' => 'A-04', 'batch' => "{$scope}-FACE-0726"];
        }

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

        $requests = $scope === 'GP'
            ? [
                'brick_delivery' => [
                    'title' => 'Доставить кирпич М150 на кладку второго этажа',
                    'type' => 'material_request',
                    'status' => 'fulfilled',
                    'priority' => 'high',
                    'material' => 'brick',
                    'estimate_item' => '3.1',
                    'quantity' => 6200,
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
                'rebar_topup' => [
                    'title' => 'Довезти арматуру А500С на армопояс второго этажа',
                    'type' => 'material_request',
                    'status' => 'approved',
                    'priority' => 'high',
                    'material' => 'rebar',
                    'estimate_item' => '3.5',
                    'quantity' => 2.4,
                    'unit' => 'т',
                    'required_offset' => 2,
                    'description' => 'Запас на складе ниже резерва, требуется пополнение до вязки каркасов по осям А-Г.',
                ],
                'mesh_restock' => [
                    'title' => 'Пополнить сетку для армирования кладки',
                    'type' => 'material_request',
                    'status' => 'completed',
                    'priority' => 'medium',
                    'material' => 'mesh',
                    'estimate_item' => '3.4',
                    'quantity' => 180,
                    'unit' => 'м²',
                    'required_offset' => -9,
                    'description' => 'Закрыть потребность по наружным стенам и перегородкам первого этажа.',
                ],
                'slab_delivery' => [
                    'title' => 'Плиты ПБ к монтажу перекрытия',
                    'type' => 'material_request',
                    'status' => 'pending',
                    'priority' => 'high',
                    'material' => 'slab',
                    'estimate_item' => '4.1',
                    'quantity' => 32,
                    'unit' => 'шт',
                    'required_offset' => 12,
                    'description' => 'Поставка согласована с окном работы автокрана и готовностью армопояса.',
                ],
                'roofing_preorder' => [
                    'title' => 'Подтвердить поставку металлочерепицы',
                    'type' => 'material_request',
                    'status' => 'approved',
                    'priority' => 'medium',
                    'material' => 'roofing',
                    'estimate_item' => '4.3',
                    'quantity' => 318,
                    'unit' => 'м²',
                    'required_offset' => 36,
                    'description' => 'Цвет выбран заказчиком, поставщик держит резерв до конца недели.',
                ],
                'facing_brick_preorder' => [
                    'title' => 'Зарезервировать облицовочный кирпич',
                    'type' => 'material_request',
                    'status' => 'pending',
                    'priority' => 'medium',
                    'material' => 'facing_brick',
                    'estimate_item' => '4.4',
                    'quantity' => 28400,
                    'unit' => 'шт',
                    'required_offset' => 58,
                    'description' => 'Нужна ранняя бронь партии, чтобы фасад не ушел из графика после закрытия контура.',
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
                'survey_request' => [
                    'title' => 'Исполнительная съемка армопояса перед плитами',
                    'type' => 'issue_report',
                    'status' => 'approved',
                    'priority' => 'medium',
                    'material' => null,
                    'estimate_item' => '4.6',
                    'quantity' => null,
                    'unit' => null,
                    'required_offset' => 7,
                    'description' => 'Геодезист должен проверить отметки опирания до допуска плит.',
                ],
            ]
            : [
                'brick_delivery' => [
                    'title' => 'Получить кирпич М150 на захватку Б',
                    'type' => 'material_request',
                    'status' => 'fulfilled',
                    'priority' => 'high',
                    'material' => 'brick',
                    'estimate_item' => '4.1',
                    'quantity' => 4200,
                    'unit' => 'шт',
                    'required_offset' => -3,
                    'description' => 'Материал нужен для кладки наружной стены второго этажа по осям Б-Г.',
                ],
                'mortar_today' => [
                    'title' => 'Раствор М100 на дневную смену подрядчика',
                    'type' => 'material_request',
                    'status' => 'in_progress',
                    'priority' => 'urgent',
                    'material' => 'mortar',
                    'estimate_item' => '3.2',
                    'quantity' => 6.5,
                    'unit' => 'м³',
                    'required_offset' => 1,
                    'description' => 'Поставка раствора к 09:00, разгрузка у бытового городка подрядчика.',
                ],
                'rebar_topup' => [
                    'title' => 'Получить арматуру на перемычки второго этажа',
                    'type' => 'material_request',
                    'status' => 'approved',
                    'priority' => 'high',
                    'material' => 'rebar',
                    'estimate_item' => '3.5',
                    'quantity' => 0.9,
                    'unit' => 'т',
                    'required_offset' => 2,
                    'description' => 'Каркасы перемычек нужно подготовить до окна бетонирования.',
                ],
                'mesh_restock' => [
                    'title' => 'Сетка кладочная на захватку второго этажа',
                    'type' => 'material_request',
                    'status' => 'completed',
                    'priority' => 'medium',
                    'material' => 'mesh',
                    'estimate_item' => '3.4',
                    'quantity' => 90,
                    'unit' => 'м²',
                    'required_offset' => -8,
                    'description' => 'Сетка получена со склада, закрыла армирование рядов на захватке 1.',
                ],
                'masons' => [
                    'title' => 'Дополнительные каменщики на вторую смену',
                    'type' => 'personnel_request',
                    'status' => 'approved',
                    'priority' => 'medium',
                    'material' => null,
                    'estimate_item' => '4.1',
                    'quantity' => null,
                    'unit' => null,
                    'required_offset' => 3,
                    'description' => 'Нужно усиление бригады на 5 смен, чтобы не сорвать сдачу захватки генподряду.',
                ],
                'crane' => [
                    'title' => 'Автокран для перемычек и разгрузки плит',
                    'type' => 'equipment_request',
                    'status' => 'pending',
                    'priority' => 'high',
                    'material' => null,
                    'estimate_item' => '4.2',
                    'quantity' => null,
                    'unit' => null,
                    'required_offset' => 10,
                    'description' => 'Подтвердить окно работы крана и ответственного стропальщика.',
                ],
                'quality_issue' => [
                    'title' => 'Устранить замечание по толщине шва',
                    'type' => 'issue_report',
                    'status' => 'completed',
                    'priority' => 'medium',
                    'material' => null,
                    'estimate_item' => '4.1',
                    'quantity' => null,
                    'unit' => null,
                    'required_offset' => -1,
                    'description' => 'Захватка исправлена, ПТО подрядчика подготовило фотофиксацию.',
                ],
                'survey_request' => [
                    'title' => 'Согласовать исполнительную схему кладки',
                    'type' => 'issue_report',
                    'status' => 'approved',
                    'priority' => 'medium',
                    'material' => null,
                    'estimate_item' => '4.3',
                    'quantity' => null,
                    'unit' => null,
                    'required_offset' => 5,
                    'description' => 'Передать ПТО генподряда схему отклонений и привязку к осям.',
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
            'brick_delivery' => [
                'material' => 'brick',
                'status' => 'accepted',
                'requested' => $scope === 'GP' ? 6200 : 4200,
                'reserved' => $scope === 'GP' ? 6200 : 4200,
                'shipped' => $scope === 'GP' ? 6200 : 4200,
                'accepted' => $scope === 'GP' ? 6200 : 4200,
                'planned_offset' => -3,
                'shipped_offset' => -4,
                'delivered_offset' => -3,
                'accepted_offset' => -2,
            ],
            'mortar_today' => [
                'material' => 'mortar',
                'status' => 'in_transit',
                'requested' => $scope === 'GP' ? 8.5 : 6.5,
                'reserved' => $scope === 'GP' ? 8.5 : 6.5,
                'shipped' => $scope === 'GP' ? 8.5 : 6.5,
                'accepted' => 0,
                'planned_offset' => 1,
                'shipped_offset' => 0,
                'delivered_offset' => null,
                'accepted_offset' => null,
            ],
            'rebar_topup' => [
                'material' => 'rebar',
                'status' => 'reserved',
                'requested' => $scope === 'GP' ? 2.4 : 0.9,
                'reserved' => $scope === 'GP' ? 2.4 : 0.9,
                'shipped' => 0,
                'accepted' => 0,
                'planned_offset' => 2,
                'shipped_offset' => null,
                'delivered_offset' => null,
                'accepted_offset' => null,
            ],
            'mesh_restock' => [
                'material' => 'mesh',
                'status' => 'accepted',
                'requested' => $scope === 'GP' ? 180 : 90,
                'reserved' => $scope === 'GP' ? 180 : 90,
                'shipped' => $scope === 'GP' ? 180 : 90,
                'accepted' => $scope === 'GP' ? 180 : 90,
                'planned_offset' => -9,
                'shipped_offset' => -10,
                'delivered_offset' => -9,
                'accepted_offset' => -8,
            ],
        ];

        if ($scope === 'GP') {
            $deliveries['slab_delivery'] = [
                'material' => 'slab',
                'status' => 'requested',
                'requested' => 32,
                'reserved' => 0,
                'shipped' => 0,
                'accepted' => 0,
                'planned_offset' => 12,
                'shipped_offset' => null,
                'delivered_offset' => null,
                'accepted_offset' => null,
            ];

            $deliveries['roofing_preorder'] = [
                'material' => 'roofing',
                'status' => 'requested',
                'requested' => 318,
                'reserved' => 0,
                'shipped' => 0,
                'accepted' => 0,
                'planned_offset' => 36,
                'shipped_offset' => null,
                'delivered_offset' => null,
                'accepted_offset' => null,
            ];
        }

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
                'planned_delivery_date' => $this->now->copy()->addDays($delivery['planned_offset'])->toDateString(),
                'shipped_at' => $delivery['shipped_offset'] === null ? null : $this->now->copy()->addDays($delivery['shipped_offset']),
                'delivered_at' => $delivery['delivered_offset'] === null ? null : $this->now->copy()->addDays($delivery['delivered_offset']),
                'accepted_at' => $delivery['accepted_offset'] === null ? null : $this->now->copy()->addDays($delivery['accepted_offset']),
                'responsible_user_id' => $userId,
                'receiver_user_id' => $userId,
                'notes' => 'Демо-доставка материала по заявке с объекта',
                'metadata' => $this->json(['demo' => true, 'scope' => $scope]),
            ]);

            if (Schema::hasTable('project_material_delivery_events')) {
                $eventStatuses = [];

                if ((float) $delivery['reserved'] > 0) {
                    $eventStatuses[] = 'reserved';
                }

                if ((float) $delivery['shipped'] > 0) {
                    $eventStatuses[] = 'shipped';
                }

                if (!in_array($delivery['status'], $eventStatuses, true)) {
                    $eventStatuses[] = $delivery['status'];
                }

                foreach ($eventStatuses as $index => $status) {
                    $this->upsert('project_material_delivery_events', [
                        'project_material_delivery_id' => $deliveryId,
                        'event_type' => "status_{$status}",
                    ], [
                        'user_id' => $userId,
                        'from_status' => $index === 0 ? 'requested' : null,
                        'to_status' => $status,
                        'quantity' => $status === 'accepted' ? $delivery['accepted'] : ($status === 'shipped' ? $delivery['shipped'] : $delivery['reserved']),
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
        int $createdByUserId,
        int $approvedByUserId,
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
            'created_by_user_id' => $createdByUserId,
        ]);

        $entries = $scope === 'GP'
            ? [
                [
                    'number' => 1,
                    'date_offset' => -62,
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
                    'date_offset' => -54,
                    'task' => 'waterproofing',
                    'item' => '2.3',
                    'work' => 'waterproofing',
                    'material' => null,
                    'quantity' => 246.0,
                    'unit' => 'м²',
                    'description' => 'Закрыта гидроизоляция фундаментной плиты и примыканий, оформлен акт скрытых работ.',
                    'workers' => ['Изолировщики' => 5, 'Подсобные рабочие' => 2],
                    'equipment' => ['Газовая горелка' => 3],
                    'status' => 'approved',
                ],
                [
                    'number' => 3,
                    'date_offset' => -28,
                    'task' => 'masonry_outer_1f',
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
                    'number' => 4,
                    'date_offset' => -22,
                    'task' => 'masonry_outer',
                    'item' => '3.4',
                    'work' => 'masonry_outer',
                    'material' => 'mesh',
                    'quantity' => 160.0,
                    'unit' => 'м²',
                    'description' => 'Выполнено армирование кладки сеткой через три ряда на наружном контуре первого этажа.',
                    'workers' => ['Каменщики' => 7, 'Подсобные рабочие' => 2],
                    'equipment' => ['Ручной инструмент каменщика' => 7],
                    'status' => 'approved',
                ],
                [
                    'number' => 5,
                    'date_offset' => -18,
                    'task' => 'masonry_inner_1f',
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
                    'number' => 6,
                    'date_offset' => -12,
                    'task' => 'lintels',
                    'item' => '3.5',
                    'work' => 'belt',
                    'material' => 'rebar',
                    'quantity' => 7.2,
                    'unit' => 'м³',
                    'description' => 'Забетонированы перемычки проемов ПР-1 - ПР-4, каркасы приняты ПТО до бетонирования.',
                    'workers' => ['Арматурщики' => 4, 'Плотники' => 3, 'Бетонщики' => 3],
                    'equipment' => ['Глубинный вибратор' => 2],
                    'status' => 'approved',
                ],
                [
                    'number' => 7,
                    'date_offset' => -8,
                    'task' => 'quality',
                    'item' => '4.6',
                    'work' => 'quality',
                    'material' => null,
                    'quantity' => 4.0,
                    'unit' => 'шт',
                    'description' => 'ПТО сформировало комплект исполнительных схем по фундаменту и кладке первого этажа.',
                    'workers' => ['Инженер ПТО' => 2],
                    'equipment' => ['Лазерный дальномер' => 1],
                    'status' => 'approved',
                ],
                [
                    'number' => 8,
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
                [
                    'number' => 9,
                    'date_offset' => -1,
                    'task' => 'masonry_outer_2f',
                    'item' => '3.1',
                    'work' => 'masonry_outer',
                    'material' => 'brick',
                    'quantity' => 18.6,
                    'unit' => 'м³',
                    'description' => 'Продолжается кладка второго этажа по осям Б-Г, фронт работ передан подрядчику на следующую смену.',
                    'workers' => ['Каменщики' => 9, 'Подсобные рабочие' => 3],
                    'equipment' => ['Леса фасадные' => 2, 'Растворосмеситель' => 1],
                    'status' => 'submitted',
                ],
            ]
            : [
                [
                    'number' => 1,
                    'date_offset' => -26,
                    'task' => 'masonry_outer_axis_a',
                    'item' => '3.1',
                    'work' => 'masonry_outer',
                    'material' => 'brick',
                    'quantity' => 31.8,
                    'unit' => 'м³',
                    'description' => 'Выполнена кладка наружных стен первой захватки, геометрия проверена перед перестановкой лесов.',
                    'workers' => ['Каменщики' => 8, 'Подсобные рабочие' => 3],
                    'equipment' => ['Растворосмеситель' => 1, 'Леса фасадные' => 1],
                    'status' => 'approved',
                ],
                [
                    'number' => 2,
                    'date_offset' => -19,
                    'task' => 'masonry_outer_axis_c',
                    'item' => '3.1',
                    'work' => 'masonry_outer',
                    'material' => 'brick',
                    'quantity' => 27.4,
                    'unit' => 'м³',
                    'description' => 'Закрыта кладка наружных стен первой очереди по осям В-Е, замечания ПТО устранены в смену.',
                    'workers' => ['Каменщики' => 9, 'Подсобные рабочие' => 3],
                    'equipment' => ['Растворосмеситель' => 1, 'Леса фасадные' => 1],
                    'status' => 'approved',
                ],
                [
                    'number' => 3,
                    'date_offset' => -16,
                    'task' => 'masonry_inner_wet_zones',
                    'item' => '3.3',
                    'work' => 'masonry_inner',
                    'material' => 'brick',
                    'quantity' => 96.0,
                    'unit' => 'м²',
                    'description' => 'Выполнены перегородки мокрых зон и шахт инженерных коммуникаций первого этажа.',
                    'workers' => ['Каменщики' => 6, 'Подсобные рабочие' => 2],
                    'equipment' => ['Ручной инструмент каменщика' => 6],
                    'status' => 'approved',
                ],
                [
                    'number' => 4,
                    'date_offset' => -12,
                    'task' => 'masonry_inner',
                    'item' => '3.4',
                    'work' => 'masonry_inner',
                    'material' => 'mesh',
                    'quantity' => 90.0,
                    'unit' => 'м²',
                    'description' => 'Сетка кладочная уложена в рядах перегородок, фотофиксация передана ПТО подрядчика.',
                    'workers' => ['Каменщики' => 5, 'Подсобные рабочие' => 2],
                    'equipment' => ['Ручной инструмент каменщика' => 5],
                    'status' => 'approved',
                ],
                [
                    'number' => 5,
                    'date_offset' => -7,
                    'task' => 'lintels',
                    'item' => '3.5',
                    'work' => 'belt',
                    'material' => 'rebar',
                    'quantity' => 5.6,
                    'unit' => 'м³',
                    'description' => 'Подготовлены и частично забетонированы перемычки ПР-1 - ПР-3, контроль защитного слоя выполнен.',
                    'workers' => ['Арматурщики' => 4, 'Плотники' => 2],
                    'equipment' => ['Глубинный вибратор' => 1],
                    'status' => 'approved',
                ],
                [
                    'number' => 6,
                    'date_offset' => -3,
                    'task' => 'belt',
                    'item' => '3.5',
                    'work' => 'belt',
                    'material' => 'rebar',
                    'quantity' => 8.4,
                    'unit' => 'м³',
                    'description' => 'Армопояс первого этажа готовится к приемке генподрядом перед бетонированием оставшегося участка.',
                    'workers' => ['Арматурщики' => 5, 'Плотники' => 3],
                    'equipment' => ['Вибратор для бетона' => 2],
                    'status' => 'submitted',
                ],
                [
                    'number' => 7,
                    'date_offset' => -1,
                    'task' => 'masonry_second_outer',
                    'item' => '4.1',
                    'work' => 'masonry_outer',
                    'material' => 'brick',
                    'quantity' => 16.4,
                    'unit' => 'м³',
                    'description' => 'Начата кладка наружной стены второго этажа на захватке Б, кирпич получен по заявке.',
                    'workers' => ['Каменщики' => 8, 'Подсобные рабочие' => 3],
                    'equipment' => ['Леса фасадные' => 1, 'Растворосмеситель' => 1],
                    'status' => 'submitted',
                ],
                [
                    'number' => 8,
                    'date_offset' => 0,
                    'task' => 'quality',
                    'item' => '4.3',
                    'work' => 'quality',
                    'material' => null,
                    'quantity' => 2.0,
                    'unit' => 'шт',
                    'description' => 'ПТО подрядчика подготовило исполнительные схемы по кладке первого этажа и реестр фото скрытых работ.',
                    'workers' => ['Инженер ПТО' => 1],
                    'equipment' => ['Лазерный дальномер' => 1],
                    'status' => 'approved',
                ],
            ];

        $completedWorks = [];

        foreach ($entries as $entry) {
            $scheduleTaskId = $scheduleTasks[$entry['task']] ?? null;
            $estimateItemId = $estimate['items'][$entry['item']] ?? null;
            $workTypeId = $workTypes[$entry['work']] ?? reset($workTypes);
            $materialKey = $entry['material'] ?? null;
            $materialId = is_string($materialKey) ? ($materials[$materialKey] ?? null) : null;
            $materialUsage = is_string($materialKey)
                ? $this->journalMaterialUsage($materialKey, (float) $entry['quantity'], (string) $entry['unit'])
                : null;
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
                'created_by_user_id' => $createdByUserId,
                'approved_by_user_id' => $isApproved ? $approvedByUserId : null,
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

            if ($materialId && $materialUsage !== null) {
                $journalMaterialId = $this->upsert('journal_materials', [
                    'journal_entry_id' => $entryId,
                    'material_name' => $materialUsage['name'],
                ], [
                    'material_id' => $materialId,
                    'estimate_item_id' => $estimateItemId,
                    'quantity' => $materialUsage['quantity'],
                    'measurement_unit' => $materialUsage['unit'],
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
                    'user_id' => $createdByUserId,
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

                if ($materialId && $materialUsage !== null) {
                    $this->upsert('completed_work_materials', [
                        'completed_work_id' => $completedWorkId,
                        'material_id' => $materialId,
                    ], [
                        'quantity' => $materialUsage['quantity'],
                        'unit_price' => $materialUsage['unit_price'],
                        'total_amount' => round((float) $materialUsage['quantity'] * (float) $materialUsage['unit_price'], 2),
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
     * @param array<string, mixed> $general
     * @param array<string, mixed> $contractor
     */
    private function seedWorkforceDemo(int $projectId, array $general, array $contractor): void
    {
        if (!Schema::hasTable('workforce_employees')) {
            return;
        }

        $contours = [
            [
                'scope' => 'GP',
                'account' => $general,
                'manager_key' => 'project_manager',
                'foreman_key' => 'foreman',
                'employees' => $this->generalWorkforceEmployees(),
            ],
            [
                'scope' => 'SUB',
                'account' => $contractor,
                'manager_key' => 'work_manager',
                'foreman_key' => 'foreman',
                'employees' => $this->contractorWorkforceEmployees(),
            ],
        ];

        foreach ($contours as $contour) {
            $this->seedWorkforceContour(
                projectId: $projectId,
                account: $contour['account'],
                scope: $contour['scope'],
                managerUserId: $this->actorId($contour['account'], $contour['manager_key']),
                foremanUserId: $this->actorId($contour['account'], $contour['foreman_key']),
                employees: $contour['employees']
            );
        }
    }

    /**
     * @param array<string, mixed> $account
     * @param array<int, array<string, mixed>> $employees
     */
    private function seedWorkforceContour(
        int $projectId,
        array $account,
        string $scope,
        int $managerUserId,
        int $foremanUserId,
        array $employees
    ): void {
        $organizationId = (int) $account['organization_id'];
        $departmentIds = [];
        $positionIds = [];
        $staffUnitIds = [];
        $employeeIds = [];
        $hiredAt = $this->now->copy()->subMonths(7)->startOfMonth();

        foreach ($employees as $employee) {
            $departmentCode = (string) $employee['department_code'];
            $positionCode = (string) $employee['position_code'];

            $departmentIds[$departmentCode] ??= $this->upsert('workforce_departments', [
                'organization_id' => $organizationId,
                'code' => $departmentCode,
            ], [
                'name' => $employee['department_name'],
                'is_active' => true,
            ]);

            $positionIds[$positionCode] ??= $this->upsert('workforce_positions', [
                'organization_id' => $organizationId,
                'code' => $positionCode,
            ], [
                'name' => $employee['position_name'],
                'category' => $employee['category'],
                'is_active' => true,
            ]);

            $staffUnitIds[$positionCode] ??= $this->upsert('workforce_staff_units', [
                'organization_id' => $organizationId,
                'code' => "{$scope}-STAFF-{$positionCode}",
            ], [
                'department_id' => $departmentIds[$departmentCode],
                'position_id' => $positionIds[$positionCode],
                'headcount' => $employee['headcount'],
                'rate' => 1,
                'base_salary' => $employee['base_salary'],
                'valid_from' => $hiredAt->toDateString(),
                'is_active' => true,
            ]);
        }

        $scheduleId = $this->upsert('workforce_work_schedules', [
            'organization_id' => $organizationId,
            'code' => "BH-{$scope}-5D",
        ], [
            'name' => $scope === 'GP' ? 'Пятидневка ИТР и участка' : 'Сменный график кладочной бригады',
            'schedule_type' => 'weekly',
            'hours_per_day' => $scope === 'GP' ? 8 : 9,
            'week_pattern' => $this->json(['work_days' => [1, 2, 3, 4, 5], 'scenario' => 'brick_house']),
            'is_active' => true,
        ]);

        for ($day = 6; $day >= 0; $day--) {
            $date = $this->now->copy()->subDays($day);
            $this->upsert('workforce_work_schedule_days', [
                'organization_id' => $organizationId,
                'work_schedule_id' => $scheduleId,
                'work_date' => $date->toDateString(),
            ], [
                'day_type' => $date->isWeekend() ? 'day_off' : 'work',
                'planned_hours' => $date->isWeekend() ? 0 : ($scope === 'GP' ? 8 : 9),
                'comment' => $date->isWeekend() ? 'Выходной по демо-графику' : 'Рабочий день демо-объекта',
            ]);
        }

        foreach ($employees as $index => $employee) {
            $userId = isset($employee['user_key']) ? $this->actorId($account, (string) $employee['user_key']) : null;
            $employeeId = $this->upsert('workforce_employees', [
                'organization_id' => $organizationId,
                'personnel_number' => $employee['personnel_number'],
            ], [
                'user_id' => $userId,
                'last_name' => $employee['last_name'],
                'first_name' => $employee['first_name'],
                'middle_name' => $employee['middle_name'],
                'employment_status' => 'active',
                'hire_date' => $hiredAt->copy()->addDays($index * 3)->toDateString(),
                'external_payroll_ref' => $employee['external_payroll_ref'],
                'phone' => $employee['phone'],
                'email' => $employee['email'],
                'metadata' => $this->json([
                    'scenario' => 'brick_house',
                    'scope' => $scope,
                    'project_id' => $projectId,
                    'hour_rate' => $employee['hour_rate'],
                ]),
            ]);

            $employeeIds[$employee['key']] = $employeeId;

            $this->upsert('workforce_employment_contracts', [
                'organization_id' => $organizationId,
                'contract_number' => "BH-{$scope}-TD-" . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
            ], [
                'employee_id' => $employeeId,
                'contract_date' => $hiredAt->copy()->addDays($index * 3)->toDateString(),
                'start_date' => $hiredAt->copy()->addDays($index * 3)->toDateString(),
                'end_date' => null,
                'status' => 'active',
                'metadata' => $this->json(['scenario' => 'brick_house']),
            ]);

            $this->upsert('workforce_employee_assignments', [
                'organization_id' => $organizationId,
                'employee_id' => $employeeId,
                'staff_unit_id' => $staffUnitIds[$employee['position_code']],
                'valid_from' => $this->now->copy()->subMonths(5)->toDateString(),
            ], [
                'department_id' => $departmentIds[$employee['department_code']],
                'position_id' => $positionIds[$employee['position_code']],
                'project_id' => $projectId,
                'work_schedule_id' => $scheduleId,
                'rate' => 1,
                'valid_to' => null,
                'status' => 'active',
            ]);

            $this->totals['workforce_records'] += 3;
        }

        $absenceTypeId = $this->upsert('workforce_absence_types', [
            'organization_id' => $organizationId,
            'code' => 'SICK_LEAVE',
        ], [
            'name' => 'Больничный',
            'affects_payroll' => true,
        ]);

        $absenceEmployeeId = $employeeIds[$scope === 'GP' ? 'laborer' : 'helper'] ?? 0;
        if ($absenceEmployeeId > 0) {
            $absenceDate = $this->now->copy()->subDays(2)->toDateString();
            $this->upsert('workforce_absences', [
                'organization_id' => $organizationId,
                'employee_id' => $absenceEmployeeId,
                'absence_type_id' => $absenceTypeId,
                'start_date' => $absenceDate,
                'end_date' => $absenceDate,
            ], [
                'status' => 'approved',
                'comment' => 'Демо-отсутствие, учтено в сменной сводке',
            ]);
            $this->totals['workforce_records']++;
        }

        $tripEmployeeId = $employeeIds[$scope === 'GP' ? 'pto_engineer' : 'work_manager'] ?? 0;
        if ($tripEmployeeId > 0) {
            $this->upsert('workforce_business_trips', [
                'organization_id' => $organizationId,
                'employee_id' => $tripEmployeeId,
                'project_id' => $projectId,
                'start_date' => $this->now->copy()->subDays(9)->toDateString(),
                'end_date' => $this->now->copy()->subDays(8)->toDateString(),
            ], [
                'destination' => 'Испытательная лаборатория бетона',
                'purpose' => 'Передача образцов и получение протоколов для демо-объекта',
                'status' => 'approved',
            ]);
            $this->totals['workforce_records']++;
        }

        $this->upsert('workforce_orders', [
            'organization_id' => $organizationId,
            'order_number' => "BH-{$scope}-ORD-001",
        ], [
            'employee_id' => $tripEmployeeId ?: null,
            'order_date' => $this->now->copy()->subDays(11)->toDateString(),
            'order_type' => 'business_trip',
            'status' => 'applied',
            'payload' => $this->json([
                'scenario' => 'brick_house',
                'project_id' => $projectId,
                'approved_by_user_id' => $managerUserId,
            ]),
        ]);

        $periodId = $this->upsert('workforce_payroll_periods', [
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'period_start' => $this->now->copy()->startOfMonth()->toDateString(),
            'period_end' => $this->now->copy()->endOfMonth()->toDateString(),
        ], [
            'status' => 'validated',
            'created_by_user_id' => $managerUserId,
            'locked_at' => null,
            'locked_by_user_id' => null,
            'source_hash' => hash('sha256', "brick-house-{$scope}-payroll"),
        ]);

        $statementId = $this->upsert('workforce_payroll_statements', [
            'organization_id' => $organizationId,
            'statement_number' => "BH-{$scope}-PAY-" . $this->now->format('Ym'),
        ], [
            'payroll_period_id' => $periodId,
            'status' => 'prepared',
            'total_hours' => 0,
            'gross_amount' => 0,
            'created_by_user_id' => $managerUserId,
        ]);

        $totalHours = 0.0;
        $grossAmount = 0.0;

        foreach ($employees as $employee) {
            $employeeId = $employeeIds[$employee['key']] ?? 0;
            if ($employeeId === 0) {
                continue;
            }

            $employeeHours = 0.0;
            $employeeGross = 0.0;

            for ($day = 4; $day >= 0; $day--) {
                $date = $this->now->copy()->subDays($day);
                $isAbsent = $employee['key'] === ($scope === 'GP' ? 'laborer' : 'helper') && $day === 2;
                $hours = $date->isWeekend() || $isAbsent ? 0.0 : (float) ($scope === 'GP' ? 8 : 9);
                $status = $hours > 0 ? 'at_work' : ($isAbsent ? 'absence' : 'scheduled_day_off');
                $amount = round($hours * (float) $employee['hour_rate'], 2);

                $this->upsert('workforce_attendance_corrections', [
                    'organization_id' => $organizationId,
                    'employee_id' => $employeeId,
                    'work_date' => $date->toDateString(),
                ], [
                    'project_id' => $projectId,
                    'status' => $status,
                    'hours' => $hours,
                    'reason' => $status === 'at_work'
                        ? 'Подтвержденная смена на демо-объекте'
                        : 'Отклонение учтено в демо-табеле',
                    'created_by_user_id' => $foremanUserId,
                ]);

                $this->upsert('workforce_payroll_source_rows', [
                    'organization_id' => $organizationId,
                    'payroll_period_id' => $periodId,
                    'employee_id' => $employeeId,
                    'project_id' => $projectId,
                    'work_date' => $date->toDateString(),
                    'source_type' => 'brick_house_demo',
                ], [
                    'work_order_id' => null,
                    'work_order_line_id' => null,
                    'timesheet_entry_id' => null,
                    'hours' => $hours,
                    'amount' => $amount,
                    'payload' => $this->json([
                        'scenario' => 'brick_house',
                        'status' => $status,
                        'scope' => $scope,
                    ]),
                ]);

                $employeeHours += $hours;
                $employeeGross += $amount;
            }

            $this->upsert('workforce_payroll_statement_rows', [
                'payroll_statement_id' => $statementId,
                'employee_id' => $employeeId,
                'project_id' => $projectId,
            ], [
                'organization_id' => $organizationId,
                'payroll_period_id' => $periodId,
                'hours' => $employeeHours,
                'gross_amount' => $employeeGross,
                'source_row_ids' => $this->json(['scenario' => 'brick_house']),
            ]);

            $totalHours += $employeeHours;
            $grossAmount += $employeeGross;
            $this->totals['workforce_records'] += 11;
        }

        if ($statementId > 0 && Schema::hasTable('workforce_payroll_statements')) {
            DB::table('workforce_payroll_statements')
                ->where('id', $statementId)
                ->update($this->withTimestamps('workforce_payroll_statements', [
                    'total_hours' => $totalHours,
                    'gross_amount' => $grossAmount,
                ], true));
        }

        foreach (array_slice($employeeIds, 0, 3) as $employeeId) {
            $tokenHash = hash('sha256', "brick-house-{$scope}-qr-{$employeeId}");
            $tokenId = $this->upsert('workforce_attendance_qr_tokens', [
                'token_hash' => $tokenHash,
            ], [
                'organization_id' => $organizationId,
                'employee_id' => $employeeId,
                'project_id' => $projectId,
                'work_date' => $this->now->toDateString(),
                'payload_hash' => hash('sha256', "brick-house-{$scope}-payload-{$employeeId}"),
                'expires_at' => $this->now->copy()->addHours(8),
                'used_at' => $this->now->copy()->subHours(3),
                'used_by_user_id' => $foremanUserId,
                'status' => 'used',
                'created_by_user_id' => $foremanUserId,
            ]);

            $this->upsert('workforce_attendance_scan_events', [
                'organization_id' => $organizationId,
                'employee_id' => $employeeId,
                'project_id' => $projectId,
                'work_date' => $this->now->toDateString(),
                'device_id' => "BH-{$scope}-GATE-01",
            ], [
                'qr_token_id' => $tokenId,
                'scanned_by_user_id' => $foremanUserId,
                'result' => 'success',
                'result_label' => 'Смена подтверждена',
                'failure_reason' => null,
                'scanned_at' => $this->now->copy()->setTime(8, 12),
                'metadata' => $this->json(['scenario' => 'brick_house', 'scope' => $scope]),
            ]);

            $this->totals['workforce_records'] += 2;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function generalWorkforceEmployees(): array
    {
        return [
            ['key' => 'project_manager', 'user_key' => 'project_manager', 'personnel_number' => 'BH-GP-001', 'external_payroll_ref' => 'BH-GP-PAY-001', 'last_name' => 'Орлова', 'first_name' => 'Мария', 'middle_name' => 'Викторовна', 'department_code' => 'BH-GP-PMO', 'department_name' => 'Проектный офис', 'position_code' => 'BH-GP-PM', 'position_name' => 'Руководитель проекта', 'category' => 'management', 'headcount' => 1, 'base_salary' => 210000, 'hour_rate' => 1450, 'phone' => '+7 916 210-45-01', 'email' => 'demo.gp.project-manager@most.test'],
            ['key' => 'pto_engineer', 'user_key' => 'pto_engineer', 'personnel_number' => 'BH-GP-002', 'external_payroll_ref' => 'BH-GP-PAY-002', 'last_name' => 'Волков', 'first_name' => 'Сергей', 'middle_name' => 'Игоревич', 'department_code' => 'BH-GP-PTO', 'department_name' => 'Производственно-технический отдел', 'position_code' => 'BH-GP-PTO', 'position_name' => 'Ведущий инженер ПТО', 'category' => 'engineering', 'headcount' => 2, 'base_salary' => 165000, 'hour_rate' => 1180, 'phone' => '+7 916 210-45-02', 'email' => 'demo.gp.pto@most.test'],
            ['key' => 'foreman', 'user_key' => 'foreman', 'personnel_number' => 'BH-GP-003', 'external_payroll_ref' => 'BH-GP-PAY-003', 'last_name' => 'Смирнов', 'first_name' => 'Илья', 'middle_name' => 'Павлович', 'department_code' => 'BH-GP-SITE', 'department_name' => 'Участок коробки здания', 'position_code' => 'BH-GP-FOREMAN', 'position_name' => 'Прораб участка', 'category' => 'site', 'headcount' => 2, 'base_salary' => 150000, 'hour_rate' => 1090, 'phone' => '+7 916 210-45-03', 'email' => 'demo.gp.foreman@most.test'],
            ['key' => 'supply_manager', 'user_key' => 'supply_manager', 'personnel_number' => 'BH-GP-004', 'external_payroll_ref' => 'BH-GP-PAY-004', 'last_name' => 'Белова', 'first_name' => 'Наталья', 'middle_name' => 'Олеговна', 'department_code' => 'BH-GP-SUPPLY', 'department_name' => 'Снабжение и склад', 'position_code' => 'BH-GP-SUPPLY', 'position_name' => 'Руководитель снабжения', 'category' => 'supply', 'headcount' => 1, 'base_salary' => 142000, 'hour_rate' => 980, 'phone' => '+7 916 210-45-04', 'email' => 'demo.gp.supply@most.test'],
            ['key' => 'safety_engineer', 'personnel_number' => 'BH-GP-005', 'external_payroll_ref' => 'BH-GP-PAY-005', 'last_name' => 'Тарасов', 'first_name' => 'Павел', 'middle_name' => 'Андреевич', 'department_code' => 'BH-GP-SITE', 'department_name' => 'Участок коробки здания', 'position_code' => 'BH-GP-HSE', 'position_name' => 'Инженер по охране труда', 'category' => 'safety', 'headcount' => 1, 'base_salary' => 138000, 'hour_rate' => 940, 'phone' => '+7 916 210-45-06', 'email' => 'hse.gp@most.test'],
            ['key' => 'laborer', 'personnel_number' => 'BH-GP-006', 'external_payroll_ref' => 'BH-GP-PAY-006', 'last_name' => 'Ковалев', 'first_name' => 'Никита', 'middle_name' => 'Сергеевич', 'department_code' => 'BH-GP-SITE', 'department_name' => 'Участок коробки здания', 'position_code' => 'BH-GP-LABOR', 'position_name' => 'Подсобный рабочий', 'category' => 'worker', 'headcount' => 6, 'base_salary' => 92000, 'hour_rate' => 620, 'phone' => '+7 916 210-45-07', 'email' => 'worker.gp@most.test'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function contractorWorkforceEmployees(): array
    {
        return [
            ['key' => 'work_manager', 'user_key' => 'work_manager', 'personnel_number' => 'BH-SUB-001', 'external_payroll_ref' => 'BH-SUB-PAY-001', 'last_name' => 'Захаров', 'first_name' => 'Дмитрий', 'middle_name' => 'Романович', 'department_code' => 'BH-SUB-MGMT', 'department_name' => 'Управление кладочными работами', 'position_code' => 'BH-SUB-WM', 'position_name' => 'Руководитель работ', 'category' => 'management', 'headcount' => 1, 'base_salary' => 172000, 'hour_rate' => 1220, 'phone' => '+7 916 210-46-01', 'email' => 'demo.sub.work-manager@most.test'],
            ['key' => 'pto_engineer', 'user_key' => 'pto_engineer', 'personnel_number' => 'BH-SUB-002', 'external_payroll_ref' => 'BH-SUB-PAY-002', 'last_name' => 'Егорова', 'first_name' => 'Анна', 'middle_name' => 'Владимировна', 'department_code' => 'BH-SUB-PTO', 'department_name' => 'ПТО подрядчика', 'position_code' => 'BH-SUB-PTO', 'position_name' => 'Инженер ПТО', 'category' => 'engineering', 'headcount' => 1, 'base_salary' => 138000, 'hour_rate' => 980, 'phone' => '+7 916 210-46-02', 'email' => 'demo.sub.pto@most.test'],
            ['key' => 'foreman', 'user_key' => 'foreman', 'personnel_number' => 'BH-SUB-003', 'external_payroll_ref' => 'BH-SUB-PAY-003', 'last_name' => 'Кузнецов', 'first_name' => 'Андрей', 'middle_name' => 'Николаевич', 'department_code' => 'BH-SUB-SITE', 'department_name' => 'Бригада кладки', 'position_code' => 'BH-SUB-FOREMAN', 'position_name' => 'Прораб кладочных работ', 'category' => 'site', 'headcount' => 1, 'base_salary' => 146000, 'hour_rate' => 1050, 'phone' => '+7 916 210-46-03', 'email' => 'demo.sub.foreman@most.test'],
            ['key' => 'mason_1', 'personnel_number' => 'BH-SUB-004', 'external_payroll_ref' => 'BH-SUB-PAY-004', 'last_name' => 'Громов', 'first_name' => 'Василий', 'middle_name' => 'Петрович', 'department_code' => 'BH-SUB-SITE', 'department_name' => 'Бригада кладки', 'position_code' => 'BH-SUB-MASON', 'position_name' => 'Каменщик 5 разряда', 'category' => 'worker', 'headcount' => 8, 'base_salary' => 128000, 'hour_rate' => 890, 'phone' => '+7 916 210-46-06', 'email' => 'mason1.sub@most.test'],
            ['key' => 'mason_2', 'personnel_number' => 'BH-SUB-005', 'external_payroll_ref' => 'BH-SUB-PAY-005', 'last_name' => 'Лазарев', 'first_name' => 'Олег', 'middle_name' => 'Ильич', 'department_code' => 'BH-SUB-SITE', 'department_name' => 'Бригада кладки', 'position_code' => 'BH-SUB-MASON', 'position_name' => 'Каменщик 5 разряда', 'category' => 'worker', 'headcount' => 8, 'base_salary' => 126000, 'hour_rate' => 875, 'phone' => '+7 916 210-46-07', 'email' => 'mason2.sub@most.test'],
            ['key' => 'helper', 'personnel_number' => 'BH-SUB-006', 'external_payroll_ref' => 'BH-SUB-PAY-006', 'last_name' => 'Фомин', 'first_name' => 'Кирилл', 'middle_name' => 'Денисович', 'department_code' => 'BH-SUB-SITE', 'department_name' => 'Бригада кладки', 'position_code' => 'BH-SUB-HELPER', 'position_name' => 'Подсобный рабочий кладочной бригады', 'category' => 'worker', 'headcount' => 4, 'base_salary' => 88000, 'hour_rate' => 590, 'phone' => '+7 916 210-46-08', 'email' => 'helper.sub@most.test'],
        ];
    }

    /**
     * @param array<string, mixed> $general
     * @param array<string, mixed> $contractor
     */
    private function seedSafetyDemo(int $projectId, array $general, array $contractor): void
    {
        if (!Schema::hasTable('safety_work_permits')) {
            return;
        }

        $this->seedSafetyContour($projectId, $general, 'GP', 'project_manager', 'foreman');
        $this->seedSafetyContour($projectId, $contractor, 'SUB', 'work_manager', 'foreman');
    }

    /**
     * @param array<string, mixed> $account
     */
    private function seedSafetyContour(int $projectId, array $account, string $scope, string $managerKey, string $foremanKey): void
    {
        $organizationId = (int) $account['organization_id'];
        $managerUserId = $this->actorId($account, $managerKey);
        $foremanUserId = $this->actorId($account, $foremanKey);
        $ptoUserId = $this->actorId($account, 'pto_engineer');

        $permitId = $this->upsert('safety_work_permits', [
            'permit_number' => "BH-HSE-P-{$scope}-001",
        ], [
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'created_by_user_id' => $foremanUserId,
            'responsible_user_id' => $foremanUserId,
            'approved_by_user_id' => $managerUserId,
            'title' => $scope === 'GP' ? 'Наряд-допуск на работы у котлована' : 'Наряд-допуск на кладку с лесов',
            'permit_type' => $scope === 'GP' ? 'excavation_zone' : 'working_at_height',
            'location_name' => $scope === 'GP' ? 'Оси А-Г, отметка -0.600' : 'Фасад по оси 1, второй ярус лесов',
            'risk_level' => 'high',
            'valid_from' => $this->now->copy()->subDays(2),
            'valid_until' => $this->now->copy()->addDays(3),
            'required_controls' => $this->json(['Ограждение зоны', 'СИЗ', 'Проверка лесов', 'Ответственный на смене']),
            'status' => 'active',
            'submitted_at' => $this->now->copy()->subDays(3),
            'approved_at' => $this->now->copy()->subDays(2)->setTime(7, 30),
            'activated_at' => $this->now->copy()->subDays(2)->setTime(8, 0),
            'approval_comment' => 'Контрольные мероприятия подтверждены',
            'metadata' => $this->json(['scenario' => 'brick_house', 'scope' => $scope]),
        ]);

        $this->upsert('safety_work_permits', [
            'permit_number' => "BH-HSE-P-{$scope}-002",
        ], [
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'created_by_user_id' => $foremanUserId,
            'responsible_user_id' => $ptoUserId,
            'approved_by_user_id' => $managerUserId,
            'closed_by_user_id' => $managerUserId,
            'title' => 'Наряд на разгрузку и строповку плит перекрытия',
            'permit_type' => 'lifting_operations',
            'location_name' => 'Зона разгрузки у временной дороги',
            'risk_level' => 'medium',
            'valid_from' => $this->now->copy()->subDays(12),
            'valid_until' => $this->now->copy()->subDays(10),
            'required_controls' => $this->json(['Схема строповки', 'Допуск стропальщика', 'Осмотр зоны разгрузки']),
            'status' => 'closed',
            'submitted_at' => $this->now->copy()->subDays(13),
            'approved_at' => $this->now->copy()->subDays(12)->setTime(7, 20),
            'activated_at' => $this->now->copy()->subDays(12)->setTime(8, 0),
            'closed_at' => $this->now->copy()->subDays(10)->setTime(18, 20),
            'approval_comment' => 'Проверено перед началом смены',
            'close_comment' => 'Работы завершены без замечаний',
            'metadata' => $this->json(['scenario' => 'brick_house', 'scope' => $scope]),
        ]);

        $incidentId = $this->upsert('safety_incidents', [
            'incident_number' => "BH-HSE-I-{$scope}-001",
        ], [
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'reported_by_user_id' => $foremanUserId,
            'assigned_to_user_id' => $ptoUserId,
            'triaged_by_user_id' => $managerUserId,
            'title' => $scope === 'GP' ? 'Повреждение временного ограждения у склада' : 'Падение кирпича с подмостей без травм',
            'incident_type' => 'near_miss',
            'severity' => 'major',
            'status' => 'corrective_actions',
            'occurred_at' => $this->now->copy()->subDays(6)->setTime(11, 15),
            'location_name' => $scope === 'GP' ? 'Склад кирпича, северная сторона' : 'Второй ярус лесов, ось Б',
            'description' => 'Ситуация зафиксирована на утреннем обходе, работы приостановлены на участке до устранения.',
            'immediate_actions' => 'Зона ограждена, проведен внеплановый инструктаж, ответственный назначен.',
            'root_cause' => 'Недостаточный контроль крепления временного ограждения после перемещения материалов.',
            'triage_comment' => 'Требуется корректирующее действие и повторная проверка.',
            'triaged_at' => $this->now->copy()->subDays(6)->setTime(12, 10),
            'investigation_started_at' => $this->now->copy()->subDays(6)->setTime(14, 0),
            'corrective_actions_started_at' => $this->now->copy()->subDays(5)->setTime(9, 0),
            'metadata' => $this->json(['scenario' => 'brick_house', 'scope' => $scope, 'permit_id' => $permitId]),
        ]);

        $this->upsert('safety_incidents', [
            'incident_number' => "BH-HSE-I-{$scope}-002",
        ], [
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'reported_by_user_id' => $foremanUserId,
            'assigned_to_user_id' => $ptoUserId,
            'triaged_by_user_id' => $managerUserId,
            'closed_by_user_id' => $managerUserId,
            'title' => 'Скользкий проход после дождя',
            'incident_type' => 'unsafe_condition',
            'severity' => 'minor',
            'status' => 'closed',
            'occurred_at' => $this->now->copy()->subDays(18)->setTime(9, 40),
            'location_name' => 'Проход от бытового городка к зоне кладки',
            'description' => 'Проход посыпан песком, временное покрытие восстановлено.',
            'immediate_actions' => 'Выставлены конусы, проход очищен.',
            'root_cause' => 'Ливневый сток после ночного дождя.',
            'corrective_actions' => 'Добавлен настил и дополнительная уборка после осадков.',
            'triage_comment' => 'Закрыто после осмотра.',
            'triaged_at' => $this->now->copy()->subDays(18)->setTime(10, 10),
            'investigation_started_at' => $this->now->copy()->subDays(18)->setTime(10, 30),
            'corrective_actions_started_at' => $this->now->copy()->subDays(18)->setTime(11, 0),
            'closed_at' => $this->now->copy()->subDays(17)->setTime(16, 30),
            'metadata' => $this->json(['scenario' => 'brick_house', 'scope' => $scope]),
        ]);

        $violationId = $this->upsert('safety_violations', [
            'violation_number' => "BH-HSE-V-{$scope}-001",
        ], [
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'created_by_user_id' => $ptoUserId,
            'assigned_to_user_id' => $foremanUserId,
            'title' => $scope === 'GP' ? 'Не закрыт проход в зону разгрузки' : 'Работа без закрепленного страховочного фала',
            'severity' => 'critical',
            'status' => 'open',
            'location_name' => $scope === 'GP' ? 'Временная дорога у склада' : 'Леса по фасаду, захватка 2',
            'description' => 'Нарушение выявлено при обходе, требуется устранить до продолжения работ.',
            'corrective_action' => 'Назначить ответственного, восстановить ограждение и провести повторный инструктаж.',
            'due_date' => $this->now->copy()->subDay()->toDateString(),
            'metadata' => $this->json(['scenario' => 'brick_house', 'scope' => $scope]),
        ]);

        $this->upsert('safety_violations', [
            'violation_number' => "BH-HSE-V-{$scope}-002",
        ], [
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'created_by_user_id' => $ptoUserId,
            'assigned_to_user_id' => $foremanUserId,
            'resolved_by_user_id' => $foremanUserId,
            'title' => 'Неактуальная бирка осмотра лесов',
            'severity' => 'major',
            'status' => 'resolved',
            'location_name' => 'Леса по оси В',
            'description' => 'Бирка осмотра не обновлена после перестановки секции.',
            'corrective_action' => 'Провести повторный осмотр и обновить бирку допуска.',
            'due_date' => $this->now->copy()->subDays(8)->toDateString(),
            'resolved_at' => $this->now->copy()->subDays(7)->setTime(15, 20),
            'resolution_comment' => 'Осмотр выполнен, бирка обновлена.',
            'metadata' => $this->json(['scenario' => 'brick_house', 'scope' => $scope]),
        ]);

        $briefings = [
            ['number' => "BH-HSE-B-{$scope}-001", 'title' => 'Вводный инструктаж перед работами на высоте', 'type' => 'target', 'days' => 21],
            ['number' => "BH-HSE-B-{$scope}-002", 'title' => 'Внеплановый инструктаж после замечания по ограждению', 'type' => 'unscheduled', 'days' => 5],
        ];

        foreach ($briefings as $briefing) {
            $briefingId = $this->upsert('safety_briefings', [
                'briefing_number' => $briefing['number'],
            ], [
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'conducted_by_user_id' => $foremanUserId,
                'title' => $briefing['title'],
                'briefing_type' => $briefing['type'],
                'location_name' => 'Прорабская на объекте',
                'conducted_at' => $this->now->copy()->subDays($briefing['days'])->setTime(8, 20),
                'topics' => $this->json(['СИЗ', 'Маршруты прохода', 'Работы на высоте', 'Порядок фиксации замечаний']),
                'notes' => 'Демо-инструктаж с внутренними и внешними участниками',
                'metadata' => $this->json(['scenario' => 'brick_house', 'scope' => $scope]),
            ]);

            foreach ([
                ['user_id' => $foremanUserId, 'name' => null, 'role' => 'Прораб'],
                ['user_id' => $ptoUserId, 'name' => null, 'role' => 'Инженер ПТО'],
                ['user_id' => null, 'name' => $scope === 'GP' ? 'Виктор Рябов' : 'Михаил Жуков', 'role' => 'Каменщик'],
            ] as $participant) {
                $this->upsert('safety_briefing_participants', [
                    'briefing_id' => $briefingId,
                    'user_id' => $participant['user_id'],
                    'external_name' => $participant['name'],
                ], [
                    'company_name' => $account['organization_name'],
                    'role_name' => $participant['role'],
                    'signed_at' => $this->now->copy()->subDays($briefing['days'])->setTime(8, 45),
                    'metadata' => $this->json(['scenario' => 'brick_house']),
                ]);
            }
        }

        $this->upsert('safety_corrective_actions', [
            'action_number' => "BH-HSE-C-{$scope}-001",
        ], [
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'incident_id' => $incidentId,
            'violation_id' => null,
            'created_by_user_id' => $managerUserId,
            'assigned_to_user_id' => $foremanUserId,
            'resolved_by_user_id' => null,
            'verified_by_user_id' => null,
            'title' => 'Восстановить схему ограждений и закрепить ответственного',
            'description' => 'Проверить периметр зоны разгрузки, обновить схему и провести повторный обход.',
            'source_type' => 'incident',
            'severity' => 'major',
            'status' => 'open',
            'due_date' => $this->now->copy()->addDays(2)->toDateString(),
            'metadata' => $this->json(['scenario' => 'brick_house', 'scope' => $scope]),
        ]);

        $this->upsert('safety_corrective_actions', [
            'action_number' => "BH-HSE-C-{$scope}-002",
        ], [
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'incident_id' => null,
            'violation_id' => $violationId,
            'created_by_user_id' => $ptoUserId,
            'assigned_to_user_id' => $foremanUserId,
            'resolved_by_user_id' => $foremanUserId,
            'verified_by_user_id' => $managerUserId,
            'title' => 'Подтвердить устранение критического замечания',
            'description' => 'Фотофиксация, обход зоны и отметка ответственного в журнале безопасности.',
            'source_type' => 'violation',
            'severity' => 'critical',
            'status' => 'verified',
            'due_date' => $this->now->copy()->subDays(1)->toDateString(),
            'resolution_comment' => 'Ограждение восстановлено, проход закрыт.',
            'resolved_at' => $this->now->copy()->subHours(8),
            'verification_comment' => 'Проверено руководителем работ.',
            'verified_at' => $this->now->copy()->subHours(4),
            'metadata' => $this->json(['scenario' => 'brick_house', 'scope' => $scope]),
        ]);

        $this->totals['safety_records'] += 19;
    }

    /**
     * @param array<string, mixed> $general
     * @param array<string, mixed> $contractor
     * @param array<string, int> $contractors
     * @param array{general: array<string, int>, contractor: array<string, int>} $scheduleTasks
     * @param array{general: array<string, mixed>, contractor: array<string, mixed>} $journals
     */
    private function seedQualityDemo(
        int $projectId,
        array $general,
        array $contractor,
        array $contractors,
        array $scheduleTasks,
        array $journals
    ): void {
        if (!Schema::hasTable('quality_defects')) {
            return;
        }

        $this->seedQualityContour(
            projectId: $projectId,
            account: $general,
            scope: 'GP',
            contractorId: $contractors['contractor_in_general_id'] ?? null,
            scheduleTasks: $scheduleTasks['general'],
            completedWorks: $journals['general']['completed_works'] ?? []
        );

        $this->seedQualityContour(
            projectId: $projectId,
            account: $contractor,
            scope: 'SUB',
            contractorId: $contractors['general_in_contractor_id'] ?? null,
            scheduleTasks: $scheduleTasks['contractor'],
            completedWorks: $journals['contractor']['completed_works'] ?? []
        );
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, int> $scheduleTasks
     * @param array<int, array<string, mixed>> $completedWorks
     */
    private function seedQualityContour(
        int $projectId,
        array $account,
        string $scope,
        ?int $contractorId,
        array $scheduleTasks,
        array $completedWorks
    ): void {
        $organizationId = (int) $account['organization_id'];
        $creatorId = $this->actorId($account, 'pto_engineer');
        $assigneeId = $this->actorId($account, $scope === 'GP' ? 'foreman' : 'foreman');
        $managerId = $this->actorId($account, $scope === 'GP' ? 'project_manager' : 'work_manager');

        $defects = [
            [
                'number' => "BH-QC-{$scope}-001",
                'title' => $scope === 'GP' ? 'Отклонение вертикали кладки по оси Б' : 'Неравномерная толщина шва на захватке 2',
                'description' => 'Замечание выявлено при операционном контроле, требуется корректировка до закрытия сменного объема.',
                'severity' => 'critical',
                'status' => 'assigned',
                'task' => 'masonry_outer',
                'work_index' => 1,
                'due_days' => -1,
                'location' => $scope === 'GP' ? '1 этаж, ось Б/3-4' : '1 этаж, ось В/2-3',
                'history' => ['open', 'assigned'],
            ],
            [
                'number' => "BH-QC-{$scope}-002",
                'title' => 'Исправление армирования перемычки ожидает проверки',
                'description' => 'Подрядчик сообщил об устранении, ПТО должен подтвердить результат повторным осмотром.',
                'severity' => 'major',
                'status' => 'ready_for_review',
                'task' => 'belt',
                'work_index' => 2,
                'due_days' => 1,
                'location' => 'Проем ПР-4, первый этаж',
                'resolved_at' => $this->now->copy()->subHours(5),
                'history' => ['open', 'assigned', 'in_progress', 'ready_for_review'],
            ],
            [
                'number' => "BH-QC-{$scope}-003",
                'title' => 'Партия кирпича принята после входного контроля',
                'description' => 'Проверены паспорта, геометрия и маркировка. Замечание закрыто.',
                'severity' => 'minor',
                'status' => 'resolved',
                'task' => 'masonry_inner',
                'work_index' => 0,
                'due_days' => -8,
                'location' => 'Склад кирпича, партия М150-05',
                'resolved_at' => $this->now->copy()->subDays(7),
                'verified_at' => $this->now->copy()->subDays(6),
                'history' => ['open', 'assigned', 'in_progress', 'ready_for_review', 'resolved'],
            ],
            [
                'number' => "BH-QC-{$scope}-004",
                'title' => 'Недостаточная фотофиксация армирования кладки',
                'description' => 'Перед закрытием сменного объема требуется добавить фотографии рядов с сеткой и привязкой к осям.',
                'severity' => 'major',
                'status' => 'in_progress',
                'task' => 'quality',
                'work_index' => 3,
                'due_days' => 0,
                'location' => $scope === 'GP' ? '1 этаж, оси Г-Д/4-5' : '1 этаж, оси В-Г/2-3',
                'history' => ['open', 'assigned', 'in_progress'],
            ],
            [
                'number' => "BH-QC-{$scope}-005",
                'title' => 'Сколы кирпича на лицевой поверхности ряда',
                'description' => 'Поврежденные кирпичи заменены до приемки захватки, повторный осмотр замечаний не выявил.',
                'severity' => 'minor',
                'status' => 'resolved',
                'task' => 'masonry_outer',
                'work_index' => 1,
                'due_days' => -12,
                'location' => $scope === 'GP' ? '1 этаж, южный фасад' : '1 этаж, захватка А',
                'resolved_at' => $this->now->copy()->subDays(11),
                'verified_at' => $this->now->copy()->subDays(10),
                'history' => ['open', 'assigned', 'in_progress', 'ready_for_review', 'resolved'],
            ],
            [
                'number' => "BH-QC-{$scope}-006",
                'title' => 'Акт скрытых работ по перемычкам требует подписи',
                'description' => 'Каркасы проверены, но пакет исполнительной документации не закрыт со стороны ответственного ПТО.',
                'severity' => 'major',
                'status' => 'assigned',
                'task' => 'lintels',
                'work_index' => 5,
                'due_days' => 1,
                'location' => 'Проемы ПР-1 - ПР-4, первый этаж',
                'history' => ['open', 'assigned'],
            ],
            [
                'number' => "BH-QC-{$scope}-007",
                'title' => 'Проверить ширину опирания плит до допуска крана',
                'description' => 'Замечание связано с будущей задачей графика: без подтверждения опорных зон монтаж плит нельзя выпускать в дневной план.',
                'severity' => 'critical',
                'status' => 'assigned',
                'task' => 'slabs',
                'work_index' => 2,
                'due_days' => 3,
                'location' => 'Армопояс первого этажа, оси А-Е',
                'history' => ['open', 'assigned'],
            ],
        ];

        foreach ($defects as $defect) {
            $completedWork = $completedWorks[$defect['work_index']] ?? null;
            $completedWorkId = is_array($completedWork) ? (int) ($completedWork['id'] ?? 0) : 0;
            $journalEntryId = $completedWorkId > 0 && Schema::hasTable('completed_works')
                ? (int) DB::table('completed_works')->where('id', $completedWorkId)->value('journal_entry_id')
                : null;

            $defectId = $this->upsert('quality_defects', [
                'organization_id' => $organizationId,
                'defect_number' => $defect['number'],
            ], [
                'project_id' => $projectId,
                'contractor_id' => $contractorId,
                'created_by' => $creatorId,
                'assigned_to' => $assigneeId,
                'title' => $defect['title'],
                'description' => $defect['description'],
                'severity' => $defect['severity'],
                'status' => $defect['status'],
                'location_name' => $defect['location'],
                'schedule_task_id' => $scheduleTasks[$defect['task']] ?? null,
                'construction_journal_entry_id' => $journalEntryId ?: null,
                'completed_work_id' => $completedWorkId ?: null,
                'due_date' => $this->now->copy()->addDays($defect['due_days'])->toDateString(),
                'inspection_required' => true,
                'resolved_at' => $defect['resolved_at'] ?? null,
                'verified_at' => $defect['verified_at'] ?? null,
                'metadata' => $this->json([
                    'scenario' => 'brick_house',
                    'scope' => $scope,
                    'project_id' => $projectId,
                ]),
            ]);

            foreach (['before', $defect['status'] === 'resolved' ? 'after' : 'control'] as $photoType) {
                $this->upsert('quality_defect_photos', [
                    'quality_defect_id' => $defectId,
                    'type' => $photoType,
                ], [
                    'organization_id' => $organizationId,
                    'uploaded_by' => $creatorId,
                    'url' => "https://placehold.co/1200x800/png?text=Brick+House+{$scope}+QC",
                    'caption' => $photoType === 'before' ? 'Фотофиксация замечания' : 'Контрольный снимок',
                    'metadata' => $this->json(['scenario' => 'brick_house']),
                ]);
            }

            $previous = null;
            foreach ($defect['history'] as $index => $status) {
                $changedBy = $status === 'resolved' ? $managerId : ($status === 'assigned' ? $assigneeId : $creatorId);
                $comment = match ($status) {
                    'open' => 'Замечание зарегистрировано ПТО',
                    'assigned' => 'Назначен ответственный за устранение',
                    'in_progress' => 'Работы по устранению начаты',
                    'ready_for_review' => 'Ответственный передал результат на проверку',
                    'resolved' => 'Замечание подтверждено и закрыто',
                    default => 'Статус обновлен',
                };

                $this->upsert('quality_defect_status_history', [
                    'quality_defect_id' => $defectId,
                    'to_status' => $status,
                    'comment' => $comment,
                ], [
                    'organization_id' => $organizationId,
                    'from_status' => $previous,
                    'changed_by' => $changedBy,
                    'changed_at' => $this->now->copy()->subDays(8 - $index),
                ]);

                $previous = $status;
            }

            $this->totals['quality_defects']++;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $completedWorks
     */
    private function seedPerformanceAct(
        int $contractId,
        int $projectId,
        int $createdByUserId,
        int $approvedByUserId,
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
            'created_by_user_id' => $createdByUserId,
            'submitted_by_user_id' => $createdByUserId,
            'submitted_at' => $this->now->copy()->subDays(3)->addHours(2),
            'approved_by_user_id' => $approvedByUserId,
            'rejected_by_user_id' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
            'signed_by_user_id' => $approvedByUserId,
            'signed_at' => $this->now->copy()->subDays(2)->addHours(4),
            'locked_by_user_id' => $approvedByUserId,
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
                'created_by' => $createdByUserId,
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
     * @return array{
     *     general: array{period_id: int, scenario_id: int, centers: array<string, int>, articles: array<string, int>, versions: array<string, int>},
     *     contractor: array{period_id: int, scenario_id: int, centers: array<string, int>, articles: array<string, int>, versions: array<string, int>},
     *     payment_links: array<string, array{budget_article_id: int, responsibility_center_id: int}>
     * }
     */
    private function seedBudgetingDemo(
        int $projectId,
        array $general,
        array $contractor,
        array $contracts,
        array $contractors
    ): array {
        $generalBudgeting = $this->seedOrganizationBudgeting(
            organizationId: (int) $general['organization_id'],
            projectId: $projectId,
            ownerUserId: $this->actorId($general, 'project_manager'),
            approverUserId: $this->actorId($general, 'accountant'),
            contractId: $contracts['general_contract_id'],
            counterpartyId: $contractors['contractor_in_general_id'],
            scope: 'GP'
        );

        $contractorBudgeting = $this->seedOrganizationBudgeting(
            organizationId: (int) $contractor['organization_id'],
            projectId: $projectId,
            ownerUserId: $this->actorId($contractor, 'work_manager'),
            approverUserId: $this->actorId($contractor, 'accountant'),
            contractId: $contracts['contractor_contract_id'],
            counterpartyId: $contractors['general_in_contractor_id'],
            scope: 'SUB'
        );

        return [
            'general' => $generalBudgeting,
            'contractor' => $contractorBudgeting,
            'payment_links' => [
                'gp_advance' => [
                    'budget_article_id' => $generalBudgeting['articles']['bdds_subcontract'],
                    'responsibility_center_id' => $generalBudgeting['centers']['contracts'],
                ],
                'gp_act_payment' => [
                    'budget_article_id' => $generalBudgeting['articles']['bdds_subcontract'],
                    'responsibility_center_id' => $generalBudgeting['centers']['contracts'],
                ],
                'gp_materials' => [
                    'budget_article_id' => $generalBudgeting['articles']['bdds_materials'],
                    'responsibility_center_id' => $generalBudgeting['centers']['supply'],
                ],
                'gp_rebar_topup' => [
                    'budget_article_id' => $generalBudgeting['articles']['bdds_materials'],
                    'responsibility_center_id' => $generalBudgeting['centers']['supply'],
                ],
                'gp_slab_supplier_invoice' => [
                    'budget_article_id' => $generalBudgeting['articles']['bdds_materials'],
                    'responsibility_center_id' => $generalBudgeting['centers']['supply'],
                ],
                'gp_roofing_preorder' => [
                    'budget_article_id' => $generalBudgeting['articles']['bdds_materials'],
                    'responsibility_center_id' => $generalBudgeting['centers']['supply'],
                ],
                'sub_advance_income' => [
                    'budget_article_id' => $contractorBudgeting['articles']['bdds_income'],
                    'responsibility_center_id' => $contractorBudgeting['centers']['works'],
                ],
                'sub_act_invoice' => [
                    'budget_article_id' => $contractorBudgeting['articles']['bdds_income'],
                    'responsibility_center_id' => $contractorBudgeting['centers']['works'],
                ],
                'sub_mesh_restock' => [
                    'budget_article_id' => $contractorBudgeting['articles']['bdds_materials'],
                    'responsibility_center_id' => $contractorBudgeting['centers']['supply'],
                ],
                'sub_mortar_today' => [
                    'budget_article_id' => $contractorBudgeting['articles']['bdds_materials'],
                    'responsibility_center_id' => $contractorBudgeting['centers']['supply'],
                ],
                'sub_rebar_topup' => [
                    'budget_article_id' => $contractorBudgeting['articles']['bdds_materials'],
                    'responsibility_center_id' => $contractorBudgeting['centers']['supply'],
                ],
            ],
        ];
    }

    /**
     * @return array{period_id: int, scenario_id: int, centers: array<string, int>, articles: array<string, int>, versions: array<string, int>}
     */
    private function seedOrganizationBudgeting(
        int $organizationId,
        int $projectId,
        int $ownerUserId,
        int $approverUserId,
        int $contractId,
        int $counterpartyId,
        string $scope
    ): array {
        $start = $this->now->copy()->subMonths(5)->startOfMonth();
        $end = $this->now->copy()->addMonths(7)->endOfMonth();
        $periodId = $this->upsert('budget_periods', [
            'organization_id' => $organizationId,
            'code' => "BH-{$scope}-2026",
        ], [
            'name' => 'Бюджет проекта "Лесной двор" 2026',
            'period_type' => 'project',
            'starts_at' => $start->toDateString(),
            'ends_at' => $end->toDateString(),
            'status' => 'open',
            'created_by' => $ownerUserId,
            'updated_by' => $approverUserId,
        ]);
        $this->totals['budget_periods']++;

        $scenarioId = $this->upsert('budget_scenarios', [
            'organization_id' => $organizationId,
            'code' => "BH-{$scope}-BASE",
        ], [
            'name' => 'Базовый сценарий "Лесной двор"',
            'scenario_type' => 'base',
            'is_default' => true,
            'is_active' => true,
            'created_by' => $ownerUserId,
            'updated_by' => $approverUserId,
        ]);

        $centers = $this->seedBudgetCenters($organizationId, $projectId, $ownerUserId, $approverUserId, $scope);
        $articles = $this->seedBudgetArticles($organizationId, $ownerUserId, $approverUserId, $scope);
        $versions = [
            'bdr' => $this->seedBudgetVersion(
                organizationId: $organizationId,
                periodId: $periodId,
                scenarioId: $scenarioId,
                userId: $approverUserId,
                kind: 'bdr',
                name: $scope === 'GP'
                    ? 'БДР генподряда по кирпичному дому'
                    : 'БДР подрядчика по кладочным работам'
            ),
            'bdds' => $this->seedBudgetVersion(
                organizationId: $organizationId,
                periodId: $periodId,
                scenarioId: $scenarioId,
                userId: $approverUserId,
                kind: 'bdds',
                name: $scope === 'GP'
                    ? 'БДДС генподряда по кирпичному дому'
                    : 'БДДС подрядчика по кладочным работам'
            ),
        ];

        if ($scope === 'GP') {
            $this->seedGeneralContractorBudgetLines($versions, $articles, $centers, $projectId, $contractId, $counterpartyId, $ownerUserId);
        } else {
            $this->seedContractorBudgetLines($versions, $articles, $centers, $projectId, $contractId, $counterpartyId, $ownerUserId);
        }

        return [
            'period_id' => $periodId,
            'scenario_id' => $scenarioId,
            'centers' => $centers,
            'articles' => $articles,
            'versions' => $versions,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function seedBudgetCenters(int $organizationId, int $projectId, int $ownerUserId, int $approverUserId, string $scope): array
    {
        $definitions = $scope === 'GP'
            ? [
                'project' => ['code' => 'BH-GP-CFO-PROJECT', 'name' => 'Проект "Лесной двор"', 'type' => 'project', 'owner' => $ownerUserId],
                'contracts' => ['code' => 'BH-GP-CFO-CONTRACTS', 'name' => 'Договоры и расчеты с подрядчиком', 'type' => 'contract', 'owner' => $approverUserId],
                'supply' => ['code' => 'BH-GP-CFO-SUPPLY', 'name' => 'Снабжение и склад объекта', 'type' => 'supply', 'owner' => $approverUserId],
                'site' => ['code' => 'BH-GP-CFO-SITE', 'name' => 'Производственный участок генподряда', 'type' => 'site', 'owner' => $ownerUserId],
            ]
            : [
                'works' => ['code' => 'BH-SUB-CFO-WORKS', 'name' => 'Кладочные работы "Лесной двор"', 'type' => 'project', 'owner' => $ownerUserId],
                'supply' => ['code' => 'BH-SUB-CFO-SUPPLY', 'name' => 'Материалы подрядчика на объекте', 'type' => 'supply', 'owner' => $approverUserId],
                'site' => ['code' => 'BH-SUB-CFO-SITE', 'name' => 'Бригада кладки и ПТО', 'type' => 'site', 'owner' => $ownerUserId],
            ];

        $centers = [];
        foreach ($definitions as $key => $definition) {
            $centers[$key] = $this->upsert('responsibility_centers', [
                'organization_id' => $organizationId,
                'code' => $definition['code'],
            ], [
                'center_type' => $definition['type'],
                'name' => $definition['name'],
                'owner_user_id' => $definition['owner'],
                'approver_user_id' => $approverUserId,
                'linked_entity_type' => 'project',
                'linked_entity_id' => $projectId,
                'active_from' => $this->now->copy()->subMonths(5)->startOfMonth()->toDateString(),
                'active_to' => null,
                'is_active' => true,
                'created_by' => $ownerUserId,
                'updated_by' => $approverUserId,
            ]);
        }

        return $centers;
    }

    /**
     * @return array<string, int>
     */
    private function seedBudgetArticles(int $organizationId, int $ownerUserId, int $approverUserId, string $scope): array
    {
        $definitions = [
            'bdr_revenue' => ['code' => "BH-{$scope}-BDR-REV-CONTRACT", 'name' => 'Выручка по проекту "Лесной двор"', 'kind' => 'bdr', 'direction' => 'income'],
            'bdr_subcontract' => ['code' => "BH-{$scope}-BDR-COST-SUBCONTRACT", 'name' => 'Субподрядные работы', 'kind' => 'bdr', 'direction' => 'expense'],
            'bdr_materials' => ['code' => "BH-{$scope}-BDR-COST-MATERIALS", 'name' => 'Материалы и поставки', 'kind' => 'bdr', 'direction' => 'expense'],
            'bdr_labor' => ['code' => "BH-{$scope}-BDR-COST-LABOR", 'name' => 'Оплата труда и бригады', 'kind' => 'bdr', 'direction' => 'expense'],
            'bdr_equipment' => ['code' => "BH-{$scope}-BDR-COST-EQUIPMENT", 'name' => 'Механизация и техника', 'kind' => 'bdr', 'direction' => 'expense'],
            'bdr_overhead' => ['code' => "BH-{$scope}-BDR-COST-OVERHEAD", 'name' => 'Управление проектом и ПТО', 'kind' => 'bdr', 'direction' => 'expense'],
            'bdds_income' => ['code' => "BH-{$scope}-BDDS-IN-CONTRACT", 'name' => 'Поступления по договору', 'kind' => 'bdds', 'direction' => 'inflow'],
            'bdds_subcontract' => ['code' => "BH-{$scope}-BDDS-OUT-SUBCONTRACT", 'name' => 'Оплата подрядных работ', 'kind' => 'bdds', 'direction' => 'outflow'],
            'bdds_materials' => ['code' => "BH-{$scope}-BDDS-OUT-MATERIALS", 'name' => 'Оплата материалов', 'kind' => 'bdds', 'direction' => 'outflow'],
            'bdds_labor' => ['code' => "BH-{$scope}-BDDS-OUT-LABOR", 'name' => 'Выплаты бригадам', 'kind' => 'bdds', 'direction' => 'outflow'],
            'bdds_overhead' => ['code' => "BH-{$scope}-BDDS-OUT-OVERHEAD", 'name' => 'Административные платежи проекта', 'kind' => 'bdds', 'direction' => 'outflow'],
        ];

        $articles = [];
        foreach ($definitions as $key => $definition) {
            $articles[$key] = $this->upsert('budget_articles', [
                'organization_id' => $organizationId,
                'code' => $definition['code'],
            ], [
                'name' => $definition['name'],
                'budget_kind' => $definition['kind'],
                'flow_direction' => $definition['direction'],
                'is_leaf' => true,
                'is_active' => true,
                'created_by' => $ownerUserId,
                'updated_by' => $approverUserId,
            ]);
        }

        return $articles;
    }

    private function seedBudgetVersion(
        int $organizationId,
        int $periodId,
        int $scenarioId,
        int $userId,
        string $kind,
        string $name
    ): int {
        $versionId = $this->upsert('budget_versions', [
            'organization_id' => $organizationId,
            'budget_period_id' => $periodId,
            'scenario_id' => $scenarioId,
            'budget_kind' => $kind,
            'version_number' => 1,
        ], [
            'name' => $name,
            'description' => 'Активная версия бюджета для демонстрационного проекта "Кирпичный дом Лесной двор"',
            'status' => 'active',
            'submitted_at' => $this->now->copy()->subMonths(4)->subDays(20),
            'submitted_by' => $userId,
            'approved_at' => $this->now->copy()->subMonths(4)->subDays(18),
            'approved_by' => $userId,
            'activated_at' => $this->now->copy()->subMonths(4)->subDays(17),
            'activated_by' => $userId,
            'created_by' => $userId,
            'updated_by' => $userId,
            'workflow_history' => $this->json([
                ['action' => 'submit', 'from_status' => 'draft', 'to_status' => 'on_approval', 'user_id' => $userId],
                ['action' => 'approve', 'from_status' => 'on_approval', 'to_status' => 'approved', 'user_id' => $userId],
                ['action' => 'activate', 'from_status' => 'approved', 'to_status' => 'active', 'user_id' => $userId],
            ]),
        ]);
        $this->totals['budget_versions']++;

        return $versionId;
    }

    /**
     * @param array<string, int> $versions
     * @param array<string, int> $articles
     * @param array<string, int> $centers
     */
    private function seedGeneralContractorBudgetLines(
        array $versions,
        array $articles,
        array $centers,
        int $projectId,
        int $contractId,
        int $counterpartyId,
        int $userId
    ): void {
        $this->seedBudgetLineWithAmounts($versions['bdr'], $articles['bdr_revenue'], $centers['project'], $projectId, null, null, 'Доходы по договору с заказчиком', $this->monthlyAmounts([
            -5 => 12_630_000.00,
            -3 => 16_840_000.00,
            0 => 21_050_000.00,
            2 => 16_840_000.00,
            5 => 16_840_000.00,
        ], 1.01), $userId, ['scope' => 'GP', 'role' => 'revenue']);
        $this->seedBudgetLineWithAmounts($versions['bdr'], $articles['bdr_subcontract'], $centers['contracts'], $projectId, $contractId, $counterpartyId, 'Субподряд на кладку и армопояса', $this->monthlyAmounts([
            -4 => 7_456_000.00,
            -1 => 8_620_000.00,
            1 => 9_584_000.00,
            3 => 7_072_000.00,
            5 => 4_548_000.00,
        ], 1.00), $userId, ['scope' => 'GP', 'role' => 'subcontract']);
        $this->seedBudgetLineWithAmounts($versions['bdr'], $articles['bdr_materials'], $centers['supply'], $projectId, null, null, 'Кирпич, бетон, арматура, плиты и кровля', $this->monthlyAmounts([
            -5 => 2_400_000.00,
            -4 => 3_200_000.00,
            -2 => 3_100_000.00,
            0 => 3_400_000.00,
            2 => 2_600_000.00,
            4 => 2_100_000.00,
            6 => 1_800_000.00,
        ], 1.03), $userId, ['scope' => 'GP', 'role' => 'materials']);
        $this->seedBudgetLineWithAmounts($versions['bdr'], $articles['bdr_equipment'], $centers['site'], $projectId, null, null, 'Экскаватор, автокран и погрузка', $this->monthlyAmounts([
            -5 => 900_000.00,
            -3 => 800_000.00,
            0 => 1_400_000.00,
            1 => 1_100_000.00,
            3 => 1_000_000.00,
        ], 1.02), $userId, ['scope' => 'GP', 'role' => 'equipment']);
        $this->seedBudgetLineWithAmounts($versions['bdr'], $articles['bdr_overhead'], $centers['project'], $projectId, null, null, 'ПТО, управление проектом и контроль качества', $this->spreadMonthlyAmounts(8_100_000.00, -5, 7, 1.00), $userId, ['scope' => 'GP', 'role' => 'overhead']);

        $this->seedBudgetLineWithAmounts($versions['bdds'], $articles['bdds_income'], $centers['project'], $projectId, null, null, 'Поступления от заказчика по этапам строительства', $this->monthlyAmounts([
            -5 => 12_630_000.00,
            -3 => 16_840_000.00,
            0 => 21_050_000.00,
            3 => 16_840_000.00,
            6 => 16_840_000.00,
        ], 1.00), $userId, ['scope' => 'GP', 'cash_flow' => 'income']);
        $this->seedBudgetLineWithAmounts($versions['bdds'], $articles['bdds_subcontract'], $centers['contracts'], $projectId, $contractId, $counterpartyId, 'Платежи подрядчику по договору ГП-ПДР-ЛД-02/2026', $this->monthlyAmounts([
            -4 => 7_456_000.00,
            0 => 8_620_000.00,
            2 => 9_584_000.00,
            4 => 7_072_000.00,
            6 => 4_548_000.00,
        ], 1.00), $userId, ['scope' => 'GP', 'cash_flow' => 'subcontract']);
        $this->seedBudgetLineWithAmounts($versions['bdds'], $articles['bdds_materials'], $centers['supply'], $projectId, null, null, 'Закупка материалов по заявкам с объекта', $this->monthlyAmounts([
            -5 => 2_400_000.00,
            -4 => 3_200_000.00,
            -2 => 3_100_000.00,
            0 => 3_400_000.00,
            2 => 2_600_000.00,
            4 => 2_100_000.00,
            6 => 1_800_000.00,
        ], 1.02), $userId, ['scope' => 'GP', 'cash_flow' => 'materials']);
        $this->seedBudgetLineWithAmounts($versions['bdds'], $articles['bdds_overhead'], $centers['project'], $projectId, null, null, 'Административные платежи проекта', $this->spreadMonthlyAmounts(8_100_000.00, -5, 7, 1.00), $userId, ['scope' => 'GP', 'cash_flow' => 'overhead']);
    }

    /**
     * @param array<string, int> $versions
     * @param array<string, int> $articles
     * @param array<string, int> $centers
     */
    private function seedContractorBudgetLines(
        array $versions,
        array $articles,
        array $centers,
        int $projectId,
        int $contractId,
        int $counterpartyId,
        int $userId
    ): void {
        $this->seedBudgetLineWithAmounts($versions['bdr'], $articles['bdr_revenue'], $centers['works'], $projectId, $contractId, $counterpartyId, 'Выручка по кладочным работам', $this->monthlyAmounts([
            -4 => 7_456_000.00,
            0 => 8_620_000.00,
            2 => 9_584_000.00,
            4 => 7_072_000.00,
            6 => 4_548_000.00,
        ], 1.00), $userId, ['scope' => 'SUB', 'role' => 'revenue']);
        $this->seedBudgetLineWithAmounts($versions['bdr'], $articles['bdr_labor'], $centers['site'], $projectId, null, null, 'Бригады каменщиков и мастера участка', $this->monthlyAmounts([
            -3 => 3_000_000.00,
            -2 => 2_400_000.00,
            -1 => 2_600_000.00,
            0 => 2_500_000.00,
            1 => 2_200_000.00,
            2 => 1_500_000.00,
            3 => 1_000_000.00,
        ], 1.01), $userId, ['scope' => 'SUB', 'role' => 'labor']);
        $this->seedBudgetLineWithAmounts($versions['bdr'], $articles['bdr_materials'], $centers['supply'], $projectId, null, null, 'Раствор, сетка, арматура и расходные материалы', $this->monthlyAmounts([
            -3 => 1_400_000.00,
            -1 => 1_700_000.00,
            0 => 1_500_000.00,
            1 => 1_400_000.00,
            2 => 1_200_000.00,
            3 => 1_200_000.00,
        ], 1.03), $userId, ['scope' => 'SUB', 'role' => 'materials']);
        $this->seedBudgetLineWithAmounts($versions['bdr'], $articles['bdr_equipment'], $centers['site'], $projectId, null, null, 'Автокран, вибраторы и малая механизация', $this->monthlyAmounts([
            -2 => 200_000.00,
            0 => 350_000.00,
            1 => 450_000.00,
            2 => 250_000.00,
            3 => 150_000.00,
        ], 1.00), $userId, ['scope' => 'SUB', 'role' => 'equipment']);
        $this->seedBudgetLineWithAmounts($versions['bdr'], $articles['bdr_overhead'], $centers['works'], $projectId, null, null, 'ПТО, управление и исполнительная документация', $this->spreadMonthlyAmounts(3_200_000.00, -4, 4, 1.00), $userId, ['scope' => 'SUB', 'role' => 'overhead']);

        $this->seedBudgetLineWithAmounts($versions['bdds'], $articles['bdds_income'], $centers['works'], $projectId, $contractId, $counterpartyId, 'Поступления от генподрядчика по договору', $this->monthlyAmounts([
            -4 => 7_456_000.00,
            0 => 8_620_000.00,
            2 => 9_584_000.00,
            4 => 7_072_000.00,
            6 => 4_548_000.00,
        ], 1.00), $userId, ['scope' => 'SUB', 'cash_flow' => 'income']);
        $this->seedBudgetLineWithAmounts($versions['bdds'], $articles['bdds_labor'], $centers['site'], $projectId, null, null, 'Выплаты бригадам по графику кладки', $this->monthlyAmounts([
            -3 => 3_000_000.00,
            -2 => 2_400_000.00,
            -1 => 2_600_000.00,
            0 => 2_500_000.00,
            1 => 2_200_000.00,
            2 => 1_500_000.00,
            3 => 1_000_000.00,
        ], 1.00), $userId, ['scope' => 'SUB', 'cash_flow' => 'labor']);
        $this->seedBudgetLineWithAmounts($versions['bdds'], $articles['bdds_materials'], $centers['supply'], $projectId, null, null, 'Оплата раствора, сетки и арматуры', $this->monthlyAmounts([
            -3 => 1_400_000.00,
            -1 => 1_700_000.00,
            0 => 1_500_000.00,
            1 => 1_400_000.00,
            2 => 1_200_000.00,
            3 => 1_200_000.00,
        ], 1.02), $userId, ['scope' => 'SUB', 'cash_flow' => 'materials']);
        $this->seedBudgetLineWithAmounts($versions['bdds'], $articles['bdds_overhead'], $centers['works'], $projectId, null, null, 'ПТО и административные платежи подрядчика', $this->spreadMonthlyAmounts(3_200_000.00, -4, 4, 1.00), $userId, ['scope' => 'SUB', 'cash_flow' => 'overhead']);
    }

    /**
     * @param list<array{month: string, plan: float, forecast: float}> $amounts
     * @param array<string, mixed> $metadata
     */
    private function seedBudgetLineWithAmounts(
        int $versionId,
        int $articleId,
        int $centerId,
        int $projectId,
        ?int $contractId,
        ?int $counterpartyId,
        string $description,
        array $amounts,
        int $userId,
        array $metadata
    ): void {
        $lineId = $this->upsert('budget_lines', [
            'budget_version_id' => $versionId,
            'budget_article_id' => $articleId,
            'responsibility_center_id' => $centerId,
            'project_id' => $projectId,
            'contract_id' => $contractId,
            'counterparty_id' => $counterpartyId,
            'currency' => 'RUB',
            'description' => $description,
        ], [
            'metadata' => $this->json($metadata + ['scenario' => 'brick_house']),
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
        $this->totals['budget_lines']++;

        foreach ($amounts as $amount) {
            $this->upsert('budget_amounts', [
                'budget_line_id' => $lineId,
                'month' => $amount['month'],
            ], [
                'plan_amount' => $amount['plan'],
                'forecast_amount' => $amount['forecast'],
                'currency' => 'RUB',
            ]);
            $this->totals['budget_amounts']++;
        }
    }

    /**
     * @param array<int, float> $amountsByOffset
     * @return list<array{month: string, plan: float, forecast: float}>
     */
    private function monthlyAmounts(array $amountsByOffset, float $forecastFactor = 1.0): array
    {
        $amounts = [];
        foreach ($amountsByOffset as $offset => $planAmount) {
            $amounts[] = [
                'month' => $this->budgetMonth((int) $offset),
                'plan' => round((float) $planAmount, 2),
                'forecast' => round((float) $planAmount * $forecastFactor, 2),
            ];
        }

        return $amounts;
    }

    /**
     * @return list<array{month: string, plan: float, forecast: float}>
     */
    private function spreadMonthlyAmounts(float $totalAmount, int $startOffset, int $endOffset, float $forecastFactor = 1.0): array
    {
        $offsets = range($startOffset, $endOffset);
        $monthlyAmount = round($totalAmount / count($offsets), 2);
        $remaining = $totalAmount;
        $amounts = [];

        foreach ($offsets as $index => $offset) {
            $plan = $index === array_key_last($offsets)
                ? round($remaining, 2)
                : $monthlyAmount;
            $remaining = round($remaining - $plan, 2);
            $amounts[] = [
                'month' => $this->budgetMonth((int) $offset),
                'plan' => $plan,
                'forecast' => round($plan * $forecastFactor, 2),
            ];
        }

        return $amounts;
    }

    private function budgetMonth(int $offset): string
    {
        return $this->now->copy()->addMonthsNoOverflow($offset)->startOfMonth()->toDateString();
    }

    /**
     * @param array<string, array<string, mixed>> $documents
     * @param array{payment_links?: array<string, array{budget_article_id: int, responsibility_center_id: int}>} $budgeting
     * @return array<string, array<string, mixed>>
     */
    private function applyBudgetingToPayments(array $documents, array $budgeting): array
    {
        foreach (($budgeting['payment_links'] ?? []) as $documentKey => $link) {
            if (!isset($documents[$documentKey])) {
                continue;
            }

            $documents[$documentKey]['budget_article_id'] = $link['budget_article_id'];
            $documents[$documentKey]['responsibility_center_id'] = $link['responsibility_center_id'];
        }

        return $documents;
    }

    /**
     * @param array<string, mixed> $general
     * @param array<string, mixed> $contractor
     * @param array{general: array<string, int>, contractor: array<string, int>} $materials
     * @param array{general: array<string, int>, contractor: array<string, int>} $warehouses
     * @param array{general: array<string, int>, contractor: array<string, int>} $siteRequests
     * @return array{site_request_links: array<int, array{purchase_request_id: int, purchase_order_id: int}>, purchase_requests: array<string, int>, purchase_orders: array<string, int>}
     */
    private function seedProcurement(
        int $projectId,
        array $general,
        array $contractor,
        array $materials,
        array $warehouses,
        array $siteRequests
    ): array {
        if (
            !Schema::hasTable('suppliers')
            || !Schema::hasTable('purchase_requests')
            || !Schema::hasTable('purchase_orders')
        ) {
            return [
                'site_request_links' => [],
                'purchase_requests' => [],
                'purchase_orders' => [],
            ];
        }

        $generalProcurement = $this->seedProcurementContour(
            projectId: $projectId,
            account: $general,
            scope: 'GP',
            materials: $materials['general'],
            warehouse: $warehouses['general'],
            siteRequests: $siteRequests['general'],
            buyerUserId: $this->actorId($general, 'supply_manager'),
            approverUserId: $this->actorId($general, 'project_manager')
        );

        $contractorProcurement = $this->seedProcurementContour(
            projectId: $projectId,
            account: $contractor,
            scope: 'SUB',
            materials: $materials['contractor'],
            warehouse: $warehouses['contractor'],
            siteRequests: $siteRequests['contractor'],
            buyerUserId: $this->actorId($contractor, 'storekeeper'),
            approverUserId: $this->actorId($contractor, 'work_manager')
        );

        return [
            'site_request_links' => $generalProcurement['site_request_links'] + $contractorProcurement['site_request_links'],
            'purchase_requests' => $generalProcurement['purchase_requests'] + $contractorProcurement['purchase_requests'],
            'purchase_orders' => $generalProcurement['purchase_orders'] + $contractorProcurement['purchase_orders'],
        ];
    }

    /**
     * @param array<string, mixed> $account
     * @param array<string, int> $materials
     * @param array<string, int> $warehouse
     * @param array<string, int> $siteRequests
     * @return array{site_request_links: array<int, array{purchase_request_id: int, purchase_order_id: int}>, purchase_requests: array<string, int>, purchase_orders: array<string, int>}
     */
    private function seedProcurementContour(
        int $projectId,
        array $account,
        string $scope,
        array $materials,
        array $warehouse,
        array $siteRequests,
        int $buyerUserId,
        int $approverUserId
    ): array {
        $organizationId = (int) $account['organization_id'];
        $links = [];
        $purchaseRequestIds = [];
        $purchaseOrderIds = [];

        foreach ($this->procurementScenarios($scope) as $scenario) {
            $siteRequestId = $siteRequests[$scenario['site_request']] ?? 0;
            $materialId = $materials[$scenario['material']] ?? 0;

            if ($siteRequestId === 0 || $materialId === 0) {
                continue;
            }

            $supplierId = $this->upsert('suppliers', [
                'organization_id' => $organizationId,
                'code' => $scenario['supplier_code'],
            ], [
                'name' => $scenario['supplier_name'],
                'contact_person' => $scenario['supplier_contact'],
                'phone' => $scenario['supplier_phone'],
                'email' => $scenario['supplier_email'],
                'address' => $scenario['supplier_address'],
                'tax_number' => $scenario['supplier_tax_number'],
                'description' => 'Поставщик демо-сценария закупок кирпичного дома',
                'is_active' => true,
                'additional_info' => $this->json([
                    'demo' => true,
                    'scenario' => 'brick_house',
                    'scope' => $scope,
                    'specialization' => $scenario['supplier_specialization'],
                ]),
            ]);

            if ($supplierId === 0) {
                continue;
            }

            $this->totals['suppliers']++;

            $supplierSnapshot = $this->supplierSnapshot($scenario, $supplierId);
            $supplierPartyId = $this->upsert('supplier_parties', [
                'organization_id' => $organizationId,
                'type' => 'registered',
                'registered_supplier_id' => $supplierId,
            ], [
                'status' => 'linked',
                'external_supplier_contact_id' => null,
                'display_name' => $scenario['supplier_name'],
                'contact_name' => $scenario['supplier_contact'],
                'email' => $scenario['supplier_email'],
                'normalized_email' => strtolower($scenario['supplier_email']),
                'phone' => $scenario['supplier_phone'],
                'tax_id' => $scenario['supplier_tax_number'],
                'snapshot' => $this->json($supplierSnapshot),
                'linked_at' => $this->now->copy()->subDays(34),
            ]);

            $subtotalAmount = round((float) $scenario['quantity'] * (float) $scenario['unit_price'], 2);
            $deliveryAmount = (float) $scenario['delivery_amount'];
            $totalAmount = round($subtotalAmount + $deliveryAmount, 2);
            $vatAmount = round($totalAmount * 20 / 120, 2);
            $neededBy = $this->now->copy()->addDays($scenario['needed_offset']);

            $purchaseRequestId = $this->upsert('purchase_requests', [
                'organization_id' => $organizationId,
                'request_number' => $scenario['purchase_request_number'],
            ], [
                'site_request_id' => $siteRequestId,
                'assigned_to' => $buyerUserId,
                'status' => 'approved',
                'needed_by' => $neededBy->toDateString(),
                'budget_amount' => $totalAmount,
                'budget_currency' => 'RUB',
                'notes' => $scenario['purchase_request_notes'],
                'metadata' => $this->json([
                    'demo' => true,
                    'scenario' => 'brick_house',
                    'scope' => $scope,
                    'site_request_key' => $scenario['site_request'],
                    'project_id' => $projectId,
                ]),
            ]);

            if ($purchaseRequestId === 0) {
                continue;
            }

            $purchaseRequestIds[$scenario['key']] = $purchaseRequestId;
            $this->totals['purchase_requests']++;

            $purchaseRequestLineId = $this->upsert('purchase_request_lines', [
                'purchase_request_id' => $purchaseRequestId,
                'name' => $scenario['material_name'],
            ], [
                'material_id' => $materialId,
                'quantity' => $scenario['quantity'],
                'unit' => $scenario['unit'],
                'specification' => $scenario['specification'],
                'needed_by' => $neededBy->toDateString(),
                'metadata' => $this->json([
                    'demo' => true,
                    'source' => 'site_request',
                    'site_request_id' => $siteRequestId,
                ]),
            ]);

            $supplierRequestId = $this->upsert('supplier_requests', [
                'request_number' => $scenario['supplier_request_number'],
            ], [
                'organization_id' => $organizationId,
                'purchase_request_id' => $purchaseRequestId,
                'supplier_id' => $supplierId,
                'external_supplier_contact_id' => null,
                'supplier_party_id' => $supplierPartyId ?: null,
                'supplier_snapshot' => $this->json($supplierSnapshot),
                'status' => 'responded',
                'sent_at' => $this->now->copy()->addDays($scenario['sent_offset']),
                'responded_at' => $this->now->copy()->addDays($scenario['proposal_offset']),
                'cancelled_at' => null,
                'comment' => $scenario['supplier_request_comment'],
                'public_token' => hash('sha256', 'brick-house-supplier-request-' . $scenario['supplier_request_number']),
                'public_token_expires_at' => $this->now->copy()->addDays(21),
                'public_opened_at' => $this->now->copy()->addDays($scenario['proposal_offset'])->subHours(3),
                'metadata' => $this->json([
                    'demo' => true,
                    'scenario' => 'brick_house',
                    'scope' => $scope,
                ]),
            ]);

            $supplierRequestLineId = $supplierRequestId > 0
                ? $this->upsert('supplier_request_lines', [
                    'supplier_request_id' => $supplierRequestId,
                    'name' => $scenario['material_name'],
                ], [
                    'purchase_request_line_id' => $purchaseRequestLineId ?: null,
                    'material_id' => $materialId,
                    'quantity' => $scenario['quantity'],
                    'unit' => $scenario['unit'],
                    'specification' => $scenario['specification'],
                    'metadata' => $this->json(['demo' => true, 'scenario' => 'brick_house']),
                ])
                : 0;

            $requestLineSnapshot = $this->requestLineSnapshot(
                lineId: $supplierRequestLineId,
                purchaseRequestLineId: $purchaseRequestLineId,
                materialId: $materialId,
                scenario: $scenario
            );

            $supplierRequestVersionId = $supplierRequestId > 0
                ? $this->upsert('supplier_request_versions', [
                    'supplier_request_id' => $supplierRequestId,
                    'version_number' => 1,
                ], [
                    'organization_id' => $organizationId,
                    'request_snapshot' => $this->json([
                        'id' => $supplierRequestId,
                        'request_number' => $scenario['supplier_request_number'],
                        'status' => 'responded',
                        'sent_at' => $this->now->copy()->addDays($scenario['sent_offset'])->toDateTimeString(),
                        'purchase_request_id' => $purchaseRequestId,
                    ]),
                    'line_snapshot' => $this->json([$requestLineSnapshot]),
                    'supplier_snapshot' => $this->json($supplierSnapshot),
                    'sent_by' => $buyerUserId,
                    'sent_at' => $this->now->copy()->addDays($scenario['sent_offset']),
                ])
                : 0;

            $proposalId = $supplierRequestId > 0
                ? $this->upsert('supplier_proposals', [
                    'proposal_number' => $scenario['proposal_number'],
                ], [
                    'organization_id' => $organizationId,
                    'purchase_order_id' => null,
                    'supplier_request_id' => $supplierRequestId,
                    'supplier_request_version_id' => $supplierRequestVersionId ?: null,
                    'supplier_id' => $supplierId,
                    'external_supplier_contact_id' => null,
                    'supplier_party_id' => $supplierPartyId ?: null,
                    'supplier_snapshot' => $this->json($supplierSnapshot),
                    'proposal_date' => $this->now->copy()->addDays($scenario['proposal_offset'])->toDateString(),
                    'status' => 'accepted',
                    'subtotal_amount' => $subtotalAmount,
                    'delivery_amount' => $deliveryAmount,
                    'vat_amount' => $vatAmount,
                    'total_amount' => $totalAmount,
                    'currency' => 'RUB',
                    'vat_mode' => 'included',
                    'vat_rate' => 20,
                    'valid_until' => $this->now->copy()->addDays($scenario['proposal_offset'] + 14)->toDateString(),
                    'delivery_due_date' => $neededBy->toDateString(),
                    'lead_time_days' => max(1, $scenario['needed_offset'] - $scenario['proposal_offset']),
                    'payment_terms' => $scenario['payment_terms'],
                    'delivery_terms' => $scenario['delivery_terms'],
                    'warranty_terms' => $scenario['warranty_terms'],
                    'items' => $this->json([$requestLineSnapshot + [
                        'unit_price' => $scenario['unit_price'],
                        'total_amount' => $subtotalAmount,
                    ]]),
                    'notes' => $scenario['proposal_notes'],
                    'metadata' => $this->json(['demo' => true, 'scenario' => 'brick_house']),
                ])
                : 0;

            if ($proposalId > 0) {
                $this->totals['supplier_proposals']++;
            }

            $proposalLineId = $proposalId > 0
                ? $this->upsert('supplier_proposal_lines', [
                    'supplier_proposal_id' => $proposalId,
                    'name' => $scenario['material_name'],
                ], [
                    'supplier_request_line_id' => $supplierRequestLineId ?: null,
                    'material_id' => $materialId,
                    'quantity' => $scenario['quantity'],
                    'unit' => $scenario['unit'],
                    'unit_price' => $scenario['unit_price'],
                    'total_amount' => $subtotalAmount,
                    'comment' => $scenario['proposal_line_comment'],
                    'metadata' => $this->json(['demo' => true, 'scenario' => 'brick_house']),
                ])
                : 0;

            $commercialSnapshot = $this->proposalCommercialSnapshot(
                scenario: $scenario,
                proposalId: $proposalId,
                supplierRequestLineId: $supplierRequestLineId,
                proposalLineId: $proposalLineId,
                materialId: $materialId,
                subtotalAmount: $subtotalAmount,
                deliveryAmount: $deliveryAmount,
                vatAmount: $vatAmount,
                totalAmount: $totalAmount,
                deliveryDueDate: $neededBy->toDateString()
            );

            $proposalVersionId = $proposalId > 0
                ? $this->upsert('supplier_proposal_versions', [
                    'supplier_proposal_id' => $proposalId,
                    'version_number' => 1,
                ], [
                    'organization_id' => $organizationId,
                    'commercial_snapshot' => $this->json($commercialSnapshot),
                    'attachment_snapshot' => $this->json([
                        'intake_attachment_ids' => [],
                        'document_name' => $scenario['proposal_number'] . '.pdf',
                    ]),
                    'created_by' => $buyerUserId,
                    'created_at' => $this->now->copy()->addDays($scenario['proposal_offset']),
                ])
                : 0;

            if ($proposalId > 0) {
                $this->upsert('supplier_proposal_intakes', [
                    'supplier_proposal_id' => $proposalId,
                ], [
                    'organization_id' => $organizationId,
                    'supplier_party_id' => $supplierPartyId ?: null,
                    'source' => 'email',
                    'received_at' => $this->now->copy()->addDays($scenario['proposal_offset'])->setTime(11, 20),
                    'entered_by' => $buyerUserId,
                    'external_reference' => 'mail:' . strtolower($scenario['proposal_number']),
                    'comment' => 'КП получено и занесено в демо-контур закупок',
                    'attachment_ids' => $this->json([]),
                ]);
            }

            if ($supplierRequestId > 0 && $proposalId > 0) {
                $this->upsert('supplier_proposal_decisions', [
                    'supplier_request_id' => $supplierRequestId,
                ], [
                    'organization_id' => $organizationId,
                    'winning_supplier_proposal_id' => $proposalId,
                    'winning_supplier_proposal_version_id' => $proposalVersionId ?: null,
                    'cheapest_supplier_proposal_id' => $proposalId,
                    'cheapest_supplier_proposal_version_id' => $proposalVersionId ?: null,
                    'status' => 'approved',
                    'is_lowest_price_selected' => true,
                    'decision_reason' => $scenario['decision_reason'],
                    'comparison_snapshot' => $this->json([
                        [
                            'proposal_id' => $proposalId,
                            'proposal_number' => $scenario['proposal_number'],
                            'supplier_name' => $scenario['supplier_name'],
                            'total_amount' => $totalAmount,
                            'delivery_due_date' => $neededBy->toDateString(),
                            'selected' => true,
                        ],
                    ]),
                    'selected_by' => $approverUserId,
                    'selected_at' => $this->now->copy()->addDays($scenario['proposal_offset'] + 1),
                ]);
            }

            $purchaseOrderId = $this->upsert('purchase_orders', [
                'order_number' => $scenario['order_number'],
            ], [
                'organization_id' => $organizationId,
                'purchase_request_id' => $purchaseRequestId,
                'accepted_supplier_proposal_id' => $proposalId ?: null,
                'accepted_supplier_proposal_version_id' => $proposalVersionId ?: null,
                'supplier_id' => $supplierId,
                'external_supplier_contact_id' => null,
                'supplier_party_id' => $supplierPartyId ?: null,
                'supplier_snapshot' => $this->json($supplierSnapshot),
                'contract_id' => null,
                'order_date' => $this->now->copy()->addDays($scenario['order_offset'])->toDateString(),
                'status' => $scenario['order_status'],
                'total_amount' => $totalAmount,
                'currency' => 'RUB',
                'pricing_source' => 'accepted_supplier_proposal',
                'delivery_date' => $neededBy->toDateString(),
                'sent_at' => $this->now->copy()->addDays($scenario['sent_offset'])->toDateString(),
                'confirmed_at' => $this->now->copy()->addDays($scenario['confirmed_offset'])->toDateString(),
                'notes' => $scenario['order_notes'],
                'metadata' => $this->json([
                    'demo' => true,
                    'scenario' => 'brick_house',
                    'scope' => $scope,
                    'site_request_id' => $siteRequestId,
                    'supplier_request_id' => $supplierRequestId,
                    'supplier_proposal_id' => $proposalId,
                ]),
            ]);

            if ($purchaseOrderId === 0) {
                continue;
            }

            $purchaseOrderIds[$scenario['key']] = $purchaseOrderId;
            $this->totals['purchase_orders']++;

            if ($proposalId > 0 && Schema::hasTable('supplier_proposals')) {
                DB::table('supplier_proposals')
                    ->where('id', $proposalId)
                    ->update($this->withTimestamps('supplier_proposals', ['purchase_order_id' => $purchaseOrderId], true));
            }

            $purchaseOrderItemId = $this->upsert('purchase_order_items', [
                'purchase_order_id' => $purchaseOrderId,
                'material_name' => $scenario['material_name'],
            ], [
                'material_id' => $materialId,
                'quantity' => $scenario['quantity'],
                'unit' => $scenario['unit'],
                'unit_price' => $scenario['unit_price'],
                'total_price' => $subtotalAmount,
                'notes' => 'Позиция заказа поставщику из демо-заявки с объекта',
                'metadata' => $this->json([
                    'demo' => true,
                    'purchase_request_line_id' => $purchaseRequestLineId,
                    'supplier_proposal_line_id' => $proposalLineId,
                ]),
            ]);

            if (($scenario['receipt_quantity'] ?? 0) > 0 && isset($warehouse['warehouse_id']) && $purchaseOrderItemId > 0) {
                $receiptId = $this->upsert('purchase_receipts', [
                    'receipt_number' => $scenario['receipt_number'],
                ], [
                    'organization_id' => $organizationId,
                    'purchase_order_id' => $purchaseOrderId,
                    'warehouse_id' => $warehouse['warehouse_id'],
                    'received_by_user_id' => $buyerUserId,
                    'receipt_date' => $this->now->copy()->addDays($scenario['receipt_offset'])->toDateString(),
                    'status' => 'posted',
                    'notes' => 'Приемка материала по демо-заказу поставщику',
                    'metadata' => $this->json([
                        'demo' => true,
                        'scenario' => 'brick_house',
                        'site_request_id' => $siteRequestId,
                    ]),
                ]);

                if ($receiptId > 0) {
                    $this->upsert('purchase_receipt_lines', [
                        'purchase_receipt_id' => $receiptId,
                        'purchase_order_item_id' => $purchaseOrderItemId,
                    ], [
                        'quantity_received' => $scenario['receipt_quantity'],
                        'price' => $scenario['unit_price'],
                        'total_amount' => round((float) $scenario['receipt_quantity'] * (float) $scenario['unit_price'], 2),
                        'metadata' => $this->json(['demo' => true, 'scenario' => 'brick_house']),
                    ]);

                    $this->totals['purchase_receipts']++;
                }
            }

            $this->linkProcurementToProjectDelivery(
                organizationId: $organizationId,
                projectId: $projectId,
                siteRequestId: $siteRequestId,
                purchaseRequestId: $purchaseRequestId,
                purchaseOrderId: $purchaseOrderId
            );

            $links[(int) $siteRequestId] = [
                'purchase_request_id' => $purchaseRequestId,
                'purchase_order_id' => $purchaseOrderId,
            ];
        }

        return [
            'site_request_links' => $links,
            'purchase_requests' => $purchaseRequestIds,
            'purchase_orders' => $purchaseOrderIds,
        ];
    }

    private function supplierSnapshot(array $scenario, int $supplierId): array
    {
        return [
            'type' => 'registered',
            'status' => 'linked',
            'display_name' => $scenario['supplier_name'],
            'contact_name' => $scenario['supplier_contact'],
            'email' => $scenario['supplier_email'],
            'phone' => $scenario['supplier_phone'],
            'tax_id' => $scenario['supplier_tax_number'],
            'registered_supplier_id' => $supplierId,
            'external_supplier_contact_id' => null,
        ];
    }

    private function requestLineSnapshot(int $lineId, int $purchaseRequestLineId, int $materialId, array $scenario): array
    {
        return [
            'id' => $lineId ?: null,
            'purchase_request_line_id' => $purchaseRequestLineId ?: null,
            'material_id' => $materialId,
            'name' => $scenario['material_name'],
            'quantity' => (float) $scenario['quantity'],
            'unit' => $scenario['unit'],
            'specification' => $scenario['specification'],
            'metadata' => ['demo' => true, 'scenario' => 'brick_house'],
        ];
    }

    private function proposalCommercialSnapshot(
        array $scenario,
        int $proposalId,
        int $supplierRequestLineId,
        int $proposalLineId,
        int $materialId,
        float $subtotalAmount,
        float $deliveryAmount,
        float $vatAmount,
        float $totalAmount,
        string $deliveryDueDate
    ): array {
        return [
            'proposal_id' => $proposalId ?: null,
            'proposal_number' => $scenario['proposal_number'],
            'proposal_date' => $this->now->copy()->addDays($scenario['proposal_offset'])->toDateString(),
            'subtotal_amount' => $subtotalAmount,
            'delivery_amount' => $deliveryAmount,
            'vat_amount' => $vatAmount,
            'total_amount' => $totalAmount,
            'currency' => 'RUB',
            'vat_mode' => 'included',
            'vat_rate' => 20,
            'valid_until' => $this->now->copy()->addDays($scenario['proposal_offset'] + 14)->toDateString(),
            'delivery_due_date' => $deliveryDueDate,
            'lead_time_days' => max(1, $scenario['needed_offset'] - $scenario['proposal_offset']),
            'payment_terms' => $scenario['payment_terms'],
            'delivery_terms' => $scenario['delivery_terms'],
            'warranty_terms' => $scenario['warranty_terms'],
            'lines' => [
                [
                    'id' => $proposalLineId ?: null,
                    'supplier_request_line_id' => $supplierRequestLineId ?: null,
                    'material_id' => $materialId,
                    'name' => $scenario['material_name'],
                    'quantity' => (float) $scenario['quantity'],
                    'unit' => $scenario['unit'],
                    'unit_price' => (float) $scenario['unit_price'],
                    'total_amount' => $subtotalAmount,
                    'comment' => $scenario['proposal_line_comment'],
                ],
            ],
        ];
    }

    private function linkProcurementToProjectDelivery(
        int $organizationId,
        int $projectId,
        int $siteRequestId,
        int $purchaseRequestId,
        int $purchaseOrderId
    ): void {
        if (
            !Schema::hasTable('project_material_deliveries')
            || $siteRequestId <= 0
            || $purchaseRequestId <= 0
            || $purchaseOrderId <= 0
        ) {
            return;
        }

        DB::table('project_material_deliveries')
            ->where('organization_id', $organizationId)
            ->where('project_id', $projectId)
            ->where('site_request_id', $siteRequestId)
            ->update($this->withTimestamps('project_material_deliveries', [
                'purchase_request_id' => $purchaseRequestId,
                'purchase_order_id' => $purchaseOrderId,
            ], true));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function procurementScenarios(string $scope): array
    {
        if ($scope === 'GP') {
            return [
                [
                    'key' => 'gp_brick_delivery',
                    'site_request' => 'brick_delivery',
                    'material' => 'brick',
                    'material_name' => 'Кирпич керамический полнотелый М150',
                    'quantity' => 6200,
                    'unit' => 'шт',
                    'unit_price' => 34.10,
                    'delivery_amount' => 28000.00,
                    'needed_offset' => -3,
                    'order_offset' => -15,
                    'sent_offset' => -14,
                    'proposal_offset' => -13,
                    'confirmed_offset' => -12,
                    'order_status' => 'delivered',
                    'receipt_quantity' => 6200,
                    'receipt_offset' => -2,
                    'receipt_number' => 'ПРМ-ГП-ЛД-001',
                    'purchase_request_number' => 'ЗЗ-ГП-ЛД-001',
                    'supplier_request_number' => 'ЗП-ГП-ЛД-001',
                    'proposal_number' => 'КП-ГП-КЕРАМИКА-001',
                    'order_number' => 'ЗК-ГП-ЛД-001',
                    'supplier_code' => 'BH-GP-SUP-BRICK',
                    'supplier_name' => 'ООО "Истра Керамика"',
                    'supplier_contact' => 'Марина Белова',
                    'supplier_phone' => '+7 495 730-18-41',
                    'supplier_email' => 'sales@istra-ceramic.prohelper.test',
                    'supplier_address' => 'Московская область, Истра, Промышленная ул., 8',
                    'supplier_tax_number' => '5017001401',
                    'supplier_specialization' => 'Керамический кирпич',
                    'specification' => 'Марка М150, полнотелый, паллеты с паспортом качества, партия без высолов.',
                    'purchase_request_notes' => 'Закрытие сменного объема кладки второго этажа без простоя подрядчика.',
                    'supplier_request_comment' => 'Запросить наличие партии и окно доставки до 12:00.',
                    'proposal_notes' => 'Цена зафиксирована на партию со склада поставщика.',
                    'proposal_line_comment' => 'Доставка отдельной строкой, разгрузка силами объекта.',
                    'order_notes' => 'Кирпич принят кладовщиком, документы приложены к приемке.',
                    'payment_terms' => '100% оплата после приемки партии на объекте.',
                    'delivery_terms' => 'Доставка автотранспортом поставщика до зоны разгрузки 1.',
                    'warranty_terms' => 'Замена боя сверх 2% по акту входного контроля.',
                    'decision_reason' => 'Выбран поставщик с подтвержденным наличием партии и ближайшим окном доставки.',
                ],
                [
                    'key' => 'gp_mesh_restock',
                    'site_request' => 'mesh_restock',
                    'material' => 'mesh',
                    'material_name' => 'Сетка кладочная 50x50x4',
                    'quantity' => 180,
                    'unit' => 'м²',
                    'unit_price' => 182.00,
                    'delivery_amount' => 6500.00,
                    'needed_offset' => -9,
                    'order_offset' => -13,
                    'sent_offset' => -12,
                    'proposal_offset' => -12,
                    'confirmed_offset' => -11,
                    'order_status' => 'delivered',
                    'receipt_quantity' => 180,
                    'receipt_offset' => -8,
                    'receipt_number' => 'ПРМ-ГП-ЛД-002',
                    'purchase_request_number' => 'ЗЗ-ГП-ЛД-002',
                    'supplier_request_number' => 'ЗП-ГП-ЛД-002',
                    'proposal_number' => 'КП-ГП-МЕТИЗ-002',
                    'order_number' => 'ЗК-ГП-ЛД-002',
                    'supplier_code' => 'BH-GP-SUP-MESH',
                    'supplier_name' => 'ООО "Метиз Комплект"',
                    'supplier_contact' => 'Андрей Орлов',
                    'supplier_phone' => '+7 495 730-18-42',
                    'supplier_email' => 'orders@metiz-komplekt.prohelper.test',
                    'supplier_address' => 'Москва, ул. Промышленная, 17',
                    'supplier_tax_number' => '7715022402',
                    'supplier_specialization' => 'Армирующая сетка и метизы',
                    'specification' => 'Карта 2x0,5 м, оцинкованная, сертификат соответствия.',
                    'purchase_request_notes' => 'Пополнение резерва сетки под армирование рядов кладки.',
                    'supplier_request_comment' => 'Подтвердить наличие на складе и отгрузку в день заказа.',
                    'proposal_notes' => 'Поставщик дал цену ниже базовой на остаток партии.',
                    'proposal_line_comment' => 'Упаковка кратно 10 картам.',
                    'order_notes' => 'Сетка принята и зарезервирована под кладку второго этажа.',
                    'payment_terms' => 'Оплата по счету в течение 3 банковских дней.',
                    'delivery_terms' => 'Самовывоз транспортом генподряда.',
                    'warranty_terms' => 'Качество по паспорту партии.',
                    'decision_reason' => 'Самая быстрая отгрузка из доступных поставщиков.',
                ],
                [
                    'key' => 'gp_rebar_topup',
                    'site_request' => 'rebar_topup',
                    'material' => 'rebar',
                    'material_name' => 'Арматура А500С 12 мм',
                    'quantity' => 2.4,
                    'unit' => 'т',
                    'unit_price' => 73500.00,
                    'delivery_amount' => 18500.00,
                    'needed_offset' => 2,
                    'order_offset' => -1,
                    'sent_offset' => -1,
                    'proposal_offset' => 0,
                    'confirmed_offset' => 0,
                    'order_status' => 'confirmed',
                    'receipt_quantity' => 0,
                    'receipt_offset' => null,
                    'receipt_number' => 'ПРМ-ГП-ЛД-003',
                    'purchase_request_number' => 'ЗЗ-ГП-ЛД-003',
                    'supplier_request_number' => 'ЗП-ГП-ЛД-003',
                    'proposal_number' => 'КП-ГП-СТАЛЬ-003',
                    'order_number' => 'ЗК-ГП-ЛД-003',
                    'supplier_code' => 'BH-GP-SUP-REBAR',
                    'supplier_name' => 'АО "СтальСнаб-М"',
                    'supplier_contact' => 'Роман Соколов',
                    'supplier_phone' => '+7 495 730-18-43',
                    'supplier_email' => 'tender@stalsnab.prohelper.test',
                    'supplier_address' => 'Москва, 2-й Кабельный проезд, 4',
                    'supplier_tax_number' => '7722043303',
                    'supplier_specialization' => 'Металлопрокат и арматура',
                    'specification' => 'А500С 12 мм, мерная длина 11,7 м, сертификат плавки обязателен.',
                    'purchase_request_notes' => 'Пополнение под армопояс второго этажа.',
                    'supplier_request_comment' => 'Подтвердить отгрузку с резкой без задержки графика.',
                    'proposal_notes' => 'Поставка подтверждена на ближайшее утро.',
                    'proposal_line_comment' => 'Цена включает погрузку на складе поставщика.',
                    'order_notes' => 'Заказ подтвержден, ожидает отгрузку.',
                    'payment_terms' => '50% предоплата, 50% после приемки.',
                    'delivery_terms' => 'Доставка манипулятором поставщика.',
                    'warranty_terms' => 'Сертификат качества на каждую партию.',
                    'decision_reason' => 'Поставщик подтвердил нужный диаметр и дату доставки.',
                ],
                [
                    'key' => 'gp_slab_delivery',
                    'site_request' => 'slab_delivery',
                    'material' => 'slab',
                    'material_name' => 'Плиты перекрытия ПБ',
                    'quantity' => 32,
                    'unit' => 'шт',
                    'unit_price' => 18500.00,
                    'delivery_amount' => 42000.00,
                    'needed_offset' => 12,
                    'order_offset' => 1,
                    'sent_offset' => 1,
                    'proposal_offset' => 2,
                    'confirmed_offset' => 3,
                    'order_status' => 'confirmed',
                    'receipt_quantity' => 0,
                    'receipt_offset' => null,
                    'receipt_number' => 'ПРМ-ГП-ЛД-004',
                    'purchase_request_number' => 'ЗЗ-ГП-ЛД-004',
                    'supplier_request_number' => 'ЗП-ГП-ЛД-004',
                    'proposal_number' => 'КП-ГП-ЖБИ-004',
                    'order_number' => 'ЗК-ГП-ЛД-004',
                    'supplier_code' => 'BH-GP-SUP-SLAB',
                    'supplier_name' => 'ООО "Подмосковье ЖБИ"',
                    'supplier_contact' => 'Игорь Зайцев',
                    'supplier_phone' => '+7 495 730-18-44',
                    'supplier_email' => 'pb@mosjbi.prohelper.test',
                    'supplier_address' => 'Московская область, Дедовск, Заводская ул., 5',
                    'supplier_tax_number' => '5017004404',
                    'supplier_specialization' => 'Железобетонные изделия',
                    'specification' => 'Плиты ПБ по спецификации проекта, паспорта и схема раскладки обязательны.',
                    'purchase_request_notes' => 'Поставка синхронизирована с окном автокрана.',
                    'supplier_request_comment' => 'Запросить подтверждение номенклатуры и время подачи машин.',
                    'proposal_notes' => 'Поставщик подтвердил две машины с интервалом 40 минут.',
                    'proposal_line_comment' => 'Цена без учета разгрузки краном генподряда.',
                    'order_notes' => 'Ожидает готовность армопояса и допуск ПТО.',
                    'payment_terms' => '30% предоплата, остаток после приемки плит.',
                    'delivery_terms' => 'Доставка по графику монтажа плит.',
                    'warranty_terms' => 'Паспорта ЖБИ и гарантия завода 12 месяцев.',
                    'decision_reason' => 'Выбран поставщик с подтвержденной номенклатурой ПБ и датой подачи.',
                ],
                [
                    'key' => 'gp_roofing_preorder',
                    'site_request' => 'roofing_preorder',
                    'material' => 'roofing',
                    'material_name' => 'Металлочерепица 0,5 мм',
                    'quantity' => 318,
                    'unit' => 'м²',
                    'unit_price' => 910.00,
                    'delivery_amount' => 22000.00,
                    'needed_offset' => 36,
                    'order_offset' => 6,
                    'sent_offset' => 6,
                    'proposal_offset' => 7,
                    'confirmed_offset' => 8,
                    'order_status' => 'sent',
                    'receipt_quantity' => 0,
                    'receipt_offset' => null,
                    'receipt_number' => 'ПРМ-ГП-ЛД-005',
                    'purchase_request_number' => 'ЗЗ-ГП-ЛД-005',
                    'supplier_request_number' => 'ЗП-ГП-ЛД-005',
                    'proposal_number' => 'КП-ГП-КРОВЛЯ-005',
                    'order_number' => 'ЗК-ГП-ЛД-005',
                    'supplier_code' => 'BH-GP-SUP-ROOF',
                    'supplier_name' => 'ООО "Кровля Профи"',
                    'supplier_contact' => 'Светлана Миронова',
                    'supplier_phone' => '+7 495 730-18-45',
                    'supplier_email' => 'roof@roof-profi.prohelper.test',
                    'supplier_address' => 'Москва, Дмитровское шоссе, 163',
                    'supplier_tax_number' => '7713055505',
                    'supplier_specialization' => 'Кровельные материалы',
                    'specification' => 'Металлочерепица 0,5 мм, цвет RAL 7024, комплект планок и крепежа.',
                    'purchase_request_notes' => 'Ранняя бронь выбранного заказчиком цвета.',
                    'supplier_request_comment' => 'Запросить срок производства и резерв цвета.',
                    'proposal_notes' => 'Цена действует 14 дней, производство после подтверждения заказа.',
                    'proposal_line_comment' => 'Комплектующие включены в доставку.',
                    'order_notes' => 'Заказ отправлен поставщику, ожидается подтверждение производства.',
                    'payment_terms' => '70% предоплата до запуска производства.',
                    'delivery_terms' => 'Доставка после готовности кровельного фронта.',
                    'warranty_terms' => 'Гарантия покрытия 15 лет.',
                    'decision_reason' => 'Поставщик держит нужный цвет и комплектующие в одном заказе.',
                ],
                [
                    'key' => 'gp_facing_brick_preorder',
                    'site_request' => 'facing_brick_preorder',
                    'material' => 'facing_brick',
                    'material_name' => 'Кирпич облицовочный лицевой М175',
                    'quantity' => 28400,
                    'unit' => 'шт',
                    'unit_price' => 51.20,
                    'delivery_amount' => 68000.00,
                    'needed_offset' => 58,
                    'order_offset' => 9,
                    'sent_offset' => 9,
                    'proposal_offset' => 10,
                    'confirmed_offset' => 11,
                    'order_status' => 'sent',
                    'receipt_quantity' => 0,
                    'receipt_offset' => null,
                    'receipt_number' => 'ПРМ-ГП-ЛД-006',
                    'purchase_request_number' => 'ЗЗ-ГП-ЛД-006',
                    'supplier_request_number' => 'ЗП-ГП-ЛД-006',
                    'proposal_number' => 'КП-ГП-ФАСАД-006',
                    'order_number' => 'ЗК-ГП-ЛД-006',
                    'supplier_code' => 'BH-GP-SUP-FACE',
                    'supplier_name' => 'ООО "Фасадный кирпич"',
                    'supplier_contact' => 'Павел Карпов',
                    'supplier_phone' => '+7 495 730-18-46',
                    'supplier_email' => 'face@facade-brick.prohelper.test',
                    'supplier_address' => 'Тула, Новомосковское шоссе, 12',
                    'supplier_tax_number' => '7107006606',
                    'supplier_specialization' => 'Облицовочный кирпич',
                    'specification' => 'М175, оттенок графит, единая партия, бой не более 1,5%.',
                    'purchase_request_notes' => 'Резерв фасадной партии до закрытия теплового контура.',
                    'supplier_request_comment' => 'Запросить образец, паспорт партии и условия хранения резерва.',
                    'proposal_notes' => 'Поставщик готов держать резерв партии до даты фасадных работ.',
                    'proposal_line_comment' => 'Поставка четырьмя машинами по отдельному графику.',
                    'order_notes' => 'Заказ отправлен, ожидается подтверждение резерва партии.',
                    'payment_terms' => '20% бронь партии, остаток перед отгрузкой.',
                    'delivery_terms' => 'Поставка партиями по заявкам генподряда.',
                    'warranty_terms' => 'Подбор оттенка и замена боя сверх нормы.',
                    'decision_reason' => 'Выбран единый оттенок партии и приемлемые условия резерва.',
                ],
            ];
        }

        return [
            [
                'key' => 'sub_brick_delivery',
                'site_request' => 'brick_delivery',
                'material' => 'brick',
                'material_name' => 'Кирпич керамический полнотелый М150',
                'quantity' => 4200,
                'unit' => 'шт',
                'unit_price' => 34.80,
                'delivery_amount' => 16000.00,
                'needed_offset' => -3,
                'order_offset' => -8,
                'sent_offset' => -8,
                'proposal_offset' => -7,
                'confirmed_offset' => -7,
                'order_status' => 'delivered',
                'receipt_quantity' => 4200,
                'receipt_offset' => -2,
                'receipt_number' => 'ПРМ-ПДР-ЛД-001',
                'purchase_request_number' => 'ЗЗ-ПДР-ЛД-001',
                'supplier_request_number' => 'ЗП-ПДР-ЛД-001',
                'proposal_number' => 'КП-ПДР-КИРПИЧ-001',
                'order_number' => 'ЗК-ПДР-ЛД-001',
                'supplier_code' => 'BH-SUB-SUP-BRICK',
                'supplier_name' => 'ООО "КладМатериал"',
                'supplier_contact' => 'Денис Климов',
                'supplier_phone' => '+7 495 740-21-11',
                'supplier_email' => 'order@klad-material.prohelper.test',
                'supplier_address' => 'Москва, Рязанский проспект, 31',
                'supplier_tax_number' => '7721011101',
                'supplier_specialization' => 'Кладочные материалы',
                'specification' => 'Кирпич М150, подача на захватку подрядчика, паллеты с маркировкой.',
                'purchase_request_notes' => 'Закрыть вторую захватку кладки без ожидания склада генподряда.',
                'supplier_request_comment' => 'Подтвердить быструю доставку к бытовому городку подрядчика.',
                'proposal_notes' => 'КП принято мастером подрядчика.',
                'proposal_line_comment' => 'Доставка малотоннажным транспортом.',
                'order_notes' => 'Кирпич принят на склад подрядчика.',
                'payment_terms' => 'Оплата после приемки.',
                'delivery_terms' => 'Доставка до склада подрядчика на объекте.',
                'warranty_terms' => 'Замена боя по входному контролю.',
                'decision_reason' => 'Лучший срок поставки для сменного плана подрядчика.',
            ],
            [
                'key' => 'sub_mesh_restock',
                'site_request' => 'mesh_restock',
                'material' => 'mesh',
                'material_name' => 'Сетка кладочная 50x50x4',
                'quantity' => 90,
                'unit' => 'м²',
                'unit_price' => 185.00,
                'delivery_amount' => 0.00,
                'needed_offset' => -8,
                'order_offset' => -10,
                'sent_offset' => -10,
                'proposal_offset' => -9,
                'confirmed_offset' => -9,
                'order_status' => 'delivered',
                'receipt_quantity' => 90,
                'receipt_offset' => -8,
                'receipt_number' => 'ПРМ-ПДР-ЛД-002',
                'purchase_request_number' => 'ЗЗ-ПДР-ЛД-002',
                'supplier_request_number' => 'ЗП-ПДР-ЛД-002',
                'proposal_number' => 'КП-ПДР-СЕТКА-002',
                'order_number' => 'ЗК-ПДР-ЛД-002',
                'supplier_code' => 'BH-SUB-SUP-MESH',
                'supplier_name' => 'ИП Гордеев А.С.',
                'supplier_contact' => 'Александр Гордеев',
                'supplier_phone' => '+7 495 740-21-12',
                'supplier_email' => 'gordeev@mesh-supply.prohelper.test',
                'supplier_address' => 'Московская область, Красногорск, Заводская ул., 3',
                'supplier_tax_number' => '5024012202',
                'supplier_specialization' => 'Сетка и крепеж',
                'specification' => 'Карта 2x0,5 м, доставка включена в цену.',
                'purchase_request_notes' => 'Закрыть армирование рядов на первой захватке.',
                'supplier_request_comment' => 'Подтвердить наличие 90 м² и отгрузку сегодня.',
                'proposal_notes' => 'Поставщик подтвердил доставку в день запроса.',
                'proposal_line_comment' => 'Цена без отдельной доставки.',
                'order_notes' => 'Сетка получена и списана в журнал работ.',
                'payment_terms' => 'Оплата по факту приемки.',
                'delivery_terms' => 'Доставка до КПП объекта.',
                'warranty_terms' => 'Качество по паспорту партии.',
                'decision_reason' => 'Материал был в наличии и без отдельной логистики.',
            ],
            [
                'key' => 'sub_mortar_today',
                'site_request' => 'mortar_today',
                'material' => 'mortar',
                'material_name' => 'Раствор кладочный М100',
                'quantity' => 6.5,
                'unit' => 'м³',
                'unit_price' => 5100.00,
                'delivery_amount' => 4500.00,
                'needed_offset' => 1,
                'order_offset' => 0,
                'sent_offset' => 0,
                'proposal_offset' => 0,
                'confirmed_offset' => 0,
                'order_status' => 'in_delivery',
                'receipt_quantity' => 0,
                'receipt_offset' => null,
                'receipt_number' => 'ПРМ-ПДР-ЛД-003',
                'purchase_request_number' => 'ЗЗ-ПДР-ЛД-003',
                'supplier_request_number' => 'ЗП-ПДР-ЛД-003',
                'proposal_number' => 'КП-ПДР-РАСТВОР-003',
                'order_number' => 'ЗК-ПДР-ЛД-003',
                'supplier_code' => 'BH-SUB-SUP-MORTAR',
                'supplier_name' => 'ООО "Раствор-Сервис"',
                'supplier_contact' => 'Олег Антонов',
                'supplier_phone' => '+7 495 740-21-13',
                'supplier_email' => 'dispatch@rastvor-service.prohelper.test',
                'supplier_address' => 'Москва, ул. Бетонная, 6',
                'supplier_tax_number' => '7723013303',
                'supplier_specialization' => 'Готовые растворы',
                'specification' => 'Раствор М100, подача миксером к 09:00, осадка конуса по паспорту.',
                'purchase_request_notes' => 'Срочная поставка на дневную смену кладки.',
                'supplier_request_comment' => 'Запросить окно подачи и контакт водителя.',
                'proposal_notes' => 'Поставщик подтвердил машину в пути.',
                'proposal_line_comment' => 'Доставка включена отдельной строкой.',
                'order_notes' => 'Машина в пути, приемка ожидается утром.',
                'payment_terms' => 'Оплата по счету после подтверждения объема.',
                'delivery_terms' => 'Подача миксером на площадку подрядчика.',
                'warranty_terms' => 'Паспорт смеси на рейс.',
                'decision_reason' => 'Единственный поставщик с подтвержденным утренним окном.',
            ],
            [
                'key' => 'sub_rebar_topup',
                'site_request' => 'rebar_topup',
                'material' => 'rebar',
                'material_name' => 'Арматура А500С 12 мм',
                'quantity' => 0.9,
                'unit' => 'т',
                'unit_price' => 74200.00,
                'delivery_amount' => 9000.00,
                'needed_offset' => 2,
                'order_offset' => 0,
                'sent_offset' => 0,
                'proposal_offset' => 1,
                'confirmed_offset' => 1,
                'order_status' => 'confirmed',
                'receipt_quantity' => 0,
                'receipt_offset' => null,
                'receipt_number' => 'ПРМ-ПДР-ЛД-004',
                'purchase_request_number' => 'ЗЗ-ПДР-ЛД-004',
                'supplier_request_number' => 'ЗП-ПДР-ЛД-004',
                'proposal_number' => 'КП-ПДР-АРМ-004',
                'order_number' => 'ЗК-ПДР-ЛД-004',
                'supplier_code' => 'BH-SUB-SUP-REBAR',
                'supplier_name' => 'ООО "Металл Участок"',
                'supplier_contact' => 'Евгений Фролов',
                'supplier_phone' => '+7 495 740-21-14',
                'supplier_email' => 'metal@uchastok.prohelper.test',
                'supplier_address' => 'Москва, ул. Складская, 9',
                'supplier_tax_number' => '7724014404',
                'supplier_specialization' => 'Мелкие партии металлопроката',
                'specification' => 'А500С 12 мм, партия до 1 т, резка под перемычки.',
                'purchase_request_notes' => 'Пополнение под каркасы перемычек второго этажа.',
                'supplier_request_comment' => 'Подтвердить резку и доставку на следующий день.',
                'proposal_notes' => 'Поставщик подтвердил резку по ведомости.',
                'proposal_line_comment' => 'Цена включает резку.',
                'order_notes' => 'Заказ подтвержден, ожидается отгрузка.',
                'payment_terms' => '50% предоплата, остаток по приемке.',
                'delivery_terms' => 'Доставка манипулятором до склада подрядчика.',
                'warranty_terms' => 'Сертификат на партию.',
                'decision_reason' => 'Поставщик принимает малый объем без удорожания резки.',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $general
     * @param array<string, mixed> $contractor
     * @param array<string, int> $contracts
     * @param array<string, int> $contractors
     * @param array<string, int> $estimates
     * @param array<string, int> $acts
     * @param array<string, array<string, int>> $siteRequests
     * @param array{site_request_links?: array<int, array{purchase_request_id: int, purchase_order_id: int}>} $procurement
     * @param array{payment_links?: array<string, array{budget_article_id: int, responsibility_center_id: int}>} $budgeting
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
        array $siteRequests,
        array $procurement = [],
        array $budgeting = []
    ): array {
        if (!Schema::hasTable('payment_documents')) {
            return [];
        }

        $generalAccountantId = $this->actorId($general, 'accountant');
        $generalApproverId = $this->actorId($general, 'project_manager');
        $contractorAccountantId = $this->actorId($contractor, 'accountant');
        $contractorApproverId = $this->actorId($contractor, 'work_manager');
        $generalActAmount = $this->performanceActAmount($acts['general'] ?? 0, 8620000.00);
        $contractorActAmount = $this->performanceActAmount($acts['contractor'] ?? 0, 8620000.00);

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
                'user_id' => $generalAccountantId,
                'approver_id' => $generalApproverId,
                'recipient_user_id' => $contractorAccountantId,
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
                'amount' => $generalActAmount,
                'paid_amount' => 0,
                'status' => 'approved',
                'source_type' => ContractPerformanceAct::class,
                'source_id' => $acts['general'],
                'description' => 'Оплата акта по кладке первого этажа',
                'purpose' => 'Оплата по акту КС-2-КД-ГП-01',
                'due_offset' => 5,
                'paid_offset' => null,
                'user_id' => $generalAccountantId,
                'approver_id' => $generalApproverId,
                'recipient_user_id' => $contractorAccountantId,
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
                'user_id' => $generalAccountantId,
                'approver_id' => $generalApproverId,
                'recipient_user_id' => null,
                'site_request_id' => $siteRequests['general']['brick_delivery'] ?? null,
            ],
            'gp_rebar_topup' => [
                'organization_id' => $general['organization_id'],
                'project_id' => $projectId,
                'estimate_id' => $estimates['general'],
                'document_type' => 'expense',
                'document_number' => 'РКО-ГП-ЛД-011',
                'document_date' => $this->now->copy()->subDay()->toDateString(),
                'direction' => 'outgoing',
                'invoice_type' => 'material_purchase',
                'payer_organization_id' => $general['organization_id'],
                'payee_organization_id' => null,
                'payee_contractor_id' => null,
                'amount' => 178080.00,
                'paid_amount' => 0,
                'status' => 'approved',
                'source_type' => 'project',
                'source_id' => $projectId,
                'description' => 'Резерв и закупка арматуры для армопояса второго этажа',
                'purpose' => 'Арматура А500С по заявке на пополнение резерва',
                'due_offset' => 2,
                'paid_offset' => null,
                'user_id' => $generalAccountantId,
                'approver_id' => $generalApproverId,
                'recipient_user_id' => null,
                'site_request_id' => $siteRequests['general']['rebar_topup'] ?? null,
            ],
            'gp_slab_supplier_invoice' => [
                'organization_id' => $general['organization_id'],
                'project_id' => $projectId,
                'estimate_id' => $estimates['general'],
                'document_type' => 'invoice',
                'document_number' => 'СЧ-ГП-ЛД-019',
                'document_date' => $this->now->copy()->addDays(2)->toDateString(),
                'direction' => 'outgoing',
                'invoice_type' => 'material_purchase',
                'payer_organization_id' => $general['organization_id'],
                'payee_organization_id' => null,
                'payee_contractor_id' => null,
                'amount' => 595200.00,
                'paid_amount' => 0,
                'status' => 'approved',
                'source_type' => 'project',
                'source_id' => $projectId,
                'description' => 'Счет поставщика плит перекрытия ПБ',
                'purpose' => 'Плиты ПБ по заявке на монтаж перекрытия',
                'due_offset' => 9,
                'paid_offset' => null,
                'user_id' => $generalAccountantId,
                'approver_id' => $generalApproverId,
                'recipient_user_id' => null,
                'site_request_id' => $siteRequests['general']['slab_delivery'] ?? null,
            ],
            'gp_roofing_preorder' => [
                'organization_id' => $general['organization_id'],
                'project_id' => $projectId,
                'estimate_id' => $estimates['general'],
                'document_type' => 'invoice',
                'document_number' => 'СЧ-ГП-ЛД-022',
                'document_date' => $this->now->copy()->addDays(6)->toDateString(),
                'direction' => 'outgoing',
                'invoice_type' => 'material_purchase',
                'payer_organization_id' => $general['organization_id'],
                'payee_organization_id' => null,
                'payee_contractor_id' => null,
                'amount' => 292560.00,
                'paid_amount' => 0,
                'status' => 'approved',
                'source_type' => 'project',
                'source_id' => $projectId,
                'description' => 'Бронь партии металлочерепицы выбранного цвета',
                'purpose' => 'Предоплата поставки кровельного материала',
                'due_offset' => 14,
                'paid_offset' => null,
                'user_id' => $generalAccountantId,
                'approver_id' => $generalApproverId,
                'recipient_user_id' => null,
                'site_request_id' => $siteRequests['general']['roofing_preorder'] ?? null,
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
                'user_id' => $contractorAccountantId,
                'approver_id' => $contractorApproverId,
                'recipient_user_id' => $contractorAccountantId,
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
                'amount' => $contractorActAmount,
                'paid_amount' => 0,
                'status' => 'approved',
                'source_type' => ContractPerformanceAct::class,
                'source_id' => $acts['contractor'],
                'description' => 'Счет подрядчика по акту за кладку первого этажа',
                'purpose' => 'Оплата по акту КС-2-КД-ПДР-01',
                'due_offset' => 5,
                'paid_offset' => null,
                'user_id' => $contractorAccountantId,
                'approver_id' => $contractorApproverId,
                'recipient_user_id' => $contractorAccountantId,
                'site_request_id' => null,
            ],
            'sub_mesh_restock' => [
                'organization_id' => $contractor['organization_id'],
                'project_id' => $projectId,
                'estimate_id' => $estimates['contractor'],
                'document_type' => 'expense',
                'document_number' => 'РКО-ПДР-ЛД-004',
                'document_date' => $this->now->copy()->subDays(8)->toDateString(),
                'direction' => 'outgoing',
                'invoice_type' => 'material_purchase',
                'payer_organization_id' => $contractor['organization_id'],
                'payee_organization_id' => null,
                'payee_contractor_id' => null,
                'amount' => 16650.00,
                'paid_amount' => 16650.00,
                'status' => 'paid',
                'source_type' => 'project',
                'source_id' => $projectId,
                'description' => 'Сетка кладочная для первой захватки подрядчика',
                'purpose' => 'Закупка сетки по заявке мастера участка',
                'due_offset' => -8,
                'paid_offset' => -7,
                'user_id' => $contractorAccountantId,
                'approver_id' => $contractorApproverId,
                'recipient_user_id' => null,
                'site_request_id' => $siteRequests['contractor']['mesh_restock'] ?? null,
            ],
            'sub_mortar_today' => [
                'organization_id' => $contractor['organization_id'],
                'project_id' => $projectId,
                'estimate_id' => $estimates['contractor'],
                'document_type' => 'invoice',
                'document_number' => 'СЧ-ПДР-ЛД-008',
                'document_date' => $this->now->copy()->toDateString(),
                'direction' => 'outgoing',
                'invoice_type' => 'material_purchase',
                'payer_organization_id' => $contractor['organization_id'],
                'payee_organization_id' => null,
                'payee_contractor_id' => null,
                'amount' => 33150.00,
                'paid_amount' => 0,
                'status' => 'approved',
                'source_type' => 'project',
                'source_id' => $projectId,
                'description' => 'Раствор М100 на текущую смену кладки',
                'purpose' => 'Поставка раствора по срочной заявке прораба',
                'due_offset' => 1,
                'paid_offset' => null,
                'user_id' => $contractorAccountantId,
                'approver_id' => $contractorApproverId,
                'recipient_user_id' => null,
                'site_request_id' => $siteRequests['contractor']['mortar_today'] ?? null,
            ],
            'sub_rebar_topup' => [
                'organization_id' => $contractor['organization_id'],
                'project_id' => $projectId,
                'estimate_id' => $estimates['contractor'],
                'document_type' => 'expense',
                'document_number' => 'РКО-ПДР-ЛД-009',
                'document_date' => $this->now->copy()->subDay()->toDateString(),
                'direction' => 'outgoing',
                'invoice_type' => 'material_purchase',
                'payer_organization_id' => $contractor['organization_id'],
                'payee_organization_id' => null,
                'payee_contractor_id' => null,
                'amount' => 66780.00,
                'paid_amount' => 0,
                'status' => 'approved',
                'source_type' => 'project',
                'source_id' => $projectId,
                'description' => 'Арматура на перемычки второго этажа',
                'purpose' => 'Пополнение резерва арматуры по заявке мастера',
                'due_offset' => 2,
                'paid_offset' => null,
                'user_id' => $contractorAccountantId,
                'approver_id' => $contractorApproverId,
                'recipient_user_id' => null,
                'site_request_id' => $siteRequests['contractor']['rebar_topup'] ?? null,
            ],
        ];

        $documents = $this->applyBudgetingToPayments($documents, $budgeting);
        $ids = [];

        foreach ($documents as $key => $document) {
            $vatAmount = round($document['amount'] * 20 / 120, 2);
            $paidAmount = (float) $document['paid_amount'];
            $remainingAmount = round((float) $document['amount'] - $paidAmount, 2);
            $paidAt = $document['paid_offset'] === null ? null : $this->now->copy()->addDays($document['paid_offset']);
            $metadata = [
                'demo' => true,
                'scenario' => 'brick_house',
            ];

            if ($document['site_request_id']) {
                $procurementLink = $procurement['site_request_links'][(int) $document['site_request_id']] ?? null;

                if (is_array($procurementLink)) {
                    $metadata['purchase_request_id'] = $procurementLink['purchase_request_id'] ?? null;
                    $metadata['purchase_order_id'] = $procurementLink['purchase_order_id'] ?? null;
                }
            }

            $ids[$key] = $this->upsert('payment_documents', [
                'document_number' => $document['document_number'],
            ], [
                'organization_id' => $document['organization_id'],
                'project_id' => $document['project_id'],
                'estimate_id' => $document['estimate_id'],
                'budget_article_id' => $document['budget_article_id'] ?? null,
                'responsibility_center_id' => $document['responsibility_center_id'] ?? null,
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
                'budget_limit_status' => ($document['budget_article_id'] ?? null) !== null && $document['direction'] === 'outgoing' ? 'available' : null,
                'budget_limit_decision' => ($document['budget_article_id'] ?? null) !== null && $document['direction'] === 'outgoing' ? 'allow' : null,
                'budget_limit_message' => ($document['budget_article_id'] ?? null) !== null && $document['direction'] === 'outgoing'
                    ? 'Платеж находится в пределах бюджета демо-проекта'
                    : null,
                'budget_limit_checked_at' => ($document['budget_article_id'] ?? null) !== null && $document['direction'] === 'outgoing'
                    ? $this->now->copy()->subHours(2)
                    : null,
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
                'metadata' => $this->json($metadata),
                'notes' => 'Демо-платеж связан с договором, сметой или заявкой',
                'created_by_user_id' => $document['user_id'],
                'approved_by_user_id' => $document['status'] === 'approved' || $document['status'] === 'paid' ? $document['approver_id'] : null,
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
                'recipient_confirmed_by_user_id' => $document['status'] === 'paid' ? $document['recipient_user_id'] : null,
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

    private function performanceActAmount(int $actId, float $fallback): float
    {
        if ($actId <= 0 || !Schema::hasTable('contract_performance_acts')) {
            return $fallback;
        }

        $amount = DB::table('contract_performance_acts')
            ->where('id', $actId)
            ->value('amount');

        return $amount === null ? $fallback : (float) $amount;
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
                'actor_key' => 'project_manager',
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
                'actor_key' => 'project_manager',
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
                'actor_key' => 'pto_engineer',
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
                'actor_key' => 'accountant',
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
                'actor_key' => 'supply_manager',
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
                'actor_key' => 'pto_engineer',
            ],
            [
                'organization' => $general,
                'module' => 'safety-management',
                'event_type' => 'safety.briefing.completed',
                'action' => 'create',
                'subject_type' => 'safety_briefing',
                'subject_id' => null,
                'subject_label' => 'Внеплановый инструктаж',
                'title' => 'Проведен инструктаж после обхода безопасности',
                'description' => 'Прораб провел внеплановый инструктаж, участники подписали журнал.',
                'days' => 5,
                'correlation' => 'brick-house-safety-briefing',
                'actor_key' => 'foreman',
            ],
            [
                'organization' => $contractor,
                'module' => 'quality-control',
                'event_type' => 'quality.defect.ready_for_review',
                'action' => 'update',
                'subject_type' => 'quality_defect',
                'subject_id' => null,
                'subject_label' => 'Армирование перемычки',
                'title' => 'Замечание качества передано на проверку',
                'description' => 'Подрядчик устранил замечание по перемычке и ожидает повторного осмотра ПТО.',
                'days' => 0,
                'correlation' => 'brick-house-quality-defect-review',
                'actor_key' => 'foreman',
            ],
            [
                'organization' => $general,
                'module' => 'workforce-management',
                'event_type' => 'workforce.payroll.prepared',
                'action' => 'create',
                'subject_type' => 'workforce_payroll_statement',
                'subject_id' => null,
                'subject_label' => 'Табель объекта',
                'title' => 'Сформирована ведомость трудозатрат',
                'description' => 'Смены сотрудников и корректировки явки собраны в расчетную ведомость объекта.',
                'days' => 0,
                'correlation' => 'brick-house-workforce-payroll',
                'actor_key' => 'project_manager',
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
                'actor_key' => 'pto_engineer',
            ],
        ];

        foreach ($events as $event) {
            $organization = $event['organization'];
            $actor = $this->actor($organization, $event['actor_key']);

            $this->upsert('activity_events', [
                'organization_id' => $organization['organization_id'],
                'correlation_id' => $event['correlation'],
            ], [
                'actor_user_id' => $actor['user_id'],
                'actor_type' => 'user',
                'actor_name' => $actor['name'],
                'actor_email' => $actor['email'],
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
     * @return array{name: string, quantity: float, unit: string, unit_price: float}
     */
    private function journalMaterialUsage(string $materialKey, float $workQuantity, string $workUnit): array
    {
        return match ($materialKey) {
            'brick' => [
                'name' => 'Кирпич керамический полнотелый М150',
                'quantity' => round($workQuantity * ($workUnit === 'м²' ? 48 : 395), 2),
                'unit' => 'шт',
                'unit_price' => 34.50,
            ],
            'facing_brick' => [
                'name' => 'Кирпич облицовочный лицевой М175',
                'quantity' => round($workQuantity * ($workUnit === 'м²' ? 52 : 410), 2),
                'unit' => 'шт',
                'unit_price' => 52.00,
            ],
            'mortar' => [
                'name' => 'Раствор кладочный М100',
                'quantity' => round($workQuantity * 0.18, 2),
                'unit' => 'м³',
                'unit_price' => 5100.00,
            ],
            'concrete' => [
                'name' => 'Бетон B25 П4 F200 W6',
                'quantity' => $workQuantity,
                'unit' => 'м³',
                'unit_price' => 6800.00,
            ],
            'rebar' => [
                'name' => 'Арматура А500С 12 мм',
                'quantity' => round(max(0.1, $workQuantity * 0.14), 2),
                'unit' => 'т',
                'unit_price' => 74200.00,
            ],
            'mesh' => [
                'name' => 'Сетка кладочная 50x50x4',
                'quantity' => $workQuantity,
                'unit' => 'м²',
                'unit_price' => 185.00,
            ],
            'insulation' => [
                'name' => 'Минеральная вата 100 мм',
                'quantity' => round($workQuantity / 6, 2),
                'unit' => 'упак',
                'unit_price' => 1520.00,
            ],
            'slab' => [
                'name' => 'Плиты перекрытия ПБ',
                'quantity' => $workQuantity,
                'unit' => 'шт',
                'unit_price' => 18600.00,
            ],
            'roofing' => [
                'name' => 'Металлочерепица 0,5 мм',
                'quantity' => $workQuantity,
                'unit' => 'м²',
                'unit_price' => 920.00,
            ],
            default => [
                'name' => 'Цемент М500 Д0',
                'quantity' => round(max(0.05, $workQuantity * 0.02), 2),
                'unit' => 'т',
                'unit_price' => 8200.00,
            ],
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
            ['key' => 'survey_layout', 'wbs' => '1.1.1', 'name' => 'Геодезическая разбивка пятна дома', 'description' => 'Вынос осей, закрепление реперов, исполнительная схема основания.', 'phase' => 'подготовка', 'work' => 'quality', 'start_offset' => -146, 'duration' => 5, 'progress' => 100, 'status' => 'completed', 'priority' => 'high', 'critical' => true, 'cost' => 420000, 'resources' => ['people' => 3], 'material_resources' => [], 'equipment_resources' => []],
            ['key' => 'temporary_utilities', 'wbs' => '1.1.2', 'name' => 'Временное электроснабжение и вода', 'description' => 'Щиты, ввод воды, освещение складской зоны и бытового городка.', 'phase' => 'подготовка', 'work' => 'engineering', 'start_offset' => -144, 'duration' => 12, 'progress' => 100, 'status' => 'completed', 'priority' => 'normal', 'critical' => false, 'cost' => 760000, 'resources' => ['people' => 4], 'material_resources' => [], 'equipment_resources' => []],
            ['key' => 'earthworks', 'wbs' => '1.2', 'name' => 'Котлован и основание', 'description' => 'Разработка котлована, песчаная подготовка, геодезия.', 'phase' => 'подготовка', 'work' => 'earthworks', 'start_offset' => -132, 'duration' => 24, 'progress' => 100, 'status' => 'completed', 'priority' => 'high', 'critical' => true, 'cost' => 1850000, 'resources' => ['people' => 7], 'material_resources' => [], 'equipment_resources' => [['role' => 'Экскаватор', 'name' => 'Экскаватор 20 т', 'units' => 1, 'hours' => 144, 'rate' => 3200]]],
            ['key' => 'base_preparation', 'wbs' => '1.3', 'name' => 'Песчаная подготовка и подбетонка', 'description' => 'Уплотнение основания, приемка плотности, подготовка под плиту.', 'phase' => 'подготовка', 'work' => 'foundation', 'start_offset' => -116, 'duration' => 10, 'progress' => 100, 'status' => 'completed', 'priority' => 'high', 'critical' => true, 'cost' => 1260000, 'resources' => ['people' => 8], 'material_resources' => [['role' => 'Бетон B25', 'material' => 'concrete', 'quantity' => 18, 'unit_price' => 6800]], 'equipment_resources' => [['role' => 'Каток', 'name' => 'Виброкаток 7 т', 'units' => 1, 'hours' => 40, 'rate' => 2800]]],
            ['key' => 'foundation_rebar', 'wbs' => '2.1.1', 'name' => 'Армирование фундаментной плиты', 'description' => 'Нижняя и верхняя сетка, выпуски под стены, контроль защитного слоя.', 'phase' => 'фундамент', 'work' => 'foundation', 'start_offset' => -108, 'duration' => 14, 'progress' => 100, 'status' => 'completed', 'priority' => 'critical', 'critical' => true, 'cost' => 4200000, 'resources' => ['people' => 12], 'material_resources' => [['role' => 'Арматура', 'material' => 'rebar', 'quantity' => 18.4, 'unit_price' => 74200]], 'equipment_resources' => []],
            ['key' => 'foundation_formwork', 'wbs' => '2.1.2', 'name' => 'Опалубка и закладные фундамента', 'description' => 'Контур опалубки, закладные инженерных вводов, приемка ПТО.', 'phase' => 'фундамент', 'work' => 'foundation', 'start_offset' => -98, 'duration' => 8, 'progress' => 100, 'status' => 'completed', 'priority' => 'critical', 'critical' => true, 'cost' => 1850000, 'resources' => ['people' => 10], 'material_resources' => [], 'equipment_resources' => []],
            ['key' => 'foundation', 'wbs' => '2.1', 'name' => 'Монолитный фундамент', 'description' => 'Арматура, опалубка, бетон, уход за бетоном.', 'phase' => 'фундамент', 'work' => 'foundation', 'start_offset' => -108, 'duration' => 38, 'progress' => 100, 'status' => 'completed', 'priority' => 'critical', 'critical' => true, 'cost' => 14600000, 'resources' => ['people' => 18], 'material_resources' => [['role' => 'Бетон B25', 'material' => 'concrete', 'quantity' => 154, 'unit_price' => 6800], ['role' => 'Арматура', 'material' => 'rebar', 'quantity' => 18.4, 'unit_price' => 74200]], 'equipment_resources' => [['role' => 'Бетононасос', 'name' => 'Бетононасос 42 м', 'units' => 1, 'hours' => 64, 'rate' => 4200]]],
            ['key' => 'waterproofing', 'wbs' => '2.2', 'name' => 'Гидроизоляция и обратная засыпка', 'description' => 'Гидроизоляция плиты, дренаж, обратная засыпка.', 'phase' => 'фундамент', 'work' => 'waterproofing', 'start_offset' => -70, 'duration' => 16, 'progress' => 100, 'status' => 'completed', 'priority' => 'high', 'critical' => true, 'cost' => 3100000, 'resources' => ['people' => 8], 'material_resources' => [], 'equipment_resources' => []],
            ['key' => 'foundation_acceptance', 'wbs' => '2.3', 'name' => 'Приемка скрытых работ фундамента', 'description' => 'Акты скрытых работ, фотофиксация, исполнительная схема и подписи сторон.', 'phase' => 'ПТО', 'work' => 'quality', 'start_offset' => -62, 'duration' => 5, 'progress' => 100, 'status' => 'completed', 'priority' => 'high', 'critical' => true, 'cost' => 380000, 'resources' => ['people' => 3], 'material_resources' => [], 'equipment_resources' => []],
            ['key' => 'masonry_outer', 'wbs' => '3.1', 'name' => 'Наружные кирпичные стены', 'description' => 'Кладка первого и второго этажей с армированием рядов.', 'phase' => 'коробка', 'work' => 'masonry_outer', 'start_offset' => -54, 'duration' => 62, 'progress' => 64, 'status' => 'in_progress', 'priority' => 'critical', 'critical' => true, 'cost' => 18600000, 'resources' => ['people' => 16], 'material_resources' => [['role' => 'Кирпич М150', 'material' => 'brick', 'quantity' => 74260, 'unit_price' => 34.5], ['role' => 'Раствор М100', 'material' => 'mortar', 'quantity' => 74, 'unit_price' => 5100], ['role' => 'Сетка кладочная', 'material' => 'mesh', 'quantity' => 680, 'unit_price' => 185]], 'equipment_resources' => [['role' => 'Леса', 'name' => 'Фасадные леса', 'units' => 2, 'hours' => 360, 'rate' => 420]]],
            ['key' => 'masonry_outer_1f', 'wbs' => '3.1.1', 'name' => 'Наружные стены первого этажа', 'description' => 'Захватки А и Б, контроль вертикали и перевязки углов.', 'phase' => 'коробка', 'work' => 'masonry_outer', 'start_offset' => -54, 'duration' => 26, 'progress' => 100, 'status' => 'completed', 'priority' => 'critical', 'critical' => true, 'cost' => 9200000, 'resources' => ['people' => 14], 'material_resources' => [['role' => 'Кирпич М150', 'material' => 'brick', 'quantity' => 37920, 'unit_price' => 34.5], ['role' => 'Раствор М100', 'material' => 'mortar', 'quantity' => 38, 'unit_price' => 5100]], 'equipment_resources' => []],
            ['key' => 'masonry_inner', 'wbs' => '3.2', 'name' => 'Внутренние перегородки', 'description' => 'Перегородки первого этажа завершены, второй этаж в работе.', 'phase' => 'коробка', 'work' => 'masonry_inner', 'start_offset' => -32, 'duration' => 44, 'progress' => 48, 'status' => 'in_progress', 'priority' => 'high', 'critical' => false, 'cost' => 4200000, 'resources' => ['people' => 10], 'material_resources' => [['role' => 'Кирпич М150', 'material' => 'brick', 'quantity' => 31800, 'unit_price' => 34.5]], 'equipment_resources' => []],
            ['key' => 'masonry_inner_1f', 'wbs' => '3.2.1', 'name' => 'Перегородки первого этажа', 'description' => 'Санузлы, кладовые, шахты инженерных коммуникаций.', 'phase' => 'коробка', 'work' => 'masonry_inner', 'start_offset' => -28, 'duration' => 18, 'progress' => 100, 'status' => 'completed', 'priority' => 'high', 'critical' => false, 'cost' => 1840000, 'resources' => ['people' => 8], 'material_resources' => [['role' => 'Кирпич М150', 'material' => 'brick', 'quantity' => 14200, 'unit_price' => 34.5]], 'equipment_resources' => []],
            ['key' => 'belt', 'wbs' => '3.3', 'name' => 'Армопояса и перемычки', 'description' => 'Армопояса первого этажа, перемычки проемов.', 'phase' => 'коробка', 'work' => 'belt', 'start_offset' => -6, 'duration' => 26, 'progress' => 35, 'status' => 'in_progress', 'priority' => 'critical', 'critical' => true, 'cost' => 5200000, 'resources' => ['people' => 12], 'material_resources' => [['role' => 'Арматура', 'material' => 'rebar', 'quantity' => 4.8, 'unit_price' => 74200]], 'equipment_resources' => [['role' => 'Вибратор', 'name' => 'Вибратор для бетона', 'units' => 2, 'hours' => 72, 'rate' => 300]]],
            ['key' => 'lintels', 'wbs' => '3.4', 'name' => 'Монолитные перемычки проемов', 'description' => 'Каркасы, опалубка и бетонирование перемычек первого этажа.', 'phase' => 'коробка', 'work' => 'belt', 'start_offset' => -2, 'duration' => 14, 'progress' => 55, 'status' => 'in_progress', 'priority' => 'high', 'critical' => true, 'cost' => 2100000, 'resources' => ['people' => 8], 'material_resources' => [['role' => 'Арматура', 'material' => 'rebar', 'quantity' => 1.4, 'unit_price' => 74200], ['role' => 'Бетон B25', 'material' => 'concrete', 'quantity' => 9.2, 'unit_price' => 6800]], 'equipment_resources' => [['role' => 'Вибратор', 'name' => 'Вибратор для бетона', 'units' => 1, 'hours' => 32, 'rate' => 300]]],
            ['key' => 'masonry_outer_2f', 'wbs' => '3.5', 'name' => 'Наружные стены второго этажа', 'description' => 'Кладка захваток Б-Е, контроль проемов и армирования рядов.', 'phase' => 'коробка', 'work' => 'masonry_outer', 'start_offset' => 4, 'duration' => 34, 'progress' => 18, 'status' => 'in_progress', 'priority' => 'critical', 'critical' => true, 'cost' => 7100000, 'resources' => ['people' => 16], 'material_resources' => [['role' => 'Кирпич М150', 'material' => 'brick', 'quantity' => 36340, 'unit_price' => 34.5], ['role' => 'Раствор М100', 'material' => 'mortar', 'quantity' => 36, 'unit_price' => 5100]], 'equipment_resources' => [['role' => 'Леса', 'name' => 'Фасадные леса', 'units' => 2, 'hours' => 192, 'rate' => 420]]],
            ['key' => 'masonry_inner_2f', 'wbs' => '3.6', 'name' => 'Перегородки второго этажа', 'description' => 'Внутренние перегородки после закрытия наружного контура этажа.', 'phase' => 'коробка', 'work' => 'masonry_inner', 'start_offset' => 22, 'duration' => 26, 'progress' => 0, 'status' => 'not_started', 'priority' => 'normal', 'critical' => false, 'cost' => 2360000, 'resources' => ['people' => 9], 'material_resources' => [['role' => 'Кирпич М150', 'material' => 'brick', 'quantity' => 17600, 'unit_price' => 34.5]], 'equipment_resources' => []],
            ['key' => 'slabs', 'wbs' => '4.1', 'name' => 'Плиты перекрытия', 'description' => 'Разгрузка и монтаж плит перекрытия.', 'phase' => 'контур', 'work' => 'slabs', 'start_offset' => 18, 'duration' => 12, 'progress' => 0, 'status' => 'waiting', 'priority' => 'high', 'critical' => true, 'cost' => 3900000, 'resources' => ['people' => 8], 'material_resources' => [['role' => 'Плиты ПБ', 'material' => 'slab', 'quantity' => 32, 'unit_price' => 18600]], 'equipment_resources' => [['role' => 'Автокран', 'name' => 'Автокран 25 т', 'units' => 1, 'hours' => 80, 'rate' => 5200]]],
            ['key' => 'slabs_acceptance', 'wbs' => '4.1.1', 'name' => 'Приемка плит и опорных зон', 'description' => 'Паспорта ЖБИ, ширина опирания, журнал строповки и допуск крана.', 'phase' => 'контур', 'work' => 'quality', 'start_offset' => 15, 'duration' => 5, 'progress' => 0, 'status' => 'waiting', 'priority' => 'high', 'critical' => true, 'cost' => 360000, 'resources' => ['people' => 3], 'material_resources' => [], 'equipment_resources' => []],
            ['key' => 'roof', 'wbs' => '4.2', 'name' => 'Кровля', 'description' => 'Стропильная система, утепление и металлочерепица.', 'phase' => 'контур', 'work' => 'roof', 'start_offset' => 34, 'duration' => 34, 'progress' => 0, 'status' => 'not_started', 'priority' => 'normal', 'critical' => true, 'cost' => 6800000, 'resources' => ['people' => 9], 'material_resources' => [['role' => 'Металлочерепица', 'material' => 'roofing', 'quantity' => 318, 'unit_price' => 920]], 'equipment_resources' => []],
            ['key' => 'windows_contour', 'wbs' => '4.3', 'name' => 'Закрытие теплового контура', 'description' => 'Подготовка проемов, временная защита, контроль влажности перед отделкой.', 'phase' => 'контур', 'work' => 'engineering', 'start_offset' => 58, 'duration' => 20, 'progress' => 0, 'status' => 'not_started', 'priority' => 'normal', 'critical' => false, 'cost' => 2400000, 'resources' => ['people' => 6], 'material_resources' => [], 'equipment_resources' => []],
            ['key' => 'facade', 'wbs' => '5.1', 'name' => 'Фасад и утепление', 'description' => 'Минвата, облицовочный кирпич, расшивка швов.', 'phase' => 'фасад', 'work' => 'facade', 'start_offset' => 72, 'duration' => 48, 'progress' => 0, 'status' => 'not_started', 'priority' => 'normal', 'critical' => false, 'cost' => 11800000, 'resources' => ['people' => 14], 'material_resources' => [['role' => 'Минвата', 'material' => 'insulation', 'quantity' => 96, 'unit_price' => 1520], ['role' => 'Облицовочный кирпич', 'material' => 'facing_brick', 'quantity' => 28400, 'unit_price' => 52]], 'equipment_resources' => []],
            ['key' => 'facade_insulation', 'wbs' => '5.1.1', 'name' => 'Фасадное утепление', 'description' => 'Минеральная вата, крепеж, ветровая защита и контроль мостиков холода.', 'phase' => 'фасад', 'work' => 'facade', 'start_offset' => 72, 'duration' => 22, 'progress' => 0, 'status' => 'not_started', 'priority' => 'normal', 'critical' => false, 'cost' => 4200000, 'resources' => ['people' => 8], 'material_resources' => [['role' => 'Минвата', 'material' => 'insulation', 'quantity' => 96, 'unit_price' => 1520]], 'equipment_resources' => []],
            ['key' => 'facade_facing', 'wbs' => '5.1.2', 'name' => 'Облицовочная кладка фасада', 'description' => 'Лицевой кирпич, расшивка швов, приемка образца фасада.', 'phase' => 'фасад', 'work' => 'facade', 'start_offset' => 94, 'duration' => 32, 'progress' => 0, 'status' => 'not_started', 'priority' => 'normal', 'critical' => false, 'cost' => 7600000, 'resources' => ['people' => 12], 'material_resources' => [['role' => 'Облицовочный кирпич', 'material' => 'facing_brick', 'quantity' => 28400, 'unit_price' => 52]], 'equipment_resources' => []],
            ['key' => 'engineering', 'wbs' => '6.1', 'name' => 'Инженерные вводы', 'description' => 'Закладные, проходки, вводы воды, канализации и электрики.', 'phase' => 'инженерия', 'work' => 'engineering', 'start_offset' => 54, 'duration' => 32, 'progress' => 12, 'status' => 'waiting', 'priority' => 'normal', 'critical' => false, 'cost' => 3900000, 'resources' => ['people' => 6], 'material_resources' => [], 'equipment_resources' => []],
            ['key' => 'rough_electrical', 'wbs' => '6.2', 'name' => 'Черновая электрика и закладные', 'description' => 'Гильзы, проходки, трассы под кабель и приемка скрытых работ.', 'phase' => 'инженерия', 'work' => 'engineering', 'start_offset' => 64, 'duration' => 22, 'progress' => 0, 'status' => 'not_started', 'priority' => 'normal', 'critical' => false, 'cost' => 1950000, 'resources' => ['people' => 5], 'material_resources' => [], 'equipment_resources' => []],
            ['key' => 'rough_plumbing', 'wbs' => '6.3', 'name' => 'Черновые сантехнические вводы', 'description' => 'Вводы воды, канализация, гильзы через фундамент и стены.', 'phase' => 'инженерия', 'work' => 'engineering', 'start_offset' => 70, 'duration' => 20, 'progress' => 0, 'status' => 'not_started', 'priority' => 'normal', 'critical' => false, 'cost' => 1680000, 'resources' => ['people' => 4], 'material_resources' => [], 'equipment_resources' => []],
            ['key' => 'quality', 'wbs' => '7.1', 'name' => 'Исполнительная документация', 'description' => 'Схемы, акты скрытых работ, фотофиксация и отчеты.', 'phase' => 'ПТО', 'work' => 'quality', 'start_offset' => -50, 'duration' => 196, 'progress' => 41, 'status' => 'in_progress', 'priority' => 'normal', 'critical' => false, 'cost' => 1600000, 'resources' => ['people' => 3], 'material_resources' => [], 'equipment_resources' => []],
            ['key' => 'customer_walkthrough', 'wbs' => '7.2', 'name' => 'Обход заказчика по коробке дома', 'description' => 'Фиксация замечаний, фото, срок устранения и связь с графиком.', 'phase' => 'ПТО', 'work' => 'quality', 'start_offset' => 8, 'duration' => 3, 'progress' => 0, 'status' => 'waiting', 'priority' => 'high', 'critical' => false, 'cost' => 180000, 'resources' => ['people' => 4], 'material_resources' => [], 'equipment_resources' => []],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function contractorScheduleTasks(): array
    {
        return [
            ['key' => 'masonry_outer', 'wbs' => '1.1', 'name' => 'Наружные стены первого этажа', 'description' => 'Кладка наружных стен, контроль геометрии, армирование рядов.', 'phase' => 'кладка', 'work' => 'masonry_outer', 'start_offset' => -54, 'duration' => 30, 'progress' => 100, 'status' => 'completed', 'priority' => 'critical', 'critical' => true, 'cost' => 9200000, 'resources' => ['people' => 14], 'material_resources' => [['role' => 'Кирпич М150', 'material' => 'brick', 'quantity' => 37920, 'unit_price' => 34.5], ['role' => 'Раствор М100', 'material' => 'mortar', 'quantity' => 38, 'unit_price' => 5100]], 'equipment_resources' => []],
            ['key' => 'masonry_outer_axis_a', 'wbs' => '1.1.1', 'name' => 'Кладка наружных стен по осям А-Б', 'description' => 'Первая захватка, контроль перевязки углов и армирования.', 'phase' => 'кладка', 'work' => 'masonry_outer', 'start_offset' => -54, 'duration' => 12, 'progress' => 100, 'status' => 'completed', 'priority' => 'critical', 'critical' => true, 'cost' => 3650000, 'resources' => ['people' => 10], 'material_resources' => [['role' => 'Кирпич М150', 'material' => 'brick', 'quantity' => 14800, 'unit_price' => 34.5]], 'equipment_resources' => []],
            ['key' => 'masonry_outer_axis_c', 'wbs' => '1.1.2', 'name' => 'Кладка наружных стен по осям В-Е', 'description' => 'Вторая захватка, оконные проемы и контроль вертикали.', 'phase' => 'кладка', 'work' => 'masonry_outer', 'start_offset' => -42, 'duration' => 16, 'progress' => 100, 'status' => 'completed', 'priority' => 'critical', 'critical' => true, 'cost' => 4200000, 'resources' => ['people' => 12], 'material_resources' => [['role' => 'Кирпич М150', 'material' => 'brick', 'quantity' => 16800, 'unit_price' => 34.5], ['role' => 'Раствор М100', 'material' => 'mortar', 'quantity' => 16.8, 'unit_price' => 5100]], 'equipment_resources' => []],
            ['key' => 'masonry_inner', 'wbs' => '1.2', 'name' => 'Перегородки первого этажа', 'description' => 'Внутренние перегородки и проемы под инженерные коммуникации.', 'phase' => 'кладка', 'work' => 'masonry_inner', 'start_offset' => -28, 'duration' => 22, 'progress' => 100, 'status' => 'completed', 'priority' => 'high', 'critical' => false, 'cost' => 3600000, 'resources' => ['people' => 9], 'material_resources' => [['role' => 'Кирпич М150', 'material' => 'brick', 'quantity' => 18200, 'unit_price' => 34.5]], 'equipment_resources' => []],
            ['key' => 'masonry_inner_wet_zones', 'wbs' => '1.2.1', 'name' => 'Перегородки мокрых зон', 'description' => 'Санузлы и техпомещение с проемами под инженерные стояки.', 'phase' => 'кладка', 'work' => 'masonry_inner', 'start_offset' => -25, 'duration' => 8, 'progress' => 100, 'status' => 'completed', 'priority' => 'normal', 'critical' => false, 'cost' => 980000, 'resources' => ['people' => 6], 'material_resources' => [['role' => 'Кирпич М150', 'material' => 'brick', 'quantity' => 5200, 'unit_price' => 34.5]], 'equipment_resources' => []],
            ['key' => 'belt', 'wbs' => '1.3', 'name' => 'Армопояс первого этажа', 'description' => 'Каркасы, опалубка, бетонирование армопояса.', 'phase' => 'железобетон', 'work' => 'belt', 'start_offset' => -6, 'duration' => 18, 'progress' => 45, 'status' => 'in_progress', 'priority' => 'critical', 'critical' => true, 'cost' => 5100000, 'resources' => ['people' => 10], 'material_resources' => [['role' => 'Арматура', 'material' => 'rebar', 'quantity' => 2.8, 'unit_price' => 74200], ['role' => 'Сетка', 'material' => 'mesh', 'quantity' => 140, 'unit_price' => 185]], 'equipment_resources' => [['role' => 'Вибратор', 'name' => 'Вибратор для бетона', 'units' => 2, 'hours' => 48, 'rate' => 300]]],
            ['key' => 'lintels', 'wbs' => '1.3.1', 'name' => 'Перемычки первого этажа', 'description' => 'Опалубка, каркасы и бетонирование перемычек по проемам ПР-1 - ПР-6.', 'phase' => 'железобетон', 'work' => 'belt', 'start_offset' => -3, 'duration' => 10, 'progress' => 60, 'status' => 'in_progress', 'priority' => 'high', 'critical' => true, 'cost' => 1460000, 'resources' => ['people' => 7], 'material_resources' => [['role' => 'Арматура', 'material' => 'rebar', 'quantity' => 0.9, 'unit_price' => 74200], ['role' => 'Бетон B25', 'material' => 'concrete', 'quantity' => 6.4, 'unit_price' => 6800]], 'equipment_resources' => [['role' => 'Вибратор', 'name' => 'Вибратор для бетона', 'units' => 1, 'hours' => 24, 'rate' => 300]]],
            ['key' => 'masonry_second_outer', 'wbs' => '2.1', 'name' => 'Наружные стены второго этажа', 'description' => 'Кладка второй очереди, захватки Б-Е, подготовка под армопояс.', 'phase' => 'кладка второго этажа', 'work' => 'masonry_outer', 'start_offset' => 4, 'duration' => 30, 'progress' => 18, 'status' => 'in_progress', 'priority' => 'critical', 'critical' => true, 'cost' => 8900000, 'resources' => ['people' => 14], 'material_resources' => [['role' => 'Кирпич М150', 'material' => 'brick', 'quantity' => 36340, 'unit_price' => 34.5], ['role' => 'Раствор М100', 'material' => 'mortar', 'quantity' => 36, 'unit_price' => 5100]], 'equipment_resources' => []],
            ['key' => 'masonry_second_inner', 'wbs' => '2.2', 'name' => 'Перегородки второго этажа', 'description' => 'Подготовка фронта после закрытия наружных стен второго этажа.', 'phase' => 'кладка второго этажа', 'work' => 'masonry_inner', 'start_offset' => 22, 'duration' => 20, 'progress' => 0, 'status' => 'not_started', 'priority' => 'normal', 'critical' => false, 'cost' => 2100000, 'resources' => ['people' => 8], 'material_resources' => [['role' => 'Кирпич М150', 'material' => 'brick', 'quantity' => 11600, 'unit_price' => 34.5]], 'equipment_resources' => []],
            ['key' => 'slabs', 'wbs' => '1.4', 'name' => 'Подготовка к плитам перекрытия', 'description' => 'Разметка опорных зон, подача перемычек, координация автокрана.', 'phase' => 'перекрытия', 'work' => 'slabs', 'start_offset' => 18, 'duration' => 10, 'progress' => 0, 'status' => 'waiting', 'priority' => 'high', 'critical' => true, 'cost' => 2600000, 'resources' => ['people' => 8], 'material_resources' => [], 'equipment_resources' => [['role' => 'Автокран', 'name' => 'Автокран 25 т', 'units' => 1, 'hours' => 40, 'rate' => 5200]]],
            ['key' => 'slabs_support_check', 'wbs' => '3.1', 'name' => 'Проверка опорных зон под плиты', 'description' => 'Геометрия армопояса, ширина опирания, готовность к допуску крана.', 'phase' => 'перекрытия', 'work' => 'quality', 'start_offset' => 14, 'duration' => 4, 'progress' => 0, 'status' => 'waiting', 'priority' => 'high', 'critical' => true, 'cost' => 240000, 'resources' => ['people' => 3], 'material_resources' => [], 'equipment_resources' => []],
            ['key' => 'quality', 'wbs' => '1.5', 'name' => 'Исполнительная документация кладки', 'description' => 'Фотофиксация, схемы, акты скрытых работ по армированию.', 'phase' => 'ПТО', 'work' => 'quality', 'start_offset' => -54, 'duration' => 82, 'progress' => 52, 'status' => 'in_progress', 'priority' => 'normal', 'critical' => false, 'cost' => 840000, 'resources' => ['people' => 2], 'material_resources' => [], 'equipment_resources' => []],
            ['key' => 'handover_pack', 'wbs' => '4.1', 'name' => 'Пакет передачи генподряду', 'description' => 'Исполнительные схемы, фото скрытых работ, реестр замечаний и статусы устранения.', 'phase' => 'ПТО', 'work' => 'quality', 'start_offset' => 24, 'duration' => 8, 'progress' => 0, 'status' => 'not_started', 'priority' => 'high', 'critical' => false, 'cost' => 420000, 'resources' => ['people' => 2], 'material_resources' => [], 'equipment_resources' => []],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function generalContractorAccount(): array
    {
        return [
            'contour' => 'general_contractor',
            'email' => 'demo.general-contractor@most.test',
            'name' => 'Алексей Мельников',
            'position' => 'Руководитель проекта генподряда',
            'organization_name' => 'Демо Генподряд "Кирпичный квартал"',
            'legal_name' => 'ООО "Кирпичный квартал Генподряд"',
            'tax_number' => '7701000001',
            'registration_number' => '1027701000001',
            'organization_email' => 'office.gp@most.test',
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
            'email' => 'demo.contractor@most.test',
            'name' => 'Ирина Соколова',
            'position' => 'Директор подрядной организации',
            'organization_name' => 'Демо Подряд "МастерКлад"',
            'legal_name' => 'ООО "МастерКлад Подряд"',
            'tax_number' => '7701000002',
            'registration_number' => '1027701000002',
            'organization_email' => 'office.sub@most.test',
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
        if (
            !$exists
            && Schema::hasColumn($table, 'uuid')
            && !array_key_exists('uuid', $keys)
            && !array_key_exists('uuid', $values)
        ) {
            $values['uuid'] = (string) Str::uuid();
        }

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
