<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkType;
use App\Models\MeasurementUnit;
use App\Enums\ConstructionJournal\JournalStatusEnum;
use App\Enums\ConstructionJournal\JournalEntryStatusEnum;
use Carbon\Carbon;

class ConstructionJournalSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Получить первый доступный проект
        $project = Project::first();
        
        if (!$project) {
            $this->command->warn('Нет доступных проектов для создания журнала работ');
            return;
        }

        $user = User::where('organization_id', $project->organization_id)->first();
        
        if (!$user) {
            $this->command->warn('Нет пользователей в организации проекта');
            return;
        }

        $this->command->info('Создание тестовых журналов работ...');

        // Создать журнал работ
        $journal = ConstructionJournal::create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'name' => 'Журнал работ ' . $project->name,
            'journal_number' => 'ОЖР-' . $project->id . '-' . now()->year . '-1',
            'start_date' => now()->subDays(30),
            'end_date' => null,
            'status' => JournalStatusEnum::ACTIVE,
            'created_by_user_id' => $user->id,
        ]);

        $this->command->info("Создан журнал: {$journal->name}");

        // Получить виды работ и единицы измерения
        $workTypes = WorkType::limit(5)->get();
        $measurementUnits = MeasurementUnit::all();
        $meterUnit = $measurementUnits->firstWhere('short_name', 'м') ?? $measurementUnits->first();
        $m2Unit = $measurementUnits->firstWhere('short_name', 'м²') ?? $measurementUnits->first();
        $m3Unit = $measurementUnits->firstWhere('short_name', 'м³') ?? $measurementUnits->first();

        // Создать записи журнала за последние 15 дней
        for ($i = 14; $i >= 0; $i--) {
            $entryDate = now()->subDays($i);
            $entryNumber = 15 - $i;
            
            // Случайный статус
            $statuses = [
                JournalEntryStatusEnum::DRAFT,
                JournalEntryStatusEnum::SUBMITTED,
                JournalEntryStatusEnum::APPROVED,
            ];
            $status = $i > 5 ? $statuses[array_rand($statuses)] : JournalEntryStatusEnum::APPROVED;

            $entry = ConstructionJournalEntry::create([
                'journal_id' => $journal->id,
                'entry_date' => $entryDate,
                'entry_number' => $entryNumber,
                'work_description' => $this->getRandomWorkDescription($i),
                'status' => $status,
                'created_by_user_id' => $user->id,
                'approved_by_user_id' => $status === JournalEntryStatusEnum::APPROVED ? $user->id : null,
                'approved_at' => $status === JournalEntryStatusEnum::APPROVED ? $entryDate->addHours(8) : null,
                'weather_conditions' => [
                    'temperature' => rand(-5, 25),
                    'precipitation' => rand(0, 1) ? 'Без осадков' : 'Дождь',
                    'wind_speed' => rand(0, 15),
                ],
                'quality_notes' => $i % 3 === 0 ? 'Качество работ соответствует требованиям' : null,
            ]);

            // Добавить объемы работ
            $volumesCount = rand(1, 3);
            for ($v = 0; $v < $volumesCount; $v++) {
                $workType = $workTypes->random();
                $units = [$meterUnit, $m2Unit, $m3Unit];
                $unit = $units[array_rand($units)];
                
                $entry->workVolumes()->create([
                    'work_type_id' => $workType->id,
                    'quantity' => rand(10, 100),
                    'measurement_unit_id' => $unit->id,
                ]);
            }

            // Добавить рабочих
            $workersCount = rand(1, 4);
            $specialties = ['Каменщик', 'Электрик', 'Сантехник', 'Плотник', 'Штукатур', 'Маляр'];
            for ($w = 0; $w < $workersCount; $w++) {
                $entry->workers()->create([
                    'specialty' => $specialties[array_rand($specialties)],
                    'workers_count' => rand(2, 8),
                    'hours_worked' => rand(6, 10),
                ]);
            }

            // Добавить оборудование
            if (rand(0, 1)) {
                $equipmentList = ['Бетономешалка', 'Перфоратор', 'Болгарка', 'Автокран', 'Экскаватор'];
                $entry->equipment()->create([
                    'equipment_name' => $equipmentList[array_rand($equipmentList)],
                    'equipment_type' => 'Строительное',
                    'quantity' => rand(1, 2),
                    'hours_used' => rand(4, 8),
                ]);
            }

            // Добавить материалы
            $materialsCount = rand(2, 4);
            $materials = [
                ['name' => 'Цемент', 'unit' => 'кг'],
                ['name' => 'Песок', 'unit' => 'м³'],
                ['name' => 'Кирпич', 'unit' => 'шт'],
                ['name' => 'Арматура', 'unit' => 'кг'],
                ['name' => 'Доска обрезная', 'unit' => 'м³'],
            ];
            
            for ($m = 0; $m < $materialsCount; $m++) {
                $material = $materials[array_rand($materials)];
                $entry->materials()->create([
                    'material_name' => $material['name'],
                    'quantity' => rand(10, 500),
                    'measurement_unit' => $material['unit'],
                ]);
            }

            $this->command->info("  Создана запись #{$entryNumber} от {$entryDate->format('d.m.Y')} ({$status->label()})");
        }

        $this->command->info('✅ Тестовые данные для журнала работ созданы успешно!');
    }

    private function getRandomWorkDescription(int $day): string
    {
        $descriptions = [
            'Устройство монолитного фундамента. Армирование подошвы фундамента.',
            'Кладка наружных стен из кирпича. Выполнено 3 ряда кладки.',
            'Монтаж перекрытий первого этажа. Установка опалубки.',
            'Устройство гидроизоляции фундамента. Нанесение битумной мастики.',
            'Электромонтажные работы. Прокладка кабельных трасс.',
            'Сантехнические работы. Монтаж внутренних сетей водоснабжения.',
            'Штукатурные работы внутренних стен. Черновая штукатурка.',
            'Устройство стяжки пола. Заливка бетонной смеси.',
            'Монтаж оконных блоков. Установлено 4 окна.',
            'Кровельные работы. Монтаж стропильной системы.',
            'Фасадные работы. Утепление наружных стен минеральной ватой.',
            'Малярные работы. Грунтовка и покраска внутренних стен.',
            'Устройство отмостки вокруг здания.',
            'Благоустройство территории. Подготовка основания под асфальт.',
            'Завершающие работы. Уборка территории, вывоз мусора.',
        ];

        return $descriptions[$day % count($descriptions)];
    }
}

