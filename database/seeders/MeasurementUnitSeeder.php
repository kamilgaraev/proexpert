<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MeasurementUnitSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Список единиц измерения по типам
        $unitsData = [
            // --- ДЛИНА (Length) ---
            ['name' => 'Миллиметр', 'short_name' => 'мм', 'type' => 'material'],
            ['name' => 'Сантиметр', 'short_name' => 'см', 'type' => 'material'],
            ['name' => 'Дециметр', 'short_name' => 'дм', 'type' => 'material'],
            ['name' => 'Метр', 'short_name' => 'м', 'type' => 'material'],
            ['name' => 'Погонный метр', 'short_name' => 'пог. м', 'type' => 'material'],
            ['name' => 'Километр', 'short_name' => 'км', 'type' => 'material'],

            // --- ПЛОЩАДЬ (Area) ---
            ['name' => 'Квадратный миллиметр', 'short_name' => 'мм²', 'type' => 'material'],
            ['name' => 'Квадратный сантиметр', 'short_name' => 'см²', 'type' => 'material'],
            ['name' => 'Квадратный метр', 'short_name' => 'м²', 'type' => 'material'],
            ['name' => 'Гектар', 'short_name' => 'га', 'type' => 'material'],
            ['name' => 'Сотка', 'short_name' => 'сот', 'type' => 'material'],

            // --- ОБЪЕМ (Volume) ---
            ['name' => 'Миллилитр', 'short_name' => 'мл', 'type' => 'material'],
            ['name' => 'Литр', 'short_name' => 'л', 'type' => 'material'],
            ['name' => 'Кубический сантиметр', 'short_name' => 'см³', 'type' => 'material'],
            ['name' => 'Кубический метр', 'short_name' => 'м³', 'type' => 'material'],

            // --- МАССА (Mass) ---
            ['name' => 'Миллиграмм', 'short_name' => 'мг', 'type' => 'material'],
            ['name' => 'Грамм', 'short_name' => 'г', 'type' => 'material'],
            ['name' => 'Килограмм', 'short_name' => 'кг', 'type' => 'material'],
            ['name' => 'Центнер', 'short_name' => 'ц', 'type' => 'material'],
            ['name' => 'Тонна', 'short_name' => 'т', 'type' => 'material'],

            // --- ШТУЧНЫЕ И УПАКОВКА (Count & Packaging) ---
            ['name' => 'Штука', 'short_name' => 'шт', 'type' => 'material'],
            ['name' => 'Упаковка', 'short_name' => 'упак', 'type' => 'material'],
            ['name' => 'Комплект', 'short_name' => 'компл', 'type' => 'material'],
            ['name' => 'Пара', 'short_name' => 'пар', 'type' => 'material'],
            ['name' => 'Рулон', 'short_name' => 'рул', 'type' => 'material'],
            ['name' => 'Лист', 'short_name' => 'лист', 'type' => 'material'],
            ['name' => 'Коробка', 'short_name' => 'кор', 'type' => 'material'],
            ['name' => 'Ящик', 'short_name' => 'ящ', 'type' => 'material'],
            ['name' => 'Мешок', 'short_name' => 'меш', 'type' => 'material'],
            ['name' => 'Бутылка', 'short_name' => 'бут', 'type' => 'material'],
            ['name' => 'Баллон', 'short_name' => 'бал', 'type' => 'material'],
            ['name' => 'Канистра', 'short_name' => 'кан', 'type' => 'material'],
            ['name' => 'Бочка', 'short_name' => 'боч', 'type' => 'material'],

            // --- РАБОТЫ И ВРЕМЯ (Works & Time) ---
            ['name' => 'Секунда', 'short_name' => 'сек', 'type' => 'work'],
            ['name' => 'Минута', 'short_name' => 'мин', 'type' => 'work'],
            ['name' => 'Час', 'short_name' => 'ч', 'type' => 'work'],
            ['name' => 'Человеко-час', 'short_name' => 'чел-ч', 'type' => 'work'],
            ['name' => 'Смена', 'short_name' => 'смн', 'type' => 'work'],
            ['name' => 'День', 'short_name' => 'дн', 'type' => 'work'],
            ['name' => 'Человеко-день', 'short_name' => 'чел-дн', 'type' => 'work'],
            ['name' => 'Месяц', 'short_name' => 'мес', 'type' => 'work'],
            ['name' => 'Машино-час', 'short_name' => 'маш-ч', 'type' => 'work'],
            ['name' => 'Машино-смена', 'short_name' => 'маш-смн', 'type' => 'work'],
            ['name' => 'Рейс', 'short_name' => 'рейс', 'type' => 'work'],
            ['name' => 'Услуга', 'short_name' => 'усл', 'type' => 'work'],
            ['name' => 'Этап', 'short_name' => 'этап', 'type' => 'work'],
            ['name' => 'Проект', 'short_name' => 'проект', 'type' => 'work'],
        ];

        // Получаем все организации
        $organizations = DB::table('organizations')->pluck('id');
        
        if ($organizations->isEmpty()) {
            $this->command->warn('Нет организаций в базе данных. Пропускаем создание единиц измерения.');
            return;
        }

        $this->command->info("Обработка единиц измерения для {$organizations->count()} организаций...");
        
        $totalCreated = 0;
        $totalUpdated = 0;
        $now = Carbon::now();
        
        foreach ($organizations as $organizationId) {
            foreach ($unitsData as $unit) {
                // Ищем по short_name внутри организации
                $exists = DB::table('measurement_units')
                    ->where('organization_id', $organizationId)
                    ->where('short_name', $unit['short_name'])
                    ->first();

                if ($exists) {
                    // Обновляем существующую запись
                    DB::table('measurement_units')
                        ->where('id', $exists->id)
                        ->update([
                            'name' => $unit['name'],
                            'type' => $unit['type'],
                            // 'is_system' => true, // Раскомментируйте, если хотите сделать их системными
                            'updated_at' => $now,
                        ]);
                    $totalUpdated++;
                } else {
                    // Создаем новую запись
                    DB::table('measurement_units')->insert([
                        'organization_id' => $organizationId,
                        'short_name' => $unit['short_name'],
                        'name' => $unit['name'],
                        'type' => $unit['type'],
                        'is_system' => true, // Новые базовые единицы помечаем как системные
                        'is_default' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $totalCreated++;
                }
            }
        }
        
        $this->command->info("✓ Обработано организаций: {$organizations->count()}");
        $this->command->info("✓ Создано новых единиц: {$totalCreated}");
        $this->command->info("✓ Обновлено существующих: {$totalUpdated}");
    }
}
