<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\Organization;
use App\Models\ContractPayment;
use App\Models\ContractPerformanceAct;
use App\Models\CompletedWork;
use App\Models\WorkType;
use App\Models\User;
use App\Models\Role;
use App\Enums\Contract\ContractStatusEnum;
use App\Enums\Contract\ContractTypeEnum;
use App\Enums\Contract\ContractWorkTypeCategoryEnum;
use App\Enums\Contract\ContractPaymentTypeEnum;
use Carbon\Carbon;

class ContractSeeder extends Seeder
{
    private int $organizationId;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Начинаем создание контрактов...');

        // Проверяем структуру таблиц перед выполнением
        if (!$this->checkTablesStructure()) {
            $this->command->error('Структура таблиц не соответствует ожидаемой!');
            return;
        }

        // Используем организацию с ID = 1, для которой у пользователя есть доступ
        $organization = Organization::find(1);
        if (!$organization) {
            $this->command->error('Не найдена организация с ID = 1! Проверьте наличие организации');
            return;
        }

        $this->organizationId = $organization->id;
        $this->command->info('Создаем контракты для организации: ' . $organization->name . ' (ID: ' . $organization->id . ')');

        $faker = \Faker\Factory::create('ru_RU');

        $this->ensureProjectsAndContractors($faker);

        $contracts = $this->createContracts($faker);

        // Сначала создаем все платежи, акты и работы для основных контрактов
        foreach ($contracts as $contract) {
            $this->createContractPayments($contract, $faker);
            $this->createContractPerformanceActs($contract, $faker);
            $this->createCompletedWorks($contract, $faker);
        }

        // Потом создаем дочерние контракты для некоторых основных
        $this->command->info('Начинаем создание дочерних контрактов...');
        $childContractsCreated = 0;
        foreach ($contracts as $contract) {
            $before = Contract::count();
            $this->createChildContracts($contract, $faker);
            $after = Contract::count();
            if ($after > $before) {
                $childContractsCreated += ($after - $before);
            }
        }
        $this->command->info("Создано дочерних контрактов: {$childContractsCreated}");

        $this->command->info('Создание контрактов завершено!');
        $this->command->info('Создано контрактов: ' . $contracts->count());
    }

    private function checkTablesStructure(): bool
    {
        // Проверяем обязательные поля в таблицах
        $requiredColumns = [
            'projects' => ['id', 'organization_id', 'name', 'address', 'description', 'start_date', 'end_date', 'status'],
            'contractors' => ['id', 'organization_id', 'name', 'contact_person', 'phone', 'email', 'legal_address', 'inn', 'kpp'],
            'contracts' => ['id', 'organization_id', 'project_id', 'contractor_id', 'number', 'date', 'type', 'total_amount', 'gp_percentage', 'planned_advance_amount', 'actual_advance_amount'],
        ];

        foreach ($requiredColumns as $table => $columns) {
            if (!Schema::hasTable($table)) {
                $this->command->error("Таблица {$table} не существует!");
                return false;
            }

            foreach ($columns as $column) {
                if (!Schema::hasColumn($table, $column)) {
                    $this->command->error("Колонка {$column} не существует в таблице {$table}!");
                    return false;
                }
            }
        }

        $this->command->info('Структура таблиц проверена успешно');
        return true;
    }

    private function ensureProjectsAndContractors($faker): void
    {
        $projectsCount = Project::where('organization_id', $this->organizationId)->count();
        if ($projectsCount < 3) {
            $this->createProjects($faker);
        }

        $contractorsCount = Contractor::where('organization_id', $this->organizationId)->count();
        if ($contractorsCount < 5) {
            $this->createContractors($faker);
        }
    }

    private function createProjects($faker): void
    {
        $projectNames = [
            'Жилой комплекс "Северный"',
            'Торговый центр "Меркурий"',
            'Офисное здание "Бизнес Парк"',
            'Складской комплекс "Логистик"',
            'Многоэтажный паркинг "Автоград"'
        ];

        foreach ($projectNames as $name) {
            Project::firstOrCreate(
                ['name' => $name, 'organization_id' => $this->organizationId],
                [
                    'organization_id' => $this->organizationId,
                    'name' => $name,
                    'address' => $faker->address,
                    'description' => 'Строительство объекта: ' . $name,
                    'start_date' => $faker->dateTimeBetween('-2 years', '-6 months'),
                    'end_date' => $faker->dateTimeBetween('+6 months', '+2 years'),
                    'status' => $faker->randomElement(['active', 'completed', 'paused']),
                ]
            );
        }
    }

    private function createContractors($faker): void
    {
        $contractorData = [
            [
                'name' => 'ООО "СтройТехнологии"',
                'type' => 'ООО',
                'specialization' => 'Строительно-монтажные работы'
            ],
            [
                'name' => 'ИП Петров Александр Сергеевич',
                'type' => 'ИП',
                'specialization' => 'Отделочные работы'
            ],
            [
                'name' => 'ООО "МегаСтрой Плюс"',
                'type' => 'ООО',
                'specialization' => 'Генеральный подряд'
            ],
            [
                'name' => 'ООО "ЭлектроМонтаж"',
                'type' => 'ООО',
                'specialization' => 'Электромонтажные работы'
            ],
            [
                'name' => 'ООО "СантехСервис"',
                'type' => 'ООО',
                'specialization' => 'Сантехнические работы'
            ],
            [
                'name' => 'ИП Сидоров Михаил Владимирович',
                'type' => 'ИП',
                'specialization' => 'Кровельные работы'
            ]
        ];

        foreach ($contractorData as $data) {
            Contractor::firstOrCreate(
                ['name' => $data['name'], 'organization_id' => $this->organizationId],
                [
                    'organization_id' => $this->organizationId,
                    'name' => $data['name'],
                    'contact_person' => $faker->name,
                    'phone' => $faker->phoneNumber,
                    'email' => $faker->companyEmail,
                    'legal_address' => $faker->address,
                    'inn' => $faker->numerify($data['type'] === 'ИП' ? '############' : '##########'),
                    'kpp' => $data['type'] === 'ИП' ? null : $faker->numerify('#########'),
                    'notes' => $data['specialization'], // Используем notes вместо specialization
                ]
            );
        }
    }

    private function createContracts($faker): \Illuminate\Support\Collection
    {
        $contracts = collect();
        $projectIds = Project::where('organization_id', $this->organizationId)->pluck('id')->toArray();
        $contractorIds = Contractor::where('organization_id', $this->organizationId)->pluck('id')->toArray();

        $this->command->info('Найдено проектов: ' . count($projectIds) . ' для организации ' . $this->organizationId);
        $this->command->info('Найдено подрядчиков: ' . count($contractorIds) . ' для организации ' . $this->organizationId);

        if (empty($projectIds)) {
            $this->command->error('Нет проектов для создания контрактов!');
            return $contracts;
        }

        if (empty($contractorIds)) {
            $this->command->error('Нет подрядчиков для создания контрактов!');
            return $contracts;
        }

        $contractTemplates = [
            [
                'type' => ContractTypeEnum::CONTRACT,
                'subject' => 'Выполнение строительно-монтажных работ',
                'work_type_category' => ContractWorkTypeCategoryEnum::SMR,
                'amount_range' => [1000000, 5000000],
                'gp_percentage_range' => [5, 15],
                'advance_percentage_range' => [10, 30],
            ],
            [
                'type' => ContractTypeEnum::AGREEMENT,
                'subject' => 'Поставка и монтаж инженерных систем',
                'work_type_category' => ContractWorkTypeCategoryEnum::SUPPLY,
                'amount_range' => [500000, 2000000],
                'gp_percentage_range' => [8, 20],
                'advance_percentage_range' => [15, 40],
            ],
            [
                'type' => ContractTypeEnum::SPECIFICATION,
                'subject' => 'Услуги по проектированию',
                'work_type_category' => ContractWorkTypeCategoryEnum::SERVICES,
                'amount_range' => [300000, 1500000],
                'gp_percentage_range' => [10, 25],
                'advance_percentage_range' => [20, 50],
            ],
        ];

        for ($i = 1; $i <= 15; $i++) {
            $template = $faker->randomElement($contractTemplates);
            $totalAmount = $faker->randomFloat(2, $template['amount_range'][0], $template['amount_range'][1]);
            $gpPercentage = $faker->randomFloat(2, $template['gp_percentage_range'][0], $template['gp_percentage_range'][1]);
            $advancePercentage = $faker->randomFloat(2, $template['advance_percentage_range'][0], $template['advance_percentage_range'][1]);
            $plannedAdvanceAmount = round($totalAmount * $advancePercentage / 100, 2);
            $actualAdvanceAmount = $faker->randomFloat(2, 0, $plannedAdvanceAmount * 1.2);

            $startDate = $faker->dateTimeBetween('-1 year', '-1 month');
            $endDate = $faker->dateTimeBetween('+1 month', '+1 year');
            $contractDate = $faker->dateTimeBetween('-1 year', '-2 weeks');

            $contractNumber = $this->generateContractNumber($faker, $template['type']);
            
            $contract = Contract::create([
                'organization_id' => $this->organizationId,
                'project_id' => $faker->randomElement($projectIds),
                'contractor_id' => $faker->randomElement($contractorIds),
                'parent_contract_id' => $faker->optional(0.2)->randomElement(
                    Contract::where('organization_id', $this->organizationId)->pluck('id')->toArray() ?: [null]
                ),
                'number' => $contractNumber,
                'date' => $contractDate,
                'type' => $template['type'],
                'subject' => $template['subject'] . ' по проекту',
                'work_type_category' => $template['work_type_category'],
                'payment_terms' => $this->generatePaymentTerms($faker),
                'total_amount' => $totalAmount,
                'gp_percentage' => $gpPercentage,
                'planned_advance_amount' => $plannedAdvanceAmount,
                'actual_advance_amount' => $actualAdvanceAmount,
                'status' => $faker->randomElement([
                    ContractStatusEnum::DRAFT,
                    ContractStatusEnum::ACTIVE,
                    ContractStatusEnum::COMPLETED,
                    ContractStatusEnum::TERMINATED
                ]),
                'start_date' => $startDate,
                'end_date' => $endDate,
                'notes' => $faker->optional(0.7)->paragraph(),
            ]);

            $contracts->push($contract);
            
            if ($i % 5 === 0) {
                $this->command->info("Создано контрактов: {$i}/15");
            }
            
            // Детальная информация о созданном контракте
            $this->command->info("Создан контракт #{$contract->id}: {$contractNumber} для организации {$this->organizationId}");
        }

        return $contracts;
    }

    private function generateContractNumber($faker, ContractTypeEnum $type): string
    {
        $prefix = match($type) {
            ContractTypeEnum::CONTRACT => 'ДОГ',
            ContractTypeEnum::AGREEMENT => 'СОГ', 
            ContractTypeEnum::SPECIFICATION => 'СПЦ',
        };

        return $prefix . '-' . $faker->numberBetween(1000, 9999) . '/2025';
    }

    private function generatePaymentTerms($faker): string
    {
        $templates = [
            'Оплата производится в течение 10 банковских дней с момента подписания акта выполненных работ',
            'Предоплата 30%, остальная сумма - в течение 15 дней после завершения работ',
            'Поэтапная оплата согласно календарному плану работ',
            'Оплата по факту выполнения работ в течение 7 рабочих дней',
            'Аванс 50%, окончательный расчет в течение 30 дней после сдачи объекта'
        ];

        return $faker->randomElement($templates);
    }

    private function createContractPayments(Contract $contract, $faker): void
    {
        if ($contract->actual_advance_amount > 0) {
            // Авансовый платеж - всегда после даты договора
            $paymentDate = $faker->dateTimeBetween($contract->date, '+30 days');

            ContractPayment::create([
                'contract_id' => $contract->id,
                'payment_date' => $paymentDate,
                'amount' => $contract->actual_advance_amount,
                'payment_type' => ContractPaymentTypeEnum::ADVANCE,
                'reference_document_number' => 'ПП-' . $faker->numberBetween(1000, 9999),
                'description' => 'Авансовый платеж по договору ' . $contract->number,
            ]);
        }

        // Промежуточные платежи для всех контрактов кроме DRAFT
        if ($contract->status !== ContractStatusEnum::DRAFT) {
            $paymentsCount = $faker->numberBetween(1, 4);
            $remainingAmount = $contract->total_amount - $contract->actual_advance_amount;
            
            for ($i = 0; $i < $paymentsCount; $i++) {
                $paymentAmount = $faker->randomFloat(2, 50000, $remainingAmount / 2);
                $remainingAmount -= $paymentAmount;
                
                if ($remainingAmount < 0) break;

                // Промежуточные платежи - от даты договора до сегодня
                $paymentDate = $faker->dateTimeBetween($contract->date, 'now');

                ContractPayment::create([
                    'contract_id' => $contract->id,
                    'payment_date' => $paymentDate,
                    'amount' => $paymentAmount,
                    'payment_type' => ContractPaymentTypeEnum::FACT_PAYMENT,
                    'reference_document_number' => 'ПП-' . $faker->numberBetween(1000, 9999),
                    'description' => 'Промежуточный платеж ' . ($i + 1) . ' по договору ' . $contract->number,
                ]);
            }
        }
    }

    private function createContractPerformanceActs(Contract $contract, $faker): void
    {
        // Акты для всех контрактов кроме DRAFT
        if ($contract->status !== ContractStatusEnum::DRAFT) {
            $actsCount = $faker->numberBetween(1, 3);
            
            for ($i = 0; $i < $actsCount; $i++) {
                $actAmount = $faker->randomFloat(2, 100000, $contract->total_amount / 3);
                
                // Акты - от даты договора до сегодня
                $actDate = $faker->dateTimeBetween($contract->date, 'now');
                $isApproved = $faker->boolean(80);
                
                // Дата утверждения - после даты акта
                $approvalDate = null;
                if ($isApproved) {
                    $approvalDate = $faker->dateTimeBetween($actDate, 'now');
                }
                
                ContractPerformanceAct::create([
                    'contract_id' => $contract->id,
                    'act_document_number' => 'АКТ-' . $faker->numberBetween(100, 999) . '/' . Carbon::parse($actDate)->format('Y'),
                    'act_date' => $actDate,
                    'amount' => $actAmount,
                    'description' => 'Акт выполненных работ № ' . ($i + 1) . ' по договору ' . $contract->number,
                    'is_approved' => $isApproved,
                    'approval_date' => $approvalDate,
                ]);
            }
        }
    }

    private function createCompletedWorks(Contract $contract, $faker): void
    {
        // Получаем виды работ и пользователей для организации
        $workTypeIds = WorkType::where('organization_id', $this->organizationId)->pluck('id')->toArray();
        $userIds = User::where('current_organization_id', $this->organizationId)->pluck('id')->toArray();

        if (empty($workTypeIds)) {
            $this->command->warn('Нет видов работ для создания выполненных работ');
            return;
        }

        if (empty($userIds)) {
            $this->command->warn('Нет пользователей для создания выполненных работ');
            return;
        }

        // Создаем 3-7 выполненных работ для контракта
        $worksCount = $faker->numberBetween(3, 7);
        
        for ($i = 0; $i < $worksCount; $i++) {
            $quantity = $faker->randomFloat(3, 1, 100);
            $price = $faker->randomFloat(2, 500, 5000);
            $totalAmount = $quantity * $price;
            
            $completionDate = $faker->dateTimeBetween($contract->start_date ?: $contract->date, 'now');

            CompletedWork::create([
                'organization_id' => $this->organizationId,
                'project_id' => $contract->project_id,
                'contract_id' => $contract->id,
                'work_type_id' => $faker->randomElement($workTypeIds),
                'user_id' => $faker->randomElement($userIds),
                'quantity' => $quantity,
                'price' => $price,
                'total_amount' => $totalAmount,
                'completion_date' => $completionDate,
                'status' => $faker->randomElement(['confirmed', 'pending', 'rejected']),
                'notes' => $faker->optional(0.7)->sentence(),
                'additional_info' => [
                    'weather' => $faker->randomElement(['солнечно', 'дождь', 'облачно']),
                    'team_size' => $faker->numberBetween(2, 6),
                    'quality_rating' => $faker->numberBetween(3, 5)
                ],
                'created_at' => $completionDate,
                'updated_at' => $completionDate,
            ]);
        }

        $this->command->info("Создано {$worksCount} выполненных работ для контракта {$contract->number}");
    }

    private function createChildContracts(Contract $contract, $faker): void
    {
        // Создаем дочерние контракты только для 20% контрактов
        $shouldCreate = $faker->boolean(20);
        if (!$shouldCreate) {
            $this->command->info("Контракт {$contract->number}: пропускаем создание дочерних (20% шанс)");
            return;
        }

        // Только для активных и завершенных контрактов
        if (!in_array($contract->status, [ContractStatusEnum::ACTIVE, ContractStatusEnum::COMPLETED])) {
            $this->command->info("Контракт {$contract->number}: пропускаем (статус {$contract->status->value})");
            return;
        }

        $this->command->info("Контракт {$contract->number}: создаем дочерние контракты");

        $contractorIds = Contractor::where('organization_id', $this->organizationId)->pluck('id')->toArray();

        // Создаем 1-2 дочерних контракта
        $childrenCount = $faker->numberBetween(1, 2);

        for ($i = 0; $i < $childrenCount; $i++) {
            $totalAmount = $faker->randomFloat(2, 100000, $contract->total_amount * 0.3);
            $gpPercentage = $faker->randomFloat(2, 3, 10);
            $plannedAdvanceAmount = $totalAmount * $faker->randomFloat(2, 0.1, 0.3);
            $actualAdvanceAmount = $plannedAdvanceAmount * $faker->randomFloat(2, 0, 1.1);
            
            $startDate = $faker->dateTimeBetween($contract->start_date ?: $contract->date, '+45 days');
            $endDate = $faker->dateTimeBetween($startDate, '+6 months');

            $childContract = Contract::create([
                'organization_id' => $this->organizationId,
                'project_id' => $contract->project_id,
                'contractor_id' => $faker->randomElement($contractorIds),
                'parent_contract_id' => $contract->id,
                'number' => 'ПОД-' . $faker->numberBetween(1000, 9999) . '/2025',
                'date' => $faker->dateTimeBetween($contract->date, '+30 days'),
                'type' => ContractTypeEnum::SPECIFICATION,
                'subject' => 'Дополнительные работы по контракту ' . $contract->number,
                'work_type_category' => ContractWorkTypeCategoryEnum::SERVICES,
                'payment_terms' => 'Оплата в течение 7 дней после выполнения работ',
                'total_amount' => $totalAmount,
                'gp_percentage' => $gpPercentage,
                'planned_advance_amount' => $plannedAdvanceAmount,
                'actual_advance_amount' => $actualAdvanceAmount,
                'status' => $faker->randomElement([
                    ContractStatusEnum::ACTIVE,
                    ContractStatusEnum::COMPLETED
                ]),
                'start_date' => $startDate,
                'end_date' => $endDate,
                'notes' => 'Дочерний контракт для ' . $contract->number,
            ]);

            $this->command->info("Создан дочерний контракт {$childContract->number} для {$contract->number}");
        }
    }
}
