<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Material;
use App\Models\WorkType;
use App\Models\MeasurementUnit;
use App\Models\User;
use App\Models\Role;
use App\Models\CompletedWork;
use App\Models\CompletedWorkMaterial;
use App\Models\Contract;
use App\Models\Contractor;
use Carbon\Carbon;
use Faker\Factory as Faker;
use Illuminate\Support\Facades\Hash;

class OrganizationCompletedWorkSeeder extends Seeder
{
    private int $organizationId = 1;
    
    public function run(): void
    {
        $faker = Faker::create('ru_RU');
        
        $organization = Organization::find($this->organizationId);
        if (!$organization) {
            $this->command->error("Организация с ID {$this->organizationId} не найдена!");
            return;
        }

        $this->command->info("Создание данных для организации: {$organization->name}");

        $this->createUsers($faker);
        $this->ensureBasicData($faker);
        $this->createCompletedWorks($faker);
        
        $this->command->info('Сидер для организации ID 1 выполнен успешно!');
    }

    private function createUsers($faker): void
    {
        $foremanRole = Role::where('slug', Role::ROLE_FOREMAN)->first();
        $adminRole = Role::where('slug', Role::ROLE_ADMIN)->first();
        
        if (!$foremanRole || !$adminRole) {
            $this->command->error('Роли не найдены! Запустите RolePermissionSeeder.');
            return;
        }

        $users = [
            [
                'name' => 'Иван Петров',
                'email' => 'foreman1@test.com',
                'role' => $foremanRole,
                'position' => 'Прораб участка №1'
            ],
            [
                'name' => 'Сергей Сидоров',
                'email' => 'foreman2@test.com',
                'role' => $foremanRole,
                'position' => 'Прораб участка №2'
            ],
            [
                'name' => 'Алексей Козлов',
                'email' => 'foreman3@test.com',
                'role' => $foremanRole,
                'position' => 'Старший прораб'
            ],
            [
                'name' => 'Администратор Тест',
                'email' => 'admin@test.com',
                'role' => $adminRole,
                'position' => 'Администратор системы'
            ]
        ];

        foreach ($users as $userData) {
            $user = User::firstOrCreate(
                ['email' => $userData['email']],
                [
                    'name' => $userData['name'],
                    'password' => Hash::make('password'),
                    'current_organization_id' => $this->organizationId,
                    'position' => $userData['position'],
                ]
            );
            
            if (!$user->roles()->where('role_id', $userData['role']->id)->exists()) {
                $user->roles()->attach($userData['role']->id, [
                    'organization_id' => $this->organizationId
                ]);
            }
        }

        $this->command->info('Созданы пользователи: ' . count($users));
    }

    private function ensureBasicData($faker): void
    {
        $projects = Project::where('organization_id', $this->organizationId)->count();
        $workTypes = WorkType::where('organization_id', $this->organizationId)->count();
        $materials = Material::where('organization_id', $this->organizationId)->count();

        if ($projects === 0 || $workTypes === 0 || $materials === 0) {
            $this->command->info('Недостаточно базовых данных. Запускаем BasicDataSeeder...');
            $this->call(BasicDataSeeder::class);
        }
    }

    private function createCompletedWorks($faker): void
    {
        $projectIds = Project::where('organization_id', $this->organizationId)->pluck('id')->toArray();
        $workTypeIds = WorkType::where('organization_id', $this->organizationId)->pluck('id')->toArray();
        $materialIds = Material::where('organization_id', $this->organizationId)->pluck('id')->toArray();
        $userIds = User::where('current_organization_id', $this->organizationId)->pluck('id')->toArray();

        if (empty($projectIds) || empty($workTypeIds) || empty($materialIds) || empty($userIds)) {
            $this->command->error('Недостаточно данных для создания выполненных работ!');
            return;
        }

        $contracts = $this->createContracts($faker, $projectIds);
        $contractIds = $contracts->pluck('id')->toArray();

        $statuses = ['draft', 'confirmed', 'cancelled'];
        $startDate = Carbon::now()->subMonths(3);
        $endDate = Carbon::now();

        $this->command->info('Создание выполненных работ с материалами...');

        for ($i = 1; $i <= 50; $i++) {
            $completionDate = $faker->dateTimeBetween($startDate, $endDate);
            $quantity = $faker->randomFloat(3, 1, 100);
            $price = $faker->randomFloat(2, 500, 3000);
            $totalAmount = $quantity * $price;

            $completedWork = CompletedWork::create([
                'organization_id' => $this->organizationId,
                'project_id' => $faker->randomElement($projectIds),
                'contract_id' => $faker->optional(0.8)->randomElement($contractIds),
                'work_type_id' => $faker->randomElement($workTypeIds),
                'user_id' => $faker->randomElement($userIds),
                'quantity' => $quantity,
                'price' => $price,
                'total_amount' => $totalAmount,
                'completion_date' => $completionDate,
                'notes' => $faker->optional(0.6)->sentence(),
                'status' => $faker->randomElement($statuses),
                'additional_info' => [
                    'weather' => $faker->randomElement(['солнечно', 'дождь', 'облачно']),
                    'team_size' => $faker->numberBetween(2, 6),
                    'quality_rating' => $faker->numberBetween(3, 5)
                ],
                'created_at' => $completionDate,
                'updated_at' => $completionDate,
            ]);

            $this->attachMaterialsToCompletedWork($completedWork, $materialIds, $faker);

            if ($i % 10 === 0) {
                $this->command->info("Создано выполненных работ: {$i}/50");
            }
        }

        $this->command->info("Всего создано выполненных работ: 50");
        $this->command->info("Всего материалов в выполненных работах: " . 
                           CompletedWorkMaterial::whereHas('completedWork', function($q) {
                               $q->where('organization_id', $this->organizationId);
                           })->count());
    }

    private function createContracts($faker, array $projectIds)
    {
        $contracts = collect();
        
        // Создаем подрядчиков если их нет
        $contractors = $this->createContractors($faker);
        $contractorIds = $contractors->pluck('id')->toArray();
        
        foreach (array_slice($projectIds, 0, 3) as $projectId) {
            $contractNumber = 'ДОГ-' . $faker->numberBetween(100, 999) . '/2025';
            
            $totalAmount = $faker->randomFloat(2, 500000, 2000000);
            $gpPercentage = $faker->randomFloat(2, 5, 20);
            $plannedAdvanceAmount = $totalAmount * $faker->randomFloat(2, 0.1, 0.4);
            $actualAdvanceAmount = $plannedAdvanceAmount * $faker->randomFloat(2, 0, 1.2);

            $contract = Contract::firstOrCreate(
                ['number' => $contractNumber, 'organization_id' => $this->organizationId],
                [
                    'organization_id' => $this->organizationId,
                    'project_id' => $projectId,
                    'contractor_id' => $faker->randomElement($contractorIds),
                    'number' => $contractNumber,
                    'date' => $faker->dateTimeBetween('-6 months', '-1 month'),
                    'type' => $faker->randomElement(['contract', 'agreement', 'specification']),
                    'status' => $faker->randomElement(['active', 'completed', 'draft']),
                    'subject' => 'Выполнение строительно-монтажных работ',
                    'work_type_category' => $faker->randomElement(['smr', 'supply', 'services']),
                    'payment_terms' => 'Оплата в течение 10 дней после подписания актов',
                    'total_amount' => $totalAmount,
                    'gp_percentage' => $gpPercentage,
                    'planned_advance_amount' => $plannedAdvanceAmount,
                    'actual_advance_amount' => $actualAdvanceAmount,
                    'start_date' => $faker->dateTimeBetween('-3 months', 'now'),
                    'end_date' => $faker->dateTimeBetween('+1 month', '+6 months'),
                    'notes' => 'Договор на выполнение строительных работ',
                ]
            );
            
            $contracts->push($contract);
        }

        return $contracts;
    }

    private function createContractors($faker)
    {
        $contractors = collect();
        
        $contractorNames = [
            'ООО "СтройМонтаж"',
            'ИП Иванов С.П.',
            'ООО "МегаСтрой"'
        ];

        foreach ($contractorNames as $name) {
            $contractor = Contractor::firstOrCreate(
                ['name' => $name, 'organization_id' => $this->organizationId],
                [
                    'organization_id' => $this->organizationId,
                    'name' => $name,
                    'contact_person' => $faker->name,
                    'phone' => $faker->phoneNumber,
                    'email' => $faker->companyEmail,
                    'legal_address' => $faker->address,
                    'inn' => $faker->numerify('############'),
                    'kpp' => $faker->numerify('#########'),
                ]
            );
            
            $contractors->push($contractor);
        }

        return $contractors;
    }

    private function attachMaterialsToCompletedWork(CompletedWork $completedWork, array $materialIds, $faker): void
    {
        $materialsCount = $faker->numberBetween(1, 4);
        $selectedMaterials = $faker->randomElements($materialIds, $materialsCount);

        foreach ($selectedMaterials as $materialId) {
            $quantity = $faker->randomFloat(3, 0.5, 50);
            $unitPrice = $faker->randomFloat(2, 10, 500);
            $totalAmount = $quantity * $unitPrice;

            $completedWork->materials()->attach($materialId, [
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_amount' => $totalAmount,
                'notes' => $faker->optional(0.4)->sentence(),
                'created_at' => $completedWork->completion_date,
                'updated_at' => $completedWork->completion_date,
            ]);
        }
    }
} 