<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeasurementUnit extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'name',
        'short_name',
        'type',
        'description',
        'is_default',
        'is_system',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_system' => 'boolean',
    ];

    /**
     * Получить организацию, которой принадлежит единица измерения.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Получить материалы, использующие эту единицу измерения.
     */
    public function materials(): HasMany
    {
        return $this->hasMany(Material::class);
    }

    /**
     * Получить виды работ, использующие эту единицу измерения.
     */
    public function workTypes(): HasMany
    {
        return $this->hasMany(WorkType::class);
    }

    /**
     * Получить список стандартных единиц измерения.
     *
     * @return array
     */
    public static function getDefaultUnits(): array
    {
        return [
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
    }
}
