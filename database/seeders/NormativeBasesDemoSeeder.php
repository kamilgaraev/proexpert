<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\NormativeBaseType;
use App\Models\NormativeCollection;
use App\Models\NormativeSection;
use App\Models\NormativeRate;
use App\Models\NormativeRateResource;
use App\Models\PriceIndex;
use App\Models\RegionalCoefficient;

class NormativeBasesDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->createBaseTypes();
        $this->createCollections();
        $this->createSectionsAndRates();
        $this->createPriceIndices();
        $this->createCoefficients();
    }

    protected function createBaseTypes(): void
    {
        NormativeBaseType::create([
            'code' => 'FER-2001',
            'name' => 'Федеральные единичные расценки',
            'description' => 'ФЕР-2001 - базовая федеральная нормативная база',
            'version' => '2001',
            'effective_date' => '2001-01-01',
            'is_active' => true,
        ]);

        NormativeBaseType::create([
            'code' => 'GESN-2001',
            'name' => 'Государственные элементные сметные нормы',
            'description' => 'ГЭСН-2001 - государственные сметные нормы',
            'version' => '2001',
            'effective_date' => '2001-01-01',
            'is_active' => true,
        ]);
    }

    protected function createCollections(): void
    {
        $fer = NormativeBaseType::where('code', 'FER-2001')->first();

        NormativeCollection::create([
            'base_type_id' => $fer->id,
            'code' => 'FER-01',
            'name' => 'Земляные работы',
            'description' => 'Расценки на земляные работы',
            'version' => '2001',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        NormativeCollection::create([
            'base_type_id' => $fer->id,
            'code' => 'FER-06',
            'name' => 'Бетонные и железобетонные конструкции',
            'description' => 'Расценки на бетонные и железобетонные работы',
            'version' => '2001',
            'sort_order' => 6,
            'is_active' => true,
        ]);

        NormativeCollection::create([
            'base_type_id' => $fer->id,
            'code' => 'FER-08',
            'name' => 'Конструкции из кирпича и блоков',
            'description' => 'Расценки на каменные работы',
            'version' => '2001',
            'sort_order' => 8,
            'is_active' => true,
        ]);
    }

    protected function createSectionsAndRates(): void
    {
        $collection = NormativeCollection::where('code', 'FER-01')->first();

        $section = NormativeSection::create([
            'collection_id' => $collection->id,
            'parent_id' => null,
            'code' => '01-01',
            'name' => 'Разработка грунта',
            'description' => 'Разработка грунта механизированным способом',
            'level' => 0,
            'sort_order' => 1,
            'path' => '/',
        ]);

        $rates = [
            [
                'code' => '01-01-001-01',
                'name' => 'Разработка грунта экскаватором одноковшовым 0,25 м3, группа грунтов 1',
                'measurement_unit' => '1000 м3',
                'base_price' => 12500.00,
                'materials_cost' => 1200.00,
                'machinery_cost' => 8300.00,
                'labor_cost' => 3000.00,
                'labor_hours' => 125.5,
                'machinery_hours' => 83.2,
                'base_price_year' => '2000',
            ],
            [
                'code' => '01-01-001-02',
                'name' => 'Разработка грунта экскаватором одноковшовым 0,25 м3, группа грунтов 2',
                'measurement_unit' => '1000 м3',
                'base_price' => 14800.00,
                'materials_cost' => 1500.00,
                'machinery_cost' => 9800.00,
                'labor_cost' => 3500.00,
                'labor_hours' => 145.8,
                'machinery_hours' => 98.5,
                'base_price_year' => '2000',
            ],
            [
                'code' => '01-01-002-01',
                'name' => 'Разработка грунта бульдозером 79 кВт, группа грунтов 1',
                'measurement_unit' => '1000 м3',
                'base_price' => 8900.00,
                'materials_cost' => 800.00,
                'machinery_cost' => 6100.00,
                'labor_cost' => 2000.00,
                'labor_hours' => 83.5,
                'machinery_hours' => 61.2,
                'base_price_year' => '2000',
            ],
        ];

        foreach ($rates as $rateData) {
            $rate = NormativeRate::create(array_merge($rateData, [
                'collection_id' => $collection->id,
                'section_id' => $section->id,
            ]));

            NormativeRateResource::create([
                'rate_id' => $rate->id,
                'resource_type' => 'labor',
                'code' => 'МР-1',
                'name' => 'Машинисты',
                'measurement_unit' => 'чел-ч',
                'consumption' => $rate->labor_hours / 1000,
                'unit_price' => 250.00,
                'total_cost' => ($rate->labor_hours / 1000) * 250,
            ]);

            NormativeRateResource::create([
                'rate_id' => $rate->id,
                'resource_type' => 'machinery',
                'code' => 'ЭМ-1',
                'name' => 'Экскаватор',
                'measurement_unit' => 'маш-ч',
                'consumption' => $rate->machinery_hours / 1000,
                'unit_price' => 1200.00,
                'total_cost' => ($rate->machinery_hours / 1000) * 1200,
            ]);
        }

        $collectionBeton = NormativeCollection::where('code', 'FER-06')->first();

        $sectionBeton = NormativeSection::create([
            'collection_id' => $collectionBeton->id,
            'parent_id' => null,
            'code' => '06-01',
            'name' => 'Бетонные работы',
            'description' => 'Укладка бетонных смесей',
            'level' => 0,
            'sort_order' => 1,
            'path' => '/',
        ]);

        NormativeRate::create([
            'collection_id' => $collectionBeton->id,
            'section_id' => $sectionBeton->id,
            'code' => '06-01-001-01',
            'name' => 'Укладка бетонной смеси в фундаменты',
            'measurement_unit' => '100 м3',
            'base_price' => 185000.00,
            'materials_cost' => 150000.00,
            'machinery_cost' => 20000.00,
            'labor_cost' => 15000.00,
            'labor_hours' => 625.0,
            'machinery_hours' => 83.0,
            'base_price_year' => '2000',
        ]);
    }

    protected function createPriceIndices(): void
    {
        $regions = [
            ['code' => '77', 'name' => 'г. Москва'],
            ['code' => '50', 'name' => 'Московская область'],
            ['code' => null, 'name' => null],
        ];

        $year = now()->year;

        foreach ($regions as $region) {
            for ($quarter = 1; $quarter <= 4; $quarter++) {
                PriceIndex::create([
                    'index_type' => 'construction_general',
                    'region_code' => $region['code'],
                    'region_name' => $region['name'],
                    'year' => $year,
                    'quarter' => $quarter,
                    'index_value' => 7.8 + ($quarter * 0.1),
                    'source' => 'Минстрой России',
                    'publication_date' => now()->subMonths(4 - $quarter),
                ]);
            }
        }
    }

    protected function createCoefficients(): void
    {
        RegionalCoefficient::create([
            'coefficient_type' => 'climatic',
            'name' => 'Районный коэффициент (Крайний Север)',
            'description' => 'Коэффициент для районов Крайнего Севера',
            'region_code' => '89',
            'region_name' => 'Ямало-Ненецкий АО',
            'coefficient_value' => 1.7,
            'is_active' => true,
        ]);

        RegionalCoefficient::create([
            'coefficient_type' => 'winter',
            'name' => 'Зимнее удорожание работ',
            'description' => 'Коэффициент удорожания работ в зимний период',
            'coefficient_value' => 1.15,
            'effective_from' => now()->month(11)->day(1),
            'effective_to' => now()->addYear()->month(3)->day(31),
            'is_active' => true,
        ]);

        RegionalCoefficient::create([
            'coefficient_type' => 'seismic',
            'name' => 'Сейсмичность 7 баллов',
            'description' => 'Коэффициент для сейсмических районов 7 баллов',
            'coefficient_value' => 1.05,
            'is_active' => true,
        ]);
    }
}
