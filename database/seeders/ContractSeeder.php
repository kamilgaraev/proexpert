<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\Organization;
use App\Models\ContractPayment;
use App\Models\ContractPerformanceAct;
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

        $organization = Organization::first();
        if (!$organization) {
            $this->command->error('Не найдена организация! Запустите сначала BasicDataSeeder');
            return;
        }

        $this->organizationId = $organization->id;

        $faker = \Faker\Factory::create('ru_RU');

        $this->ensureProjectsAndContractors($faker);

        $contracts = $this->createContracts($faker);

        foreach ($contracts as $contract) {
            $this->createContractPayments($contract, $faker);
            $this->createContractPerformanceActs($contract, $faker);
        }

        $this->command->info('Создание контрактов завершено!');
        $this->command->info('Создано контрактов: ' . $contracts->count());
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
                    'total_budget' => $faker->randomFloat(2, 5000000, 50000000),
                    'status' => $faker->randomElement(['planning', 'active', 'completed']),
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
                    'specialization' => $data['specialization'],
                ]
            );
        }
    }

    private function createContracts($faker): \Illuminate\Support\Collection
    {
        $contracts = collect();
        $projectIds = Project::where('organization_id', $this->organizationId)->pluck('id')->toArray();
        $contractorIds = Contractor::where('organization_id', $this->organizationId)->pluck('id')->toArray();

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

            $contract = Contract::create([
                'organization_id' => $this->organizationId,
                'project_id' => $faker->randomElement($projectIds),
                'contractor_id' => $faker->randomElement($contractorIds),
                'parent_contract_id' => $faker->optional(0.2)->randomElement(
                    Contract::where('organization_id', $this->organizationId)->pluck('id')->toArray() ?: [null]
                ),
                'number' => $this->generateContractNumber($faker, $template['type']),
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
            ContractPayment::create([
                'contract_id' => $contract->id,
                'payment_date' => $faker->dateTimeBetween($contract->date, $contract->start_date ?: 'now'),
                'amount' => $contract->actual_advance_amount,
                'payment_type' => ContractPaymentTypeEnum::ADVANCE,
                'reference_document_number' => 'ПП-' . $faker->numberBetween(1000, 9999),
                'description' => 'Авансовый платеж по договору ' . $contract->number,
            ]);
        }

        if ($contract->status === ContractStatusEnum::ACTIVE || $contract->status === ContractStatusEnum::COMPLETED) {
            $paymentsCount = $faker->numberBetween(1, 4);
            $remainingAmount = $contract->total_amount - $contract->actual_advance_amount;
            
            for ($i = 0; $i < $paymentsCount; $i++) {
                $paymentAmount = $faker->randomFloat(2, 50000, $remainingAmount / 2);
                $remainingAmount -= $paymentAmount;
                
                if ($remainingAmount < 0) break;

                ContractPayment::create([
                    'contract_id' => $contract->id,
                    'payment_date' => $faker->dateTimeBetween($contract->start_date ?: $contract->date, 'now'),
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
        if ($contract->status === ContractStatusEnum::ACTIVE || $contract->status === ContractStatusEnum::COMPLETED) {
            $actsCount = $faker->numberBetween(1, 3);
            
            for ($i = 0; $i < $actsCount; $i++) {
                $actAmount = $faker->randomFloat(2, 100000, $contract->total_amount / 3);
                $actDate = $faker->dateTimeBetween($contract->start_date ?: $contract->date, 'now');
                $isApproved = $faker->boolean(80);
                
                ContractPerformanceAct::create([
                    'contract_id' => $contract->id,
                    'act_document_number' => 'АКТ-' . $faker->numberBetween(100, 999) . '/' . Carbon::parse($actDate)->format('Y'),
                    'act_date' => $actDate,
                    'amount' => $actAmount,
                    'description' => 'Акт выполненных работ № ' . ($i + 1) . ' по договору ' . $contract->number,
                    'is_approved' => $isApproved,
                    'approval_date' => $isApproved ? $faker->dateTimeBetween($actDate, 'now') : null,
                ]);
            }
        }
    }
}
