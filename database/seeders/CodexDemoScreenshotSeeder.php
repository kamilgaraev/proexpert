<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class CodexDemoScreenshotSeeder extends Seeder
{
    private Carbon $now;

    public function run(): void
    {
        if ($this->envString('CODEX_DEMO_SEEDER_CONFIRM') !== 'yes') {
            $this->command?->error('Codex demo seeder skipped. Set CODEX_DEMO_SEEDER_CONFIRM=yes to run it intentionally.');

            return;
        }

        $this->now = now();
        $email = $this->envString('CODEX_DEMO_USER_EMAIL', 'codex-demo@prohelper.pro');
        $organizationId = $this->envInt('CODEX_DEMO_ORGANIZATION_ID', 48);

        $result = DB::transaction(function () use ($email, $organizationId): array {
            $user = User::query()->where('email', $email)->first();
            $organization = Organization::query()->find($organizationId);

            if (!$user || !$organization) {
                throw new RuntimeException('Codex demo user or organization was not found.');
            }

            $this->prepareAccount($user, $organization);
            $this->activateModules($organization);

            $units = $this->seedMeasurementUnits($organization);
            $materials = $this->seedMaterials($organization, $units);
            $project = $this->seedProject($organization, $user);
            $scheduleId = $this->seedSchedule($organization, $user, $project);

            $this->seedWarehouse($organization, $user, $project, $materials);
            $this->seedPayments($organization, $user, $project);
            $this->seedSiteRequests($organization, $user, $project, $materials, $units);

            return [
                'ok' => true,
                'user_id' => $user->id,
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'schedule_id' => $scheduleId,
            ];
        });

        $this->command?->info(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function prepareAccount(User $user, Organization $organization): void
    {
        $this->updateModel($organization, [
            'name' => 'ProHelper Demo Construction',
            'legal_name' => 'ООО "ПроХелпер Демо"',
            'phone' => '+7 495 120-45-77',
            'email' => 'office@prohelper-demo.ru',
            'address' => 'Москва, Ленинградский проспект, 37',
            'city' => 'Москва',
            'country' => 'Россия',
            'description' => 'Демонстрационная строительная организация для скриншотов ProHelper.',
            'is_active' => true,
            'is_verified' => true,
            'is_onboarding_demo' => true,
            'verified_at' => $this->now,
            'verification_status' => 'verified',
            'capabilities' => ['project_owner', 'general_contractor', 'warehouse_operator'],
            'primary_business_type' => 'general_contractor',
            'specializations' => ['residential_construction', 'warehouse_management', 'project_control'],
            'profile_completeness' => 100,
            'onboarding_completed' => true,
            'onboarding_completed_at' => $this->now,
        ]);

        $this->updateModel($user, [
            'current_organization_id' => $organization->id,
            'email_verified_at' => $user->email_verified_at ?: $this->now,
            'is_active' => true,
        ]);

        $this->upsert('organization_user', [
            'user_id' => $user->id,
            'organization_id' => $organization->id,
        ], [
            'is_owner' => true,
            'is_active' => true,
            'settings' => $this->json(['demo' => true]),
        ]);

        $context = AuthorizationContext::getOrganizationContext($organization->id);

        UserRoleAssignment::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'role_slug' => 'organization_owner',
                'context_id' => $context->id,
            ],
            [
                'role_type' => 'system',
                'assigned_by' => $user->id,
                'expires_at' => null,
                'is_active' => true,
            ]
        );
    }

    private function activateModules(Organization $organization): void
    {
        $modules = [
            ['slug' => 'project-management', 'name' => 'Управление проектами', 'category' => 'core'],
            ['slug' => 'schedule-management', 'name' => 'Календарные графики', 'category' => 'planning'],
            ['slug' => 'basic-warehouse', 'name' => 'Складской учет', 'category' => 'warehouse'],
            ['slug' => 'catalog-management', 'name' => 'Каталог материалов', 'category' => 'catalog'],
            ['slug' => 'payments', 'name' => 'Платежи', 'category' => 'finance'],
            ['slug' => 'reports', 'name' => 'Отчеты', 'category' => 'analytics'],
            ['slug' => 'site-requests', 'name' => 'Заявки с объекта', 'category' => 'field'],
            ['slug' => 'procurement', 'name' => 'Закупки', 'category' => 'procurement'],
            ['slug' => 'dashboard-widgets', 'name' => 'Виджеты дашборда', 'category' => 'analytics'],
            ['slug' => 'data-filters', 'name' => 'Фильтры данных', 'category' => 'productivity'],
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
                'features' => $this->json(['demo_data' => true]),
                'permissions' => $this->json(['admin.*']),
                'dependencies' => $this->json([]),
                'conflicts' => $this->json([]),
                'limits' => $this->json([]),
                'display_order' => $index + 1,
                'is_active' => true,
                'is_system_module' => false,
                'can_deactivate' => true,
            ]);

            if (!Schema::hasColumn('organization_module_activations', 'module_id')) {
                continue;
            }

            $this->upsert('organization_module_activations', [
                'organization_id' => $organization->id,
                'module_id' => $moduleId,
            ], [
                'status' => 'active',
                'activated_at' => $this->now,
                'expires_at' => null,
                'trial_ends_at' => null,
                'last_used_at' => $this->now,
                'paid_amount' => 0,
                'payment_details' => $this->json(['source' => 'codex_demo']),
                'module_settings' => $this->json(['enabled_for_screenshots' => true]),
                'usage_stats' => $this->json([]),
                'is_bundled_with_plan' => true,
                'is_auto_renew_enabled' => false,
            ]);
        }
    }

    private function seedMeasurementUnits(Organization $organization): array
    {
        $units = [
            'm3' => ['name' => 'Кубический метр', 'short_name' => 'м³'],
            'ton' => ['name' => 'Тонна', 'short_name' => 'т'],
            'piece' => ['name' => 'Штука', 'short_name' => 'шт'],
            'pack' => ['name' => 'Упаковка', 'short_name' => 'упак'],
        ];

        $ids = [];

        foreach ($units as $key => $unit) {
            $ids[$key] = $this->upsert('measurement_units', [
                'organization_id' => $organization->id,
                'short_name' => $unit['short_name'],
            ], [
                'name' => $unit['name'],
                'type' => 'material',
                'description' => 'Демо-единица для скриншотов',
                'is_default' => $key === 'piece',
                'is_system' => false,
            ]);
        }

        return $ids;
    }

    private function seedMaterials(Organization $organization, array $units): array
    {
        $materials = [
            'MAT-DEMO-001' => ['name' => 'Бетон B25 П4 F200', 'unit' => 'm3', 'category' => 'Бетон и растворы', 'price' => 6200],
            'MAT-DEMO-002' => ['name' => 'Арматура А500С 12 мм', 'unit' => 'ton', 'category' => 'Металлопрокат', 'price' => 73500],
            'MAT-DEMO-003' => ['name' => 'Кирпич керамический М150', 'unit' => 'piece', 'category' => 'Кладочные материалы', 'price' => 32],
            'MAT-DEMO-004' => ['name' => 'Минеральная вата 100 мм', 'unit' => 'pack', 'category' => 'Теплоизоляция', 'price' => 1480],
        ];

        $ids = [];

        foreach ($materials as $code => $material) {
            $ids[$code] = $this->upsert('materials', [
                'organization_id' => $organization->id,
                'code' => $code,
            ], [
                'name' => $material['name'],
                'measurement_unit_id' => $units[$material['unit']] ?? null,
                'description' => 'Демо-материал для презентационных экранов',
                'category' => $material['category'],
                'default_price' => $material['price'],
                'additional_properties' => $this->json(['demo' => true, 'source' => 'codex']),
                'is_active' => true,
                'is_onboarding_demo' => true,
                'use_in_accounting_reports' => true,
            ]);
        }

        return $ids;
    }

    private function seedProject(Organization $organization, User $user): Project
    {
        $project = Project::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'external_code' => 'CODEX-DEMO-PROJECT',
            ],
            [
                'name' => 'ЖК "Северный квартал"',
                'address' => 'Москва, Дмитровское шоссе, 71',
                'latitude' => 55.85642,
                'longitude' => 37.55691,
                'geocoded_at' => $this->now,
                'geocoding_status' => 'success',
                'description' => 'Демонстрационный объект для скриншотов админки и маркетингового сайта.',
                'customer' => 'ООО "Север Девелопмент"',
                'designer' => 'Архитектурное бюро "Линия"',
                'budget_amount' => 186500000,
                'site_area_m2' => 24800,
                'start_date' => $this->now->copy()->subMonths(3)->toDateString(),
                'end_date' => $this->now->copy()->addMonths(14)->toDateString(),
                'status' => 'active',
                'additional_info' => [
                    'demo' => true,
                    'readiness_percent' => 42,
                    'blocks' => ['Корпус 1', 'Паркинг', 'Благоустройство'],
                ],
                'is_archived' => false,
                'is_onboarding_demo' => true,
                'is_head' => true,
                'customer_organization' => 'ООО "Север Девелопмент"',
                'customer_representative' => 'Анна Волкова',
                'contract_number' => 'ГП-2026-04',
                'contract_date' => $this->now->copy()->subMonths(4)->toDateString(),
            ]
        );

        $this->upsert('project_organization', [
            'project_id' => $project->id,
            'organization_id' => $organization->id,
        ], [
            'role' => 'owner',
            'role_new' => 'owner',
            'permissions' => $this->json(['*']),
            'is_active' => true,
            'added_by_user_id' => $user->id,
            'invited_at' => $this->now,
            'accepted_at' => $this->now,
            'metadata' => $this->json(['demo' => true]),
        ]);

        $this->upsert('project_user', [
            'project_id' => $project->id,
            'user_id' => $user->id,
        ], [
            'role' => 'project_manager',
        ]);

        return $project;
    }

    private function seedSchedule(Organization $organization, User $user, Project $project): int
    {
        if (!Schema::hasTable('project_schedules') || !Schema::hasTable('schedule_tasks')) {
            return 0;
        }

        $scheduleId = $this->upsert('project_schedules', [
            'project_id' => $project->id,
            'organization_id' => $organization->id,
            'name' => 'График строительства корпуса 1',
        ], [
            'created_by_user_id' => $user->id,
            'description' => 'Демо-график с критическим путем, прогрессом и отклонениями по срокам.',
            'planned_start_date' => $this->now->copy()->subMonths(3)->startOfMonth()->toDateString(),
            'planned_end_date' => $this->now->copy()->addMonths(10)->endOfMonth()->toDateString(),
            'baseline_start_date' => $this->now->copy()->subMonths(3)->startOfMonth()->toDateString(),
            'baseline_end_date' => $this->now->copy()->addMonths(11)->endOfMonth()->toDateString(),
            'baseline_saved_at' => $this->now->copy()->subMonths(2),
            'baseline_saved_by_user_id' => $user->id,
            'actual_start_date' => $this->now->copy()->subMonths(3)->startOfMonth()->toDateString(),
            'status' => 'active',
            'is_template' => false,
            'calculation_settings' => $this->json(['work_calendar' => 'six_days']),
            'display_settings' => $this->json(['view' => 'gantt', 'show_critical_path' => true]),
            'critical_path_calculated' => true,
            'critical_path_updated_at' => $this->now,
            'critical_path_duration_days' => 312,
            'total_estimated_cost' => 124800000,
            'total_actual_cost' => 45600000,
            'overall_progress_percent' => 42,
        ]);

        $tasks = [
            ['wbs' => '1.1', 'name' => 'Подготовка площадки', 'status' => 'completed', 'progress' => 100, 'start' => -90, 'duration' => 18, 'cost' => 4200000, 'critical' => true],
            ['wbs' => '1.2', 'name' => 'Земляные работы', 'status' => 'completed', 'progress' => 100, 'start' => -72, 'duration' => 28, 'cost' => 9800000, 'critical' => true],
            ['wbs' => '1.3', 'name' => 'Монолитный фундамент', 'status' => 'in_progress', 'progress' => 76, 'start' => -44, 'duration' => 46, 'cost' => 21400000, 'critical' => true],
            ['wbs' => '1.4', 'name' => 'Вертикальные конструкции', 'status' => 'in_progress', 'progress' => 38, 'start' => -8, 'duration' => 82, 'cost' => 38600000, 'critical' => true],
            ['wbs' => '1.5', 'name' => 'Инженерные сети', 'status' => 'waiting', 'progress' => 12, 'start' => 42, 'duration' => 96, 'cost' => 27500000, 'critical' => false],
            ['wbs' => '1.6', 'name' => 'Фасад и кровля', 'status' => 'not_started', 'progress' => 0, 'start' => 124, 'duration' => 74, 'cost' => 23300000, 'critical' => false],
        ];

        foreach ($tasks as $index => $task) {
            $start = $this->now->copy()->addDays($task['start']);
            $end = $start->copy()->addDays($task['duration']);

            $this->upsert('schedule_tasks', [
                'schedule_id' => $scheduleId,
                'wbs_code' => $task['wbs'],
            ], [
                'organization_id' => $organization->id,
                'assigned_user_id' => $user->id,
                'created_by_user_id' => $user->id,
                'name' => $task['name'],
                'description' => 'Демо-этап строительства для визуализации графика.',
                'task_type' => 'task',
                'planned_start_date' => $start->toDateString(),
                'planned_end_date' => $end->toDateString(),
                'planned_duration_days' => $task['duration'],
                'planned_work_hours' => $task['duration'] * 8,
                'baseline_start_date' => $start->copy()->subDays(2)->toDateString(),
                'baseline_end_date' => $end->copy()->subDays(2)->toDateString(),
                'baseline_duration_days' => $task['duration'],
                'actual_start_date' => $task['progress'] > 0 ? $start->copy()->addDays(1)->toDateString() : null,
                'actual_duration_days' => $task['progress'] >= 100 ? $task['duration'] : null,
                'actual_work_hours' => round($task['duration'] * 8 * ($task['progress'] / 100), 2),
                'total_float_days' => $task['critical'] ? 0 : 14,
                'free_float_days' => $task['critical'] ? 0 : 6,
                'is_critical' => $task['critical'],
                'is_milestone_critical' => false,
                'progress_percent' => $task['progress'],
                'status' => $task['status'],
                'priority' => $task['critical'] ? 'high' : 'normal',
                'estimated_cost' => $task['cost'],
                'actual_cost' => round($task['cost'] * ($task['progress'] / 100), 2),
                'earned_value' => round($task['cost'] * ($task['progress'] / 100), 2),
                'required_resources' => $this->json(['people' => 18 + $index * 3, 'equipment' => ['кран', 'миксер']]),
                'constraint_type' => 'none',
                'custom_fields' => $this->json(['demo' => true]),
                'tags' => $this->json(['demo', 'screenshots']),
                'level' => 1,
                'sort_order' => $index + 1,
            ]);
        }

        return $scheduleId;
    }

    private function seedWarehouse(Organization $organization, User $user, Project $project, array $materials): void
    {
        if (!Schema::hasTable('organization_warehouses') || !Schema::hasTable('warehouse_balances')) {
            return;
        }

        $warehouseId = $this->upsert('organization_warehouses', [
            'organization_id' => $organization->id,
            'code' => 'MAIN-DEMO',
        ], [
            'name' => 'Центральный склад демо',
            'address' => 'Москва, Дмитровское шоссе, 71, складская зона 2',
            'description' => 'Основной склад демонстрационного проекта.',
            'warehouse_type' => 'central',
            'is_main' => true,
            'is_active' => true,
            'settings' => $this->json(['demo' => true]),
            'contact_person' => 'Илья Смирнов',
            'contact_phone' => '+7 495 120-45-78',
            'working_hours' => 'Пн-Сб 08:00-20:00',
            'storage_conditions' => $this->json(['heated' => true, 'security' => true]),
        ]);

        $balanceRows = [
            'MAT-DEMO-001' => ['available' => 18, 'reserved' => 6, 'price' => 6200, 'min' => 12, 'max' => 60, 'loc' => 'A-1-03', 'batch' => 'DEMO-BETON-1'],
            'MAT-DEMO-002' => ['available' => 11.5, 'reserved' => 3.2, 'price' => 73500, 'min' => 8, 'max' => 30, 'loc' => 'B-2-11', 'batch' => 'DEMO-ARM-2'],
            'MAT-DEMO-003' => ['available' => 12400, 'reserved' => 3200, 'price' => 32, 'min' => 8000, 'max' => 36000, 'loc' => 'C-4-05', 'batch' => 'DEMO-BRICK-3'],
            'MAT-DEMO-004' => ['available' => 86, 'reserved' => 24, 'price' => 1480, 'min' => 50, 'max' => 200, 'loc' => 'D-1-08', 'batch' => 'DEMO-WOOL-4'],
        ];

        foreach ($balanceRows as $code => $row) {
            if (!isset($materials[$code])) {
                continue;
            }

            $values = [
                'organization_id' => $organization->id,
                'available_quantity' => $row['available'],
                'reserved_quantity' => $row['reserved'],
                'min_stock_level' => $row['min'],
                'max_stock_level' => $row['max'],
                'location_code' => $row['loc'],
                'batch_number' => $row['batch'],
                'expiry_date' => $this->now->copy()->addMonths(9)->toDateString(),
                'last_movement_at' => $this->now->copy()->subDays(1),
            ];

            $priceColumn = Schema::hasColumn('warehouse_balances', 'unit_price')
                ? 'unit_price'
                : (Schema::hasColumn('warehouse_balances', 'average_price') ? 'average_price' : null);

            if ($priceColumn !== null) {
                $values[$priceColumn] = $row['price'];
            }

            $this->upsert('warehouse_balances', [
                'warehouse_id' => $warehouseId,
                'material_id' => $materials[$code],
            ], $values);
        }

        if (!Schema::hasTable('warehouse_movements')) {
            return;
        }

        $movementIndex = 1;

        foreach ($balanceRows as $code => $row) {
            if (!isset($materials[$code])) {
                continue;
            }

            $this->upsert('warehouse_movements', [
                'document_number' => sprintf('DEMO-WH-%03d', $movementIndex),
            ], [
                'organization_id' => $organization->id,
                'warehouse_id' => $warehouseId,
                'material_id' => $materials[$code],
                'movement_type' => 'receipt',
                'quantity' => $row['available'] + $row['reserved'],
                'price' => $row['price'],
                'project_id' => $project->id,
                'user_id' => $user->id,
                'reason' => 'Демо-поступление материалов на объект',
                'metadata' => $this->json(['demo' => true]),
                'movement_date' => $this->now->copy()->subDays(3),
            ]);

            $movementIndex++;
        }
    }

    private function seedPayments(Organization $organization, User $user, Project $project): void
    {
        if (!Schema::hasTable('payment_documents')) {
            return;
        }

        $documents = [
            ['number' => 'DEMO-PAY-001', 'type' => 'payment_request', 'status' => 'approved', 'amount' => 18400000, 'paid' => 0, 'title' => 'Аванс за монолитные работы', 'days' => 5],
            ['number' => 'DEMO-PAY-002', 'type' => 'payment_order', 'status' => 'scheduled', 'amount' => 6200000, 'paid' => 0, 'title' => 'Оплата поставки бетона B25', 'days' => 12],
            ['number' => 'DEMO-PAY-003', 'type' => 'expense', 'status' => 'paid', 'amount' => 1480000, 'paid' => 1480000, 'title' => 'Аренда башенного крана', 'days' => -8],
        ];

        foreach ($documents as $document) {
            $vatAmount = round($document['amount'] * 20 / 120, 2);

            $this->upsert('payment_documents', [
                'document_number' => $document['number'],
            ], [
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'document_type' => $document['type'],
                'document_date' => $this->now->copy()->subDays(4)->toDateString(),
                'direction' => $document['type'] === 'payment_request' ? 'incoming' : 'outgoing',
                'invoice_type' => $document['type'] === 'expense' ? 'equipment' : 'progress',
                'payer_organization_id' => $organization->id,
                'payee_organization_id' => $organization->id,
                'amount' => $document['amount'],
                'currency' => 'RUB',
                'vat_rate' => 20,
                'vat_amount' => $vatAmount,
                'amount_without_vat' => $document['amount'] - $vatAmount,
                'paid_amount' => $document['paid'],
                'remaining_amount' => $document['amount'] - $document['paid'],
                'status' => $document['status'],
                'workflow_stage' => $document['status'] === 'paid' ? 'completed' : 'payment',
                'source_type' => Project::class,
                'source_id' => $project->id,
                'due_date' => $this->now->copy()->addDays($document['days'])->toDateString(),
                'payment_terms_days' => 10,
                'description' => $document['title'],
                'payment_purpose' => $document['title'] . ' по проекту ' . $project->name,
                'attached_documents' => $this->json([]),
                'metadata' => $this->json(['demo' => true, 'source' => 'codex']),
                'created_by_user_id' => $user->id,
                'approved_by_user_id' => $user->id,
                'submitted_at' => $this->now->copy()->subDays(3),
                'approved_at' => $this->now->copy()->subDays(2),
                'scheduled_at' => $document['status'] === 'scheduled' ? $this->now->copy()->subDay() : null,
                'paid_at' => $document['status'] === 'paid' ? $this->now->copy()->subDays(8) : null,
                'issued_at' => $this->now->copy()->subDays(4),
            ]);
        }
    }

    private function seedSiteRequests(Organization $organization, User $user, Project $project, array $materials, array $units): void
    {
        if (!Schema::hasTable('site_requests')) {
            return;
        }

        $requests = [
            [
                'title' => 'Доставка бетона на захватку 2',
                'type' => 'material_request',
                'priority' => 'high',
                'status' => 'in_progress',
                'material' => 'MAT-DEMO-001',
                'quantity' => 18,
                'unit' => 'м³',
            ],
            [
                'title' => 'Бригада арматурщиков на фундамент',
                'type' => 'personnel_request',
                'priority' => 'medium',
                'status' => 'approved',
                'material' => null,
                'quantity' => null,
                'unit' => null,
            ],
        ];

        foreach ($requests as $index => $request) {
            $this->upsert('site_requests', [
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'title' => $request['title'],
            ], [
                'user_id' => $user->id,
                'assigned_to' => $user->id,
                'description' => 'Демо-заявка с объекта для презентационного интерфейса.',
                'status' => $request['status'],
                'priority' => $request['priority'],
                'request_type' => $request['type'],
                'required_date' => $this->now->copy()->addDays($index + 2)->toDateString(),
                'notes' => 'Создано для скриншотов',
                'material_id' => $request['material'] ? ($materials[$request['material']] ?? null) : null,
                'material_name' => $request['material'] ? null : 'Арматурщики 4 разряда',
                'material_quantity' => $request['quantity'],
                'material_unit' => $request['unit'],
                'delivery_address' => $project->address,
                'delivery_time_from' => '09:00:00',
                'delivery_time_to' => '13:00:00',
                'contact_person_name' => 'Илья Смирнов',
                'contact_person_phone' => '+7 495 120-45-78',
                'personnel_type' => $request['type'] === 'personnel_request' ? 'mason' : null,
                'personnel_count' => $request['type'] === 'personnel_request' ? 8 : null,
                'personnel_requirements' => $request['type'] === 'personnel_request' ? 'Опыт монолитных работ от 3 лет' : null,
                'work_start_date' => $this->now->copy()->addDays(3)->toDateString(),
                'work_end_date' => $this->now->copy()->addDays(12)->toDateString(),
                'work_location' => 'Корпус 1, фундаментная плита',
                'metadata' => $this->json(['demo' => true, 'unit_ids' => $units]),
            ]);
        }
    }

    private function updateModel($model, array $values): void
    {
        $table = $model->getTable();
        $model->forceFill($this->filterColumns($table, $values));
        $model->save();
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

        return (int) DB::table($table)->where($keys)->value('id');
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
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function envString(string $key, string $default = ''): string
    {
        $value = getenv($key);

        return $value === false ? $default : (string) $value;
    }

    private function envInt(string $key, int $default): int
    {
        $value = getenv($key);

        return $value === false ? $default : (int) $value;
    }
}
