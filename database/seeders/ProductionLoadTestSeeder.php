<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\BusinessModules\Features\SiteRequests\Enums\EquipmentTypeEnum;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Enums\Contract\ContractWorkTypeCategoryEnum;
use App\Models\Project;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ProductionLoadTestSeeder extends Seeder
{
    private const CONFIRM_ENV = 'MOST_LOAD_TEST_SEEDER_CONFIRM';
    private const CONFIRM_VALUE = 'yes';
    private const PASSWORD = 'LoadTest123!';

    private Carbon $now;

    public function run(): void
    {
        if ($this->envString(self::CONFIRM_ENV) !== self::CONFIRM_VALUE) {
            $this->command?->error(sprintf(
                'Production load-test seeder skipped. Set %s=%s to run it intentionally.',
                self::CONFIRM_ENV,
                self::CONFIRM_VALUE
            ));

            return;
        }

        $this->now = now();

        $settings = [
            'organizations' => $this->envInt('MOST_LOAD_TEST_ORGS', 6, 1, 20),
            'users_per_organization' => $this->envInt('MOST_LOAD_TEST_USERS_PER_ORG', 5, 1, 20),
            'projects_per_organization' => $this->envInt('MOST_LOAD_TEST_PROJECTS_PER_ORG', 3, 1, 20),
            'materials_per_organization' => $this->envInt('MOST_LOAD_TEST_MATERIALS_PER_ORG', 24, 1, 100),
            'site_requests_per_organization' => $this->envInt('MOST_LOAD_TEST_SITE_REQUESTS_PER_ORG', 60, 0, 500),
            'schedule_tasks_per_project' => $this->envInt('MOST_LOAD_TEST_TASKS_PER_PROJECT', 24, 0, 200),
            'payments_per_organization' => $this->envInt('MOST_LOAD_TEST_PAYMENTS_PER_ORG', 18, 0, 200),
        ];

        $result = DB::transaction(function () use ($settings): array {
            $accounts = [];
            $totals = [
                'organizations' => 0,
                'users' => 0,
                'projects' => 0,
                'materials' => 0,
                'warehouses' => 0,
                'site_requests' => 0,
                'schedule_tasks' => 0,
                'payment_documents' => 0,
                'contracts' => 0,
                'contractors' => 0,
                'suppliers' => 0,
            ];

            for ($organizationIndex = 1; $organizationIndex <= $settings['organizations']; $organizationIndex++) {
                $organizationId = $this->seedOrganization($organizationIndex);
                $users = $this->seedUsers(
                    $organizationId,
                    $organizationIndex,
                    $settings['users_per_organization'],
                    $accounts
                );
                $this->activateModules($organizationId);

                $units = $this->seedMeasurementUnits($organizationId);
                $materials = $this->seedMaterials(
                    $organizationId,
                    $organizationIndex,
                    $settings['materials_per_organization'],
                    $units
                );
                $suppliers = $this->seedSuppliers($organizationId, $organizationIndex);
                $contractors = $this->seedContractors($organizationId, $organizationIndex);
                $projects = $this->seedProjects(
                    $organizationId,
                    $organizationIndex,
                    $settings['projects_per_organization'],
                    $users
                );

                $this->seedWarehouses($organizationId, $organizationIndex, $users, $projects, $materials);
                $totals['contracts'] += $this->seedContracts($organizationId, $organizationIndex, $projects, $contractors);
                $totals['payment_documents'] += $this->seedPaymentDocuments(
                    $organizationId,
                    $organizationIndex,
                    $projects,
                    $users,
                    $settings['payments_per_organization']
                );

                $siteRequestsPerProject = $this->splitCount(
                    $settings['site_requests_per_organization'],
                    count($projects)
                );

                foreach ($projects as $projectOffset => $projectId) {
                    $this->seedProjectMembership($organizationId, $projectId, $users);
                    $totals['schedule_tasks'] += $this->seedSchedule(
                        $organizationId,
                        $organizationIndex,
                        $projectId,
                        $projectOffset + 1,
                        $users,
                        $settings['schedule_tasks_per_project']
                    );
                    $totals['site_requests'] += $this->seedSiteRequests(
                        $organizationId,
                        $organizationIndex,
                        $projectId,
                        $projectOffset + 1,
                        $users,
                        $materials,
                        $siteRequestsPerProject[$projectOffset] ?? 0
                    );
                }

                $totals['organizations']++;
                $totals['users'] += count($users);
                $totals['projects'] += count($projects);
                $totals['materials'] += count($materials);
                $totals['warehouses'] += Schema::hasTable('organization_warehouses') ? 2 : 0;
                $totals['contractors'] += count($contractors);
                $totals['suppliers'] += count($suppliers);
            }

            return [
                'settings' => $settings,
                'totals' => $totals,
                'accounts' => $accounts,
            ];
        });

        $this->command?->info('Production load-test data prepared.');
        $this->command?->table(
            ['Metric', 'Value'],
            array_map(
                static fn (string $key, int $value): array => [$key, (string) $value],
                array_keys($result['totals']),
                $result['totals']
            )
        );
        $this->command?->newLine();
        $this->command?->info('Load-test accounts:');
        $this->command?->table(
            ['Organization', 'Role', 'Email', 'Password'],
            $result['accounts']
        );
    }

    private function seedOrganization(int $organizationIndex): int
    {
        $code = sprintf('LT%02d', $organizationIndex);

        return $this->upsert('organizations', [
            'tax_number' => sprintf('9901%06d', $organizationIndex),
        ], [
            'name' => "Load Test Company {$code}",
            'legal_name' => "OOO Load Test Company {$code}",
            'registration_number' => sprintf('129901%07d', $organizationIndex),
            'phone' => sprintf('+7 495 901-%02d-%02d', $organizationIndex, $organizationIndex),
            'email' => sprintf('loadtest-org%02d@prohelper.test', $organizationIndex),
            'address' => "Moscow, Load Test Street, {$organizationIndex}",
            'city' => 'Moscow',
            'postal_code' => sprintf('109%03d', $organizationIndex),
            'country' => 'RU',
            'description' => 'Synthetic organization for production load testing before real customer onboarding.',
            'is_active' => true,
            'subscription_expires_at' => $this->now->copy()->addYear(),
            'is_verified' => true,
            'verified_at' => $this->now,
            'verification_status' => 'verified',
            'verification_data' => $this->json([
                'source' => 'production_load_test_seeder',
                'load_test' => true,
            ]),
            'organization_type' => 'single',
            'is_holding' => false,
            'hierarchy_level' => 0,
            'capabilities' => $this->json(['general_contracting', 'subcontracting', 'materials_supply']),
            'primary_business_type' => 'general_contracting',
            'specializations' => $this->json(['construction_management', 'warehouse_management', 'site_operations']),
            'certifications' => $this->json(['load_test_certificate']),
            'profile_completeness' => 100,
            'onboarding_completed' => true,
            'onboarding_completed_at' => $this->now,
        ]);
    }

    private function seedUsers(int $organizationId, int $organizationIndex, int $count, array &$accounts): array
    {
        $roles = [
            ['suffix' => 'owner', 'name' => 'Owner', 'position' => 'Director', 'role' => 'organization_owner'],
            ['suffix' => 'admin', 'name' => 'Admin', 'position' => 'Operations manager', 'role' => 'organization_admin'],
            ['suffix' => 'pm', 'name' => 'Project Manager', 'position' => 'Project manager', 'role' => 'organization_admin'],
            ['suffix' => 'foreman', 'name' => 'Foreman', 'position' => 'Site foreman', 'role' => 'viewer'],
            ['suffix' => 'accountant', 'name' => 'Accountant', 'position' => 'Accountant', 'role' => 'accountant'],
            ['suffix' => 'viewer', 'name' => 'Viewer', 'position' => 'Observer', 'role' => 'viewer'],
        ];

        $users = [];
        $context = AuthorizationContext::getOrganizationContext($organizationId);

        for ($userIndex = 1; $userIndex <= $count; $userIndex++) {
            $role = $roles[$userIndex - 1] ?? [
                'suffix' => "user{$userIndex}",
                'name' => "User {$userIndex}",
                'position' => 'Employee',
                'role' => 'viewer',
            ];

            $email = sprintf(
                'loadtest-org%02d-%s@prohelper.test',
                $organizationIndex,
                $role['suffix']
            );

            $userId = $this->upsert('users', ['email' => $email], [
                'name' => sprintf('LT%02d %s', $organizationIndex, $role['name']),
                'email_verified_at' => $this->now,
                'password' => Hash::make(self::PASSWORD),
                'phone' => sprintf('+7 916 %03d-%02d-%02d', $organizationIndex, $userIndex, $userIndex),
                'position' => $role['position'],
                'is_active' => true,
                'current_organization_id' => $organizationId,
                'settings' => $this->json([
                    'load_test' => true,
                    'organization_index' => $organizationIndex,
                    'user_index' => $userIndex,
                ]),
                'has_completed_onboarding' => true,
            ]);

            $this->upsert('organization_user', [
                'organization_id' => $organizationId,
                'user_id' => $userId,
            ], [
                'is_owner' => $role['role'] === 'organization_owner',
                'is_active' => true,
                'settings' => $this->json(['load_test' => true]),
                'project_access_mode' => 'all_projects',
            ]);

            $this->assignRole($userId, $role['role'], $context->id, $users['owner'] ?? null);

            $users[$role['suffix']] = $userId;
            $accounts[] = [
                sprintf('LT%02d', $organizationIndex),
                $role['role'],
                $email,
                self::PASSWORD,
            ];
        }

        return $users;
    }

    private function activateModules(int $organizationId): void
    {
        $modules = [
            ['slug' => 'project-management', 'name' => 'Project management', 'category' => 'core'],
            ['slug' => 'schedule-management', 'name' => 'Schedule management', 'category' => 'planning'],
            ['slug' => 'basic-warehouse', 'name' => 'Warehouse', 'category' => 'warehouse'],
            ['slug' => 'catalog-management', 'name' => 'Catalogs', 'category' => 'catalog'],
            ['slug' => 'payments', 'name' => 'Payments', 'category' => 'finance'],
            ['slug' => 'reports', 'name' => 'Reports', 'category' => 'analytics'],
            ['slug' => 'site-requests', 'name' => 'Site requests', 'category' => 'field'],
            ['slug' => 'procurement', 'name' => 'Procurement', 'category' => 'procurement'],
            ['slug' => 'dashboard-widgets', 'name' => 'Dashboard widgets', 'category' => 'analytics'],
            ['slug' => 'data-filters', 'name' => 'Data filters', 'category' => 'productivity'],
            ['slug' => 'ai-assistant', 'name' => 'AI assistant', 'category' => 'analytics'],
        ];

        foreach ($modules as $index => $module) {
            $moduleId = $this->upsert('modules', ['slug' => $module['slug']], [
                'name' => $module['name'],
                'version' => '1.0.0',
                'type' => in_array($module['slug'], ['payments', 'reports'], true) ? 'core' : 'feature',
                'billing_model' => 'free',
                'category' => $module['category'],
                'description' => $module['name'],
                'pricing_config' => $this->json(['base_price' => 0, 'currency' => 'RUB']),
                'features' => $this->json(['load_test' => true]),
                'permissions' => $this->json(['admin.*']),
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

            $this->upsert('organization_module_activations', [
                'organization_id' => $organizationId,
                'module_id' => $moduleId,
            ], [
                'status' => 'active',
                'activated_at' => $this->now,
                'expires_at' => null,
                'trial_ends_at' => null,
                'last_used_at' => $this->now,
                'paid_amount' => 0,
                'payment_details' => $this->json(['source' => 'production_load_test_seeder']),
                'module_settings' => $this->json(['load_test' => true]),
                'usage_stats' => $this->json([]),
                'is_bundled_with_plan' => true,
                'is_auto_renew_enabled' => false,
            ]);
        }
    }

    private function seedMeasurementUnits(int $organizationId): array
    {
        $units = [
            'm3' => ['name' => 'Cubic meter', 'short_name' => 'm3'],
            'ton' => ['name' => 'Ton', 'short_name' => 't'],
            'piece' => ['name' => 'Piece', 'short_name' => 'pcs'],
            'pack' => ['name' => 'Pack', 'short_name' => 'pack'],
            'hour' => ['name' => 'Hour', 'short_name' => 'h'],
        ];

        $ids = [];

        foreach ($units as $key => $unit) {
            $ids[$key] = $this->upsert('measurement_units', [
                'organization_id' => $organizationId,
                'short_name' => $unit['short_name'],
            ], [
                'name' => $unit['name'],
                'type' => $key === 'hour' ? 'work' : 'material',
                'description' => 'Load-test unit',
                'is_default' => $key === 'piece',
                'is_system' => false,
            ]);
        }

        return array_filter($ids, static fn (int $id): bool => $id > 0);
    }

    private function seedMaterials(int $organizationId, int $organizationIndex, int $count, array $units): array
    {
        $names = [
            ['Concrete B25 P4', 'm3', 'Concrete'],
            ['Rebar A500C 12 mm', 'ton', 'Metal'],
            ['Ceramic brick M150', 'piece', 'Masonry'],
            ['Mineral wool 100 mm', 'pack', 'Insulation'],
            ['Dry mix M300', 'pack', 'Mortars'],
            ['Waterproofing membrane', 'piece', 'Waterproofing'],
            ['Cement PC500', 'pack', 'Binders'],
            ['Sand washed', 'm3', 'Aggregates'],
        ];

        $materials = [];

        for ($index = 1; $index <= $count; $index++) {
            $template = $names[($index - 1) % count($names)];
            $code = sprintf('LT%02d-MAT-%03d', $organizationIndex, $index);
            $unitKey = $template[1];

            $materialId = $this->upsert('materials', [
                'organization_id' => $organizationId,
                'code' => $code,
            ], [
                'name' => sprintf('%s %03d', $template[0], $index),
                'measurement_unit_id' => $units[$unitKey] ?? null,
                'description' => 'Synthetic material for production load testing.',
                'category' => $template[2],
                'default_price' => 350 + ($index * 175),
                'additional_properties' => $this->json([
                    'load_test' => true,
                    'code' => $code,
                ]),
                'is_active' => true,
                'is_onboarding_demo' => false,
                'use_in_accounting_reports' => true,
            ]);

            if ($materialId > 0) {
                $materials[] = $materialId;
            }
        }

        return $materials;
    }

    private function seedSuppliers(int $organizationId, int $organizationIndex): array
    {
        $suppliers = [];

        for ($index = 1; $index <= 6; $index++) {
            $code = sprintf('LT%02d-SUP-%02d', $organizationIndex, $index);
            $supplierId = $this->upsert('suppliers', [
                'organization_id' => $organizationId,
                'code' => $code,
            ], [
                'name' => "Load Test Supplier {$code}",
                'contact_person' => "Supplier Contact {$index}",
                'phone' => sprintf('+7 495 920-%02d-%02d', $organizationIndex, $index),
                'email' => strtolower($code) . '@prohelper.test',
                'address' => "Moscow, Supplier Street, {$index}",
                'tax_number' => sprintf('7702%06d', ($organizationIndex * 100) + $index),
                'description' => 'Synthetic supplier for production load testing.',
                'is_active' => true,
                'additional_info' => $this->json(['load_test' => true]),
            ]);

            if ($supplierId > 0) {
                $suppliers[] = $supplierId;
            }
        }

        return $suppliers;
    }

    private function seedContractors(int $organizationId, int $organizationIndex): array
    {
        $contractors = [];

        for ($index = 1; $index <= 8; $index++) {
            $contractorId = $this->upsert('contractors', [
                'inn' => sprintf('7803%06d', ($organizationIndex * 100) + $index),
            ], [
                'organization_id' => $organizationId,
                'name' => sprintf('Load Test Contractor LT%02d-%02d', $organizationIndex, $index),
                'contact_person' => "Contractor Contact {$index}",
                'phone' => sprintf('+7 495 930-%02d-%02d', $organizationIndex, $index),
                'email' => sprintf('lt%02d-contractor%02d@prohelper.test', $organizationIndex, $index),
                'legal_address' => "Moscow, Contractor Street, {$index}",
                'kpp' => sprintf('7803%05d', $index),
                'bank_details' => $this->json([
                    'bank' => 'Load Test Bank',
                    'account' => sprintf('407028109000000%05d', $index),
                ]),
                'notes' => 'Synthetic contractor for production load testing.',
            ]);

            if ($contractorId > 0) {
                $contractors[] = $contractorId;
            }
        }

        return $contractors;
    }

    private function seedProjects(int $organizationId, int $organizationIndex, int $count, array $users): array
    {
        $projects = [];

        for ($index = 1; $index <= $count; $index++) {
            $code = sprintf('LT%02d-PRJ-%02d', $organizationIndex, $index);
            $projectId = $this->upsert('projects', [
                'organization_id' => $organizationId,
                'external_code' => $code,
            ], [
                'name' => "Load Test Project {$code}",
                'address' => "Moscow, Project Avenue, {$organizationIndex}/{$index}",
                'latitude' => 55.70 + ($organizationIndex / 100) + ($index / 1000),
                'longitude' => 37.50 + ($organizationIndex / 100) + ($index / 1000),
                'geocoded_at' => $this->now,
                'geocoding_status' => 'success',
                'description' => 'Synthetic construction project for API load testing.',
                'customer' => "Customer LT{$organizationIndex}",
                'designer' => "Designer LT{$organizationIndex}",
                'budget_amount' => 85000000 + ($index * 12500000),
                'site_area_m2' => 12000 + ($index * 900),
                'start_date' => $this->now->copy()->subMonths(4)->addDays($index)->toDateString(),
                'end_date' => $this->now->copy()->addMonths(12)->addDays($index * 5)->toDateString(),
                'status' => $index % 5 === 0 ? 'paused' : 'active',
                'additional_info' => $this->json([
                    'load_test' => true,
                    'readiness_percent' => min(92, 20 + ($index * 12)),
                ]),
                'is_archived' => false,
                'is_onboarding_demo' => false,
                'is_head' => $index === 1,
                'customer_organization' => "Load Test Customer {$organizationIndex}",
                'customer_representative' => 'Load Test Representative',
                'contract_number' => sprintf('LT-%02d-%02d/2026', $organizationIndex, $index),
                'contract_date' => $this->now->copy()->subMonths(5)->toDateString(),
                'use_in_accounting_reports' => true,
                'accounting_data' => $this->json(['load_test' => true]),
            ]);

            if ($projectId > 0) {
                $projects[] = $projectId;
                $this->seedProjectMembership($organizationId, $projectId, $users);
            }
        }

        return $projects;
    }

    private function seedProjectMembership(int $organizationId, int $projectId, array $users): void
    {
        $ownerId = $users['owner'] ?? reset($users);

        $this->upsert('project_organization', [
            'project_id' => $projectId,
            'organization_id' => $organizationId,
        ], [
            'role' => 'owner',
            'role_new' => 'owner',
            'permissions' => $this->json(['*']),
            'is_active' => true,
            'added_by_user_id' => $ownerId,
            'invited_at' => $this->now,
            'accepted_at' => $this->now,
            'metadata' => $this->json(['load_test' => true]),
        ]);

        $projectContext = AuthorizationContext::getProjectContext($projectId, $organizationId);
        $projectRoles = [
            'owner' => 'project_manager',
            'admin' => 'project_manager',
            'pm' => 'project_manager',
            'foreman' => 'foreman',
            'accountant' => 'site_engineer',
            'viewer' => 'project_viewer',
        ];

        foreach ($users as $suffix => $userId) {
            $this->upsert('project_user', [
                'project_id' => $projectId,
                'user_id' => $userId,
            ], [
                'role' => $projectRoles[$suffix] ?? 'project_viewer',
                'assigned_by_user_id' => $ownerId,
                'is_active' => true,
                'assigned_at' => $this->now,
            ]);

            $this->assignRole(
                $userId,
                $projectRoles[$suffix] ?? 'project_viewer',
                $projectContext->id,
                is_int($ownerId) ? $ownerId : null
            );
        }
    }

    private function seedWarehouses(int $organizationId, int $organizationIndex, array $users, array $projects, array $materials): void
    {
        if (!Schema::hasTable('organization_warehouses')) {
            return;
        }

        $ownerId = $users['owner'] ?? reset($users);
        $warehouseIds = [];

        for ($index = 1; $index <= 2; $index++) {
            $code = sprintf('LT%02d-WH-%02d', $organizationIndex, $index);
            $warehouseIds[] = $this->upsert('organization_warehouses', [
                'organization_id' => $organizationId,
                'code' => $code,
            ], [
                'name' => "Load Test Warehouse {$code}",
                'address' => "Moscow, Warehouse Zone {$organizationIndex}-{$index}",
                'description' => 'Synthetic warehouse for production load testing.',
                'warehouse_type' => $index === 1 ? 'central' : 'project',
                'is_main' => $index === 1,
                'is_active' => true,
                'settings' => $this->json(['load_test' => true]),
                'contact_person' => "Warehouse Contact {$index}",
                'contact_phone' => sprintf('+7 495 940-%02d-%02d', $organizationIndex, $index),
                'working_hours' => '08:00-20:00',
                'storage_conditions' => $this->json(['heated' => $index === 1, 'security' => true]),
            ]);
        }

        foreach ($warehouseIds as $warehouseOffset => $warehouseId) {
            if ($warehouseId === 0) {
                continue;
            }

            foreach ($materials as $materialOffset => $materialId) {
                $available = 20 + (($materialOffset + 1) * 3) + ($warehouseOffset * 5);
                $reserved = ($materialOffset % 4) + 1;
                $unitPrice = 450 + (($materialOffset + 1) * 210);

                $values = [
                    'organization_id' => $organizationId,
                    'available_quantity' => $available,
                    'reserved_quantity' => $reserved,
                    'unit_price' => $unitPrice,
                    'average_price' => $unitPrice,
                    'min_stock_level' => 8,
                    'max_stock_level' => 250,
                    'location_code' => sprintf('A-%02d-%02d', $warehouseOffset + 1, $materialOffset + 1),
                    'batch_number' => sprintf('LT%02d-BATCH-%03d', $organizationIndex, $materialOffset + 1),
                    'expiry_date' => $this->now->copy()->addMonths(8)->toDateString(),
                    'last_movement_at' => $this->now->copy()->subDays($materialOffset % 10),
                ];

                $this->upsert('warehouse_balances', [
                    'warehouse_id' => $warehouseId,
                    'material_id' => $materialId,
                ], $values);

                if (!Schema::hasTable('warehouse_movements')) {
                    continue;
                }

                $projectId = $projects[$materialOffset % max(1, count($projects))] ?? null;

                for ($movementIndex = 1; $movementIndex <= 3; $movementIndex++) {
                    $movementType = $movementIndex === 1 ? 'receipt' : ($movementIndex === 2 ? 'write_off' : 'adjustment');

                    $this->upsert('warehouse_movements', [
                        'document_number' => sprintf(
                            'LT%02d-WH%02d-MAT%03d-%02d',
                            $organizationIndex,
                            $warehouseOffset + 1,
                            $materialOffset + 1,
                            $movementIndex
                        ),
                    ], [
                        'organization_id' => $organizationId,
                        'warehouse_id' => $warehouseId,
                        'material_id' => $materialId,
                        'movement_type' => $movementType,
                        'quantity' => $movementIndex === 1 ? $available + $reserved : max(1, $reserved),
                        'price' => $unitPrice,
                        'price_per_unit' => $unitPrice,
                        'project_id' => $projectId,
                        'user_id' => is_int($ownerId) ? $ownerId : null,
                        'reason' => 'Load-test warehouse movement',
                        'notes' => 'Load-test warehouse movement',
                        'metadata' => $this->json(['load_test' => true]),
                        'movement_date' => $this->now->copy()->subDays(($materialOffset % 21) + $movementIndex),
                    ]);
                }
            }
        }
    }

    private function seedContracts(int $organizationId, int $organizationIndex, array $projects, array $contractors): int
    {
        if (!Schema::hasTable('contracts') || $contractors === []) {
            return 0;
        }

        $count = 0;

        foreach ($projects as $projectOffset => $projectId) {
            for ($index = 1; $index <= 3; $index++) {
                $contractNumber = sprintf('LT%02d-CNT-%02d-%02d', $organizationIndex, $projectOffset + 1, $index);

                $this->upsert('contracts', [
                    'organization_id' => $organizationId,
                    'number' => $contractNumber,
                ], [
                    'project_id' => $projectId,
                    'contractor_id' => $contractors[($projectOffset + $index) % count($contractors)],
                    'date' => $this->now->copy()->subMonths(3)->addDays($index)->toDateString(),
                    'type' => 'contract',
                    'subject' => "Load-test contract {$contractNumber}",
                    'work_type_category' => ContractWorkTypeCategoryEnum::GENERAL_CONSTRUCTION->value,
                    'payment_terms' => 'Net 15',
                    'total_amount' => 4500000 + ($index * 1200000),
                    'gp_percentage' => 5,
                    'planned_advance_amount' => 500000,
                    'status' => $index === 1 ? 'active' : 'draft',
                    'start_date' => $this->now->copy()->subMonths(2)->toDateString(),
                    'end_date' => $this->now->copy()->addMonths(8)->toDateString(),
                    'notes' => 'Synthetic contract for production load testing.',
                ]);

                $count++;
            }
        }

        return $count;
    }

    private function seedPaymentDocuments(int $organizationId, int $organizationIndex, array $projects, array $users, int $count): int
    {
        if (!Schema::hasTable('payment_documents') || $projects === []) {
            return 0;
        }

        $ownerId = $users['owner'] ?? reset($users);
        $approverId = $users['admin'] ?? $ownerId;
        $statuses = ['draft', 'submitted', 'approved', 'scheduled', 'paid'];
        $types = ['payment_request', 'invoice', 'payment_order', 'expense'];
        $seeded = 0;

        for ($index = 1; $index <= $count; $index++) {
            $amount = 125000 + ($index * 37500);
            $vatAmount = round($amount * 20 / 120, 2);
            $paidAmount = $statuses[$index % count($statuses)] === 'paid' ? $amount : 0;
            $number = sprintf('LT%02d-PAY-%04d', $organizationIndex, $index);
            $status = $statuses[$index % count($statuses)];

            $this->upsert('payment_documents', [
                'document_number' => $number,
            ], [
                'organization_id' => $organizationId,
                'project_id' => $projects[($index - 1) % count($projects)],
                'document_type' => $types[$index % count($types)],
                'document_date' => $this->now->copy()->subDays($index)->toDateString(),
                'direction' => $index % 3 === 0 ? 'incoming' : 'outgoing',
                'invoice_type' => 'progress',
                'payer_organization_id' => $organizationId,
                'payee_organization_id' => $organizationId,
                'amount' => $amount,
                'currency' => 'RUB',
                'vat_amount' => $vatAmount,
                'vat_rate' => 20,
                'amount_without_vat' => $amount - $vatAmount,
                'paid_amount' => $paidAmount,
                'remaining_amount' => $amount - $paidAmount,
                'status' => $status,
                'workflow_stage' => $status === 'paid' ? 'completed' : 'payment',
                'source_type' => Project::class,
                'source_id' => $projects[($index - 1) % count($projects)],
                'due_date' => $this->now->copy()->addDays(($index % 12) + 1)->toDateString(),
                'payment_terms_days' => 10,
                'description' => "Load-test payment {$number}",
                'payment_purpose' => "Load-test payment {$number}",
                'attached_documents' => $this->json([]),
                'metadata' => $this->json(['load_test' => true]),
                'notes' => 'Synthetic payment document for production load testing.',
                'created_by_user_id' => is_int($ownerId) ? $ownerId : null,
                'approved_by_user_id' => is_int($approverId) ? $approverId : null,
                'submitted_at' => $this->now->copy()->subDays($index),
                'approved_at' => in_array($status, ['approved', 'scheduled', 'paid'], true) ? $this->now->copy()->subDays(max(1, $index - 1)) : null,
                'scheduled_at' => in_array($status, ['scheduled', 'paid'], true) ? $this->now->copy()->subDay() : null,
                'paid_at' => $status === 'paid' ? $this->now->copy()->subHours($index) : null,
                'issued_at' => $this->now->copy()->subDays($index),
            ]);

            $seeded++;
        }

        return $seeded;
    }

    private function seedSchedule(
        int $organizationId,
        int $organizationIndex,
        int $projectId,
        int $projectIndex,
        array $users,
        int $taskCount
    ): int {
        if (!Schema::hasTable('project_schedules') || !Schema::hasTable('schedule_tasks')) {
            return 0;
        }

        $ownerId = $users['owner'] ?? reset($users);
        $foremanId = $users['foreman'] ?? $ownerId;
        $scheduleId = $this->upsert('project_schedules', [
            'project_id' => $projectId,
            'organization_id' => $organizationId,
            'name' => sprintf('Load Test Schedule LT%02d-%02d', $organizationIndex, $projectIndex),
        ], [
            'created_by_user_id' => is_int($ownerId) ? $ownerId : null,
            'description' => 'Synthetic schedule for production load testing.',
            'planned_start_date' => $this->now->copy()->subMonths(3)->toDateString(),
            'planned_end_date' => $this->now->copy()->addMonths(9)->toDateString(),
            'baseline_start_date' => $this->now->copy()->subMonths(3)->subDays(5)->toDateString(),
            'baseline_end_date' => $this->now->copy()->addMonths(9)->subDays(5)->toDateString(),
            'baseline_saved_at' => $this->now->copy()->subMonths(2),
            'baseline_saved_by_user_id' => is_int($ownerId) ? $ownerId : null,
            'actual_start_date' => $this->now->copy()->subMonths(3)->toDateString(),
            'status' => 'active',
            'is_template' => false,
            'calculation_settings' => $this->json(['calendar' => 'six_days', 'load_test' => true]),
            'display_settings' => $this->json(['view' => 'gantt']),
            'critical_path_calculated' => true,
            'critical_path_updated_at' => $this->now,
            'critical_path_duration_days' => 180 + $taskCount,
            'total_estimated_cost' => 30000000 + ($projectIndex * 5000000),
            'total_actual_cost' => 10000000 + ($projectIndex * 2500000),
            'overall_progress_percent' => min(95, 18 + ($projectIndex * 13)),
        ]);

        if ($scheduleId === 0) {
            return 0;
        }

        $statuses = ['not_started', 'in_progress', 'completed', 'waiting', 'on_hold'];

        for ($index = 1; $index <= $taskCount; $index++) {
            $start = $this->now->copy()->subDays(90)->addDays($index * 5);
            $duration = 4 + ($index % 9);
            $status = $statuses[$index % count($statuses)];
            $progress = match ($status) {
                'completed' => 100,
                'in_progress' => 30 + ($index % 60),
                'waiting', 'on_hold' => 5 + ($index % 20),
                default => 0,
            };

            $this->upsert('schedule_tasks', [
                'schedule_id' => $scheduleId,
                'wbs_code' => sprintf('%d.%02d', $projectIndex, $index),
            ], [
                'organization_id' => $organizationId,
                'assigned_user_id' => is_int($foremanId) ? $foremanId : null,
                'created_by_user_id' => is_int($ownerId) ? $ownerId : null,
                'name' => sprintf('Load-test task %02d', $index),
                'description' => 'Synthetic schedule task for production load testing.',
                'task_type' => $index % 12 === 0 ? 'milestone' : 'task',
                'planned_start_date' => $start->toDateString(),
                'planned_end_date' => $start->copy()->addDays($duration)->toDateString(),
                'planned_duration_days' => $duration,
                'planned_work_hours' => $duration * 8,
                'baseline_start_date' => $start->copy()->subDays(3)->toDateString(),
                'baseline_end_date' => $start->copy()->addDays($duration - 3)->toDateString(),
                'baseline_duration_days' => $duration,
                'actual_start_date' => $progress > 0 ? $start->copy()->addDay()->toDateString() : null,
                'actual_duration_days' => $progress === 100 ? $duration : null,
                'actual_work_hours' => round(($duration * 8) * ($progress / 100), 2),
                'total_float_days' => $index % 4 === 0 ? 0 : 5,
                'free_float_days' => $index % 4 === 0 ? 0 : 2,
                'is_critical' => $index % 4 === 0,
                'is_milestone_critical' => $index % 12 === 0,
                'progress_percent' => $progress,
                'status' => $status,
                'priority' => $index % 7 === 0 ? 'high' : 'normal',
                'estimated_cost' => 250000 + ($index * 45000),
                'actual_cost' => round((250000 + ($index * 45000)) * ($progress / 100), 2),
                'earned_value' => round((250000 + ($index * 45000)) * ($progress / 100), 2),
                'required_resources' => $this->json([
                    'people' => 4 + ($index % 8),
                    'equipment' => [
                        EquipmentTypeEnum::MOBILE_CRANE->value,
                        EquipmentTypeEnum::CONCRETE_MIXER->value,
                    ],
                ]),
                'constraint_type' => 'none',
                'custom_fields' => $this->json(['load_test' => true]),
                'notes' => 'Synthetic task',
                'tags' => $this->json(['load-test']),
                'level' => 1,
                'sort_order' => $index,
            ]);
        }

        return $taskCount;
    }

    private function seedSiteRequests(
        int $organizationId,
        int $organizationIndex,
        int $projectId,
        int $projectIndex,
        array $users,
        array $materials,
        int $count
    ): int {
        if (!Schema::hasTable('site_requests') || $materials === []) {
            return 0;
        }

        $ownerId = $users['owner'] ?? reset($users);
        $foremanId = $users['foreman'] ?? $ownerId;
        $assigneeId = $users['pm'] ?? $ownerId;
        $types = ['material_request', 'personnel_request', 'equipment_request', 'info_request', 'issue_report'];
        $statuses = ['draft', 'pending', 'in_review', 'approved', 'in_progress', 'fulfilled', 'completed'];
        $priorities = ['low', 'medium', 'high', 'urgent'];

        for ($index = 1; $index <= $count; $index++) {
            $type = $types[$index % count($types)];
            $status = $statuses[$index % count($statuses)];
            $title = sprintf('LT%02d-P%02d request %03d', $organizationIndex, $projectIndex, $index);
            $materialId = $materials[($index - 1) % count($materials)];

            $this->upsert('site_requests', [
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'title' => $title,
            ], [
                'user_id' => is_int($foremanId) ? $foremanId : $ownerId,
                'assigned_to' => is_int($assigneeId) ? $assigneeId : null,
                'description' => 'Synthetic site request for production load testing.',
                'status' => $status,
                'priority' => $priorities[$index % count($priorities)],
                'request_type' => $type,
                'required_date' => $this->now->copy()->addDays(($index % 20) - 5)->toDateString(),
                'notes' => 'Synthetic site request',
                'material_id' => $type === 'material_request' ? $materialId : null,
                'estimate_item_id' => null,
                'material_name' => $type === 'material_request' ? null : 'General construction resource',
                'material_quantity' => $type === 'material_request' ? 2 + ($index % 18) : null,
                'material_unit' => $type === 'material_request' ? 'pcs' : null,
                'delivery_address' => "Moscow, Project {$organizationIndex}/{$projectIndex}",
                'delivery_time_from' => '09:00:00',
                'delivery_time_to' => '17:00:00',
                'contact_person_name' => 'Load Test Foreman',
                'contact_person_phone' => '+7 916 900-00-00',
                'personnel_type' => $type === 'personnel_request' ? 'mason' : null,
                'personnel_count' => $type === 'personnel_request' ? 3 + ($index % 8) : null,
                'personnel_requirements' => $type === 'personnel_request' ? 'Experienced construction team' : null,
                'hourly_rate' => $type === 'personnel_request' ? 950 : null,
                'work_hours_per_day' => $type === 'personnel_request' ? 8 : null,
                'work_start_date' => $type === 'personnel_request' ? $this->now->copy()->addDays($index % 12)->toDateString() : null,
                'work_end_date' => $type === 'personnel_request' ? $this->now->copy()->addDays(($index % 12) + 4)->toDateString() : null,
                'work_location' => $type === 'personnel_request' ? 'Section A' : null,
                'additional_conditions' => $type === 'personnel_request' ? 'Standard site access' : null,
                'equipment_type' => $type === 'equipment_request' ? EquipmentTypeEnum::MOBILE_CRANE->value : null,
                'equipment_count' => $type === 'equipment_request' ? 1 + ($index % 2) : null,
                'equipment_specs' => $type === 'equipment_request' ? 'Standard construction equipment' : null,
                'rental_start_date' => $type === 'equipment_request' ? $this->now->copy()->addDays($index % 10)->toDateString() : null,
                'rental_end_date' => $type === 'equipment_request' ? $this->now->copy()->addDays(($index % 10) + 3)->toDateString() : null,
                'rental_hours_per_day' => $type === 'equipment_request' ? 8 : null,
                'with_operator' => $type === 'equipment_request',
                'equipment_location' => $type === 'equipment_request' ? 'Construction site' : null,
                'metadata' => $this->json([
                    'load_test' => true,
                    'created_by_seeder' => self::class,
                ]),
                'template_id' => null,
                'created_by_user_id' => is_int($ownerId) ? $ownerId : null,
            ]);
        }

        return $count;
    }

    private function assignRole(int $userId, string $roleSlug, int $contextId, ?int $assignedBy): void
    {
        UserRoleAssignment::query()->updateOrCreate(
            [
                'user_id' => $userId,
                'role_slug' => $roleSlug,
                'context_id' => $contextId,
            ],
            [
                'role_type' => UserRoleAssignment::TYPE_SYSTEM,
                'assigned_by' => $assignedBy,
                'expires_at' => null,
                'is_active' => true,
            ]
        );
    }

    private function splitCount(int $count, int $parts): array
    {
        if ($parts <= 0) {
            return [];
        }

        $base = intdiv($count, $parts);
        $remainder = $count % $parts;
        $result = [];

        for ($index = 0; $index < $parts; $index++) {
            $result[$index] = $base + ($index < $remainder ? 1 : 0);
        }

        return $result;
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

    private function envString(string $key, string $default = ''): string
    {
        $value = getenv($key);

        return $value === false ? $default : (string) $value;
    }

    private function envInt(string $key, int $default, int $min, int $max): int
    {
        $value = getenv($key);

        if ($value === false || !is_numeric($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }
}
