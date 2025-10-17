<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\Contract;
use App\Models\CompletedWork;
use App\Enums\OrganizationCapability;
use App\Enums\ProjectOrganizationRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ProjectRBACTestSeeder extends Seeder
{
    protected array $organizations = [];
    protected array $projects = [];
    protected array $users = [];

    public function run(): void
    {
        $this->command->info('🌱 Начало создания тестовых данных для Project-Based RBAC...');

        DB::beginTransaction();

        try {
            // 1. Создание организаций с разными capabilities
            $this->createOrganizations();

            // 2. Создание пользователей
            $this->createUsers();

            // 3. Создание проектов
            $this->createProjects();

            // 4. Добавление участников в проекты
            $this->addProjectParticipants();

            // 5. Создание тестовых контрактов
            $this->createContracts();

            // 6. Создание тестовых работ
            $this->createCompletedWorks();

            DB::commit();

            $this->command->info('✅ Тестовые данные успешно созданы!');
            $this->displaySummary();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->command->error('❌ Ошибка при создании тестовых данных: ' . $e->getMessage());
            throw $e;
        }
    }

    protected function createOrganizations(): void
    {
        $this->command->info('📦 Создание организаций...');

        // Генподрядчик
        $this->organizations['general_contractor'] = Organization::create([
            'name' => 'ООО "СтройГенподряд"',
            'inn' => '7701234567',
            'kpp' => '770101001',
            'ogrn' => '1027700234567',
            'legal_address' => 'Москва, ул. Строителей, д. 1',
            'actual_address' => 'Москва, ул. Строителей, д. 1',
            'phone' => '+7 (495) 123-45-67',
            'email' => 'info@gencontractor.ru',
            'capabilities' => [
                OrganizationCapability::GENERAL_CONTRACTING->value,
                OrganizationCapability::SUBCONTRACTING->value,
            ],
            'primary_business_type' => 'general_contractor',
            'specializations' => ['building_construction', 'road_construction'],
            'certifications' => ['ISO 9001', 'SRO'],
            'profile_completeness' => 100,
            'onboarding_completed' => true,
            'onboarding_completed_at' => now(),
        ]);

        // Субподрядчик 1 - специализация электрика
        $this->organizations['subcontractor_electric'] = Organization::create([
            'name' => 'ООО "Электромонтаж"',
            'inn' => '7702345678',
            'kpp' => '770201001',
            'ogrn' => '1027700345678',
            'legal_address' => 'Москва, пр-т Электриков, д. 15',
            'actual_address' => 'Москва, пр-т Электриков, д. 15',
            'phone' => '+7 (495) 234-56-78',
            'email' => 'info@electro.ru',
            'capabilities' => [
                OrganizationCapability::SUBCONTRACTING->value,
            ],
            'primary_business_type' => 'subcontractor',
            'specializations' => ['electrical_works'],
            'certifications' => ['SRO Electrical'],
            'profile_completeness' => 90,
            'onboarding_completed' => true,
            'onboarding_completed_at' => now()->subDays(10),
        ]);

        // Субподрядчик 2 - специализация отделка
        $this->organizations['subcontractor_finishing'] = Organization::create([
            'name' => 'ИП "Отделка Премиум"',
            'inn' => '773456789012',
            'legal_address' => 'Москва, ул. Мастеров, д. 7',
            'actual_address' => 'Москва, ул. Мастеров, д. 7',
            'phone' => '+7 (495) 345-67-89',
            'email' => 'info@otdelka-premium.ru',
            'capabilities' => [
                OrganizationCapability::SUBCONTRACTING->value,
            ],
            'primary_business_type' => 'subcontractor',
            'specializations' => ['finishing_works', 'painting'],
            'certifications' => ['Master Certificate'],
            'profile_completeness' => 85,
            'onboarding_completed' => true,
            'onboarding_completed_at' => now()->subDays(20),
        ]);

        // Заказчик
        $this->organizations['customer'] = Organization::create([
            'name' => 'ООО "Инвестстрой"',
            'inn' => '7704567890',
            'kpp' => '770401001',
            'ogrn' => '1027700567890',
            'legal_address' => 'Москва, Тверская ул., д. 10',
            'actual_address' => 'Москва, Тверская ул., д. 10',
            'phone' => '+7 (495) 456-78-90',
            'email' => 'info@investstroy.ru',
            'capabilities' => [],
            'primary_business_type' => 'customer',
            'specializations' => ['real_estate_development'],
            'certifications' => [],
            'profile_completeness' => 75,
            'onboarding_completed' => true,
            'onboarding_completed_at' => now()->subDays(30),
        ]);

        // Проектировщик
        $this->organizations['designer'] = Organization::create([
            'name' => 'АО "АрхитектПроект"',
            'inn' => '7705678901',
            'kpp' => '770501001',
            'ogrn' => '1027700678901',
            'legal_address' => 'Москва, ул. Архитекторов, д. 22',
            'actual_address' => 'Москва, ул. Архитекторов, д. 22',
            'phone' => '+7 (495) 567-89-01',
            'email' => 'info@arhproekt.ru',
            'capabilities' => [
                OrganizationCapability::DESIGN->value,
            ],
            'primary_business_type' => 'designer',
            'specializations' => ['architectural_design', 'structural_design'],
            'certifications' => ['SRO Design', 'ISO 9001'],
            'profile_completeness' => 100,
            'onboarding_completed' => true,
            'onboarding_completed_at' => now()->subDays(40),
        ]);

        // Стройконтроль
        $this->organizations['supervision'] = Organization::create([
            'name' => 'ООО "СтройНадзор"',
            'inn' => '7706789012',
            'kpp' => '770601001',
            'ogrn' => '1027700789012',
            'legal_address' => 'Москва, ул. Контролеров, д. 5',
            'actual_address' => 'Москва, ул. Контролеров, д. 5',
            'phone' => '+7 (495) 678-90-12',
            'email' => 'info@stroynadzor.ru',
            'capabilities' => [
                OrganizationCapability::CONSTRUCTION_SUPERVISION->value,
            ],
            'primary_business_type' => 'supervisor',
            'specializations' => ['construction_supervision', 'quality_control'],
            'certifications' => ['SRO Supervision'],
            'profile_completeness' => 95,
            'onboarding_completed' => true,
            'onboarding_completed_at' => now()->subDays(50),
        ]);

        $this->command->line('  ✅ Создано организаций: ' . count($this->organizations));
    }

    protected function createUsers(): void
    {
        $this->command->info('👥 Создание пользователей...');

        // Директор генподрядчика
        $this->users['director_gc'] = User::create([
            'name' => 'Иванов Иван Иванович',
            'email' => 'director@gencontractor.ru',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $this->users['director_gc']->organizations()->attach($this->organizations['general_contractor']->id);

        // Прораб генподрядчика
        $this->users['foreman_gc'] = User::create([
            'name' => 'Петров Петр Петрович',
            'email' => 'foreman@gencontractor.ru',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $this->users['foreman_gc']->organizations()->attach($this->organizations['general_contractor']->id);

        // Директор субподрядчика электрики
        $this->users['director_electric'] = User::create([
            'name' => 'Сидоров Сергей Сергеевич',
            'email' => 'director@electro.ru',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $this->users['director_electric']->organizations()->attach($this->organizations['subcontractor_electric']->id);

        // Директор субподрядчика отделка
        $this->users['director_finishing'] = User::create([
            'name' => 'Васильев Василий Васильевич',
            'email' => 'director@otdelka-premium.ru',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $this->users['director_finishing']->organizations()->attach($this->organizations['subcontractor_finishing']->id);

        // Директор заказчика
        $this->users['customer_director'] = User::create([
            'name' => 'Николаев Николай Николаевич',
            'email' => 'director@investstroy.ru',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $this->users['customer_director']->organizations()->attach($this->organizations['customer']->id);

        $this->command->line('  ✅ Создано пользователей: ' . count($this->users));
    }

    protected function createProjects(): void
    {
        $this->command->info('🏗️  Создание проектов...');

        // Проект 1: Жилой комплекс (owner - генподрядчик)
        $this->projects['residential_complex'] = Project::create([
            'name' => 'ЖК "Солнечный"',
            'description' => 'Строительство жилого комплекса на 500 квартир',
            'organization_id' => $this->organizations['general_contractor']->id,
            'address' => 'Москва, район Южное Бутово',
            'start_date' => now()->subMonths(6),
            'end_date' => now()->addMonths(18),
            'status' => 'in_progress',
        ]);

        // Проект 2: Торговый центр (owner - заказчик)
        $this->projects['shopping_mall'] = Project::create([
            'name' => 'ТРЦ "Мега Плаза"',
            'description' => 'Строительство торгово-развлекательного центра',
            'organization_id' => $this->organizations['customer']->id,
            'address' => 'Москва, МКАД 25км',
            'start_date' => now()->subMonths(3),
            'end_date' => now()->addMonths(24),
            'status' => 'in_progress',
        ]);

        // Проект 3: Офисное здание (owner - генподрядчик)
        $this->projects['office_building'] = Project::create([
            'name' => 'Бизнес-центр "Престиж"',
            'description' => 'Строительство офисного здания класса А',
            'organization_id' => $this->organizations['general_contractor']->id,
            'address' => 'Москва, ул. Тверская, д. 50',
            'start_date' => now()->subMonths(2),
            'end_date' => now()->addMonths(12),
            'status' => 'in_progress',
        ]);

        $this->command->line('  ✅ Создано проектов: ' . count($this->projects));
    }

    protected function addProjectParticipants(): void
    {
        $this->command->info('🤝 Добавление участников в проекты...');

        // Проект 1: ЖК "Солнечный"
        // Owner уже добавлен автоматически через boot() в модели Project
        
        // Добавляем заказчика
        $this->projects['residential_complex']->organizations()->attach(
            $this->organizations['customer']->id,
            [
                'role' => ProjectOrganizationRole::CUSTOMER->value,
                'role_new' => ProjectOrganizationRole::CUSTOMER->value,
                'is_active' => true,
                'invited_at' => now()->subMonths(6),
                'accepted_at' => now()->subMonths(6),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // Добавляем субподрядчика по электрике
        $this->projects['residential_complex']->organizations()->attach(
            $this->organizations['subcontractor_electric']->id,
            [
                'role' => ProjectOrganizationRole::SUBCONTRACTOR->value,
                'role_new' => ProjectOrganizationRole::SUBCONTRACTOR->value,
                'is_active' => true,
                'invited_at' => now()->subMonths(5),
                'accepted_at' => now()->subMonths(5),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // Добавляем субподрядчика по отделке
        $this->projects['residential_complex']->organizations()->attach(
            $this->organizations['subcontractor_finishing']->id,
            [
                'role' => ProjectOrganizationRole::SUBCONTRACTOR->value,
                'role_new' => ProjectOrganizationRole::SUBCONTRACTOR->value,
                'is_active' => true,
                'invited_at' => now()->subMonths(4),
                'accepted_at' => now()->subMonths(4),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // Добавляем проектировщика
        $this->projects['residential_complex']->organizations()->attach(
            $this->organizations['designer']->id,
            [
                'role' => ProjectOrganizationRole::DESIGNER->value,
                'role_new' => ProjectOrganizationRole::DESIGNER->value,
                'is_active' => true,
                'invited_at' => now()->subMonths(6),
                'accepted_at' => now()->subMonths(6),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // Добавляем стройконтроль
        $this->projects['residential_complex']->organizations()->attach(
            $this->organizations['supervision']->id,
            [
                'role' => ProjectOrganizationRole::CONSTRUCTION_SUPERVISION->value,
                'role_new' => ProjectOrganizationRole::CONSTRUCTION_SUPERVISION->value,
                'is_active' => true,
                'invited_at' => now()->subMonths(6),
                'accepted_at' => now()->subMonths(6),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // Проект 2: ТРЦ "Мега Плаза"
        // Owner (customer) уже добавлен автоматически
        
        // Добавляем генподрядчика
        $this->projects['shopping_mall']->organizations()->attach(
            $this->organizations['general_contractor']->id,
            [
                'role' => ProjectOrganizationRole::GENERAL_CONTRACTOR->value,
                'role_new' => ProjectOrganizationRole::GENERAL_CONTRACTOR->value,
                'is_active' => true,
                'invited_at' => now()->subMonths(3),
                'accepted_at' => now()->subMonths(3),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // Добавляем субподрядчика по электрике
        $this->projects['shopping_mall']->organizations()->attach(
            $this->organizations['subcontractor_electric']->id,
            [
                'role' => ProjectOrganizationRole::SUBCONTRACTOR->value,
                'role_new' => ProjectOrganizationRole::SUBCONTRACTOR->value,
                'is_active' => true,
                'invited_at' => now()->subMonths(2),
                'accepted_at' => now()->subMonths(2),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // Проект 3: Бизнес-центр "Престиж"
        // Owner (general_contractor) уже добавлен автоматически
        
        // Добавляем субподрядчика по отделке
        $this->projects['office_building']->organizations()->attach(
            $this->organizations['subcontractor_finishing']->id,
            [
                'role' => ProjectOrganizationRole::SUBCONTRACTOR->value,
                'role_new' => ProjectOrganizationRole::SUBCONTRACTOR->value,
                'is_active' => true,
                'invited_at' => now()->subMonths(1),
                'accepted_at' => now()->subMonths(1),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $this->command->line('  ✅ Участники добавлены в проекты');
    }

    protected function createContracts(): void
    {
        $this->command->info('📄 Создание тестовых контрактов...');

        // Контракт 1: Генподряд на ЖК
        Contract::create([
            'number' => 'ГП-001/2024',
            'date' => now()->subMonths(6),
            'type' => 'general',
            'project_id' => $this->projects['residential_complex']->id,
            'customer_id' => $this->organizations['customer']->id,
            'contractor_id' => $this->organizations['general_contractor']->id,
            'organization_id' => $this->organizations['general_contractor']->id,
            'total_amount' => 500000000.00,
            'vat_amount' => 100000000.00,
            'payment_terms' => 'Поэтапная оплата по актам',
            'start_date' => now()->subMonths(6),
            'end_date' => now()->addMonths(18),
            'status' => 'active',
        ]);

        // Контракт 2: Субподряд - электромонтаж
        Contract::create([
            'number' => 'СП-ЭМ-001/2024',
            'date' => now()->subMonths(5),
            'type' => 'subcontract',
            'project_id' => $this->projects['residential_complex']->id,
            'customer_id' => $this->organizations['general_contractor']->id,
            'contractor_id' => $this->organizations['subcontractor_electric']->id,
            'organization_id' => $this->organizations['general_contractor']->id,
            'total_amount' => 50000000.00,
            'vat_amount' => 10000000.00,
            'payment_terms' => 'Оплата по факту выполнения',
            'start_date' => now()->subMonths(5),
            'end_date' => now()->addMonths(12),
            'status' => 'active',
        ]);

        // Контракт 3: Субподряд - отделка
        Contract::create([
            'number' => 'СП-ОТД-001/2024',
            'date' => now()->subMonths(4),
            'type' => 'subcontract',
            'project_id' => $this->projects['residential_complex']->id,
            'customer_id' => $this->organizations['general_contractor']->id,
            'contractor_id' => $this->organizations['subcontractor_finishing']->id,
            'organization_id' => $this->organizations['general_contractor']->id,
            'total_amount' => 80000000.00,
            'vat_amount' => 16000000.00,
            'payment_terms' => 'Оплата по актам выполненных работ',
            'start_date' => now()->subMonths(4),
            'end_date' => now()->addMonths(14),
            'status' => 'active',
        ]);

        $this->command->line('  ✅ Создано контрактов: 3');
    }

    protected function createCompletedWorks(): void
    {
        $this->command->info('🔨 Создание тестовых работ...');

        // Работы генподрядчика
        CompletedWork::create([
            'name' => 'Устройство фундамента',
            'description' => 'Устройство монолитного фундамента под здание',
            'project_id' => $this->projects['residential_complex']->id,
            'organization_id' => $this->organizations['general_contractor']->id,
            'contractor_id' => $this->organizations['general_contractor']->id,
            'quantity' => 1500.00,
            'unit' => 'м³',
            'price' => 15000.00,
            'total_amount' => 22500000.00,
            'work_date' => now()->subMonths(5),
            'status' => 'completed',
        ]);

        // Работы электромонтажа
        CompletedWork::create([
            'name' => 'Прокладка электрических сетей',
            'description' => 'Монтаж электропроводки в жилых помещениях',
            'project_id' => $this->projects['residential_complex']->id,
            'organization_id' => $this->organizations['subcontractor_electric']->id,
            'contractor_id' => $this->organizations['subcontractor_electric']->id,
            'quantity' => 5000.00,
            'unit' => 'м.п.',
            'price' => 500.00,
            'total_amount' => 2500000.00,
            'work_date' => now()->subMonths(3),
            'status' => 'completed',
        ]);

        // Работы отделки
        CompletedWork::create([
            'name' => 'Чистовая отделка квартир',
            'description' => 'Покраска стен, укладка ламината',
            'project_id' => $this->projects['residential_complex']->id,
            'organization_id' => $this->organizations['subcontractor_finishing']->id,
            'contractor_id' => $this->organizations['subcontractor_finishing']->id,
            'quantity' => 10000.00,
            'unit' => 'м²',
            'price' => 3000.00,
            'total_amount' => 30000000.00,
            'work_date' => now()->subMonths(2),
            'status' => 'completed',
        ]);

        $this->command->line('  ✅ Создано работ: 3');
    }

    protected function displaySummary(): void
    {
        $this->command->newLine();
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->info('📊 ИТОГИ СОЗДАНИЯ ТЕСТОВЫХ ДАННЫХ');
        $this->command->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->command->newLine();
        
        $this->command->line('Организации:');
        foreach ($this->organizations as $key => $org) {
            $capabilities = count($org->capabilities ?? []);
            $this->command->line("  • {$org->name} (capabilities: {$capabilities})");
        }
        
        $this->command->newLine();
        $this->command->line('Проекты:');
        foreach ($this->projects as $key => $project) {
            $participantsCount = $project->organizations()->count();
            $this->command->line("  • {$project->name} (участников: {$participantsCount})");
        }
        
        $this->command->newLine();
        $this->command->line('Пользователи для тестирования:');
        foreach ($this->users as $key => $user) {
            $this->command->line("  • {$user->email} / password");
        }
        
        $this->command->newLine();
        $this->command->info('✅ Тестовая среда готова к использованию!');
    }
}
