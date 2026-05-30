<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_work_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('marketplace_work_categories')->nullOnDelete();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('type', 40);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();

            $table->index(['parent_id', 'is_active', 'sort_order']);
            $table->index(['type', 'is_active']);
        });

        $this->seedCategories();
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_work_categories');
    }

    private function seedCategories(): void
    {
        $now = now();
        $roots = [
            ['slug' => 'construction', 'name' => 'Строительно-монтажные работы', 'type' => 'construction', 'sort_order' => 10],
            ['slug' => 'engineering', 'name' => 'Инженерные системы', 'type' => 'engineering', 'sort_order' => 20],
            ['slug' => 'finishing_root', 'name' => 'Отделочные работы', 'type' => 'finishing', 'sort_order' => 30],
            ['slug' => 'design_root', 'name' => 'Проектирование и надзор', 'type' => 'design', 'sort_order' => 40],
            ['slug' => 'services_supply', 'name' => 'Услуги, техника и поставки', 'type' => 'service', 'sort_order' => 50],
        ];

        foreach ($roots as $root) {
            DB::table('marketplace_work_categories')->updateOrInsert(
                ['slug' => $root['slug']],
                array_merge($root, [
                    'parent_id' => null,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );
        }

        $rootIds = DB::table('marketplace_work_categories')->pluck('id', 'slug')->all();
        $children = [
            ['parent' => 'construction', 'slug' => 'monolith', 'name' => 'Монолитные работы', 'type' => 'construction', 'sort_order' => 10],
            ['parent' => 'construction', 'slug' => 'masonry', 'name' => 'Кладочные работы', 'type' => 'construction', 'sort_order' => 20],
            ['parent' => 'construction', 'slug' => 'earthworks', 'name' => 'Земляные работы', 'type' => 'construction', 'sort_order' => 30],
            ['parent' => 'construction', 'slug' => 'metal_structures', 'name' => 'Металлоконструкции', 'type' => 'construction', 'sort_order' => 40],
            ['parent' => 'construction', 'slug' => 'roofing', 'name' => 'Кровельные работы', 'type' => 'construction', 'sort_order' => 50],
            ['parent' => 'construction', 'slug' => 'facade', 'name' => 'Фасадные работы', 'type' => 'construction', 'sort_order' => 60],
            ['parent' => 'engineering', 'slug' => 'electrical', 'name' => 'Электромонтаж', 'type' => 'engineering', 'sort_order' => 10],
            ['parent' => 'engineering', 'slug' => 'plumbing', 'name' => 'Сантехнические работы', 'type' => 'engineering', 'sort_order' => 20],
            ['parent' => 'engineering', 'slug' => 'hvac', 'name' => 'ОВиК', 'type' => 'engineering', 'sort_order' => 30],
            ['parent' => 'engineering', 'slug' => 'fire_safety', 'name' => 'Пожарная безопасность', 'type' => 'engineering', 'sort_order' => 40],
            ['parent' => 'engineering', 'slug' => 'low_voltage', 'name' => 'Слаботочные системы', 'type' => 'engineering', 'sort_order' => 50],
            ['parent' => 'finishing_root', 'slug' => 'finishing', 'name' => 'Внутренняя отделка', 'type' => 'finishing', 'sort_order' => 10],
            ['parent' => 'finishing_root', 'slug' => 'flooring', 'name' => 'Полы и покрытия', 'type' => 'finishing', 'sort_order' => 20],
            ['parent' => 'finishing_root', 'slug' => 'windows_doors', 'name' => 'Окна и двери', 'type' => 'finishing', 'sort_order' => 30],
            ['parent' => 'engineering', 'slug' => 'installation', 'name' => 'Монтажные работы', 'type' => 'installation', 'sort_order' => 60],
            ['parent' => 'construction', 'slug' => 'welding', 'name' => 'Сварочные работы', 'type' => 'construction', 'sort_order' => 70],
            ['parent' => 'engineering', 'slug' => 'commissioning', 'name' => 'Пусконаладочные работы', 'type' => 'engineering', 'sort_order' => 70],
            ['parent' => 'design_root', 'slug' => 'design', 'name' => 'Проектирование', 'type' => 'design', 'sort_order' => 10],
            ['parent' => 'design_root', 'slug' => 'construction_supervision', 'name' => 'Строительный контроль', 'type' => 'supervision', 'sort_order' => 20],
            ['parent' => 'services_supply', 'slug' => 'equipment_rental', 'name' => 'Аренда техники', 'type' => 'service', 'sort_order' => 10],
            ['parent' => 'services_supply', 'slug' => 'materials_supply', 'name' => 'Поставка материалов', 'type' => 'supply', 'sort_order' => 20],
        ];

        foreach ($children as $child) {
            DB::table('marketplace_work_categories')->updateOrInsert(
                ['slug' => $child['slug']],
                [
                    'parent_id' => $rootIds[$child['parent']] ?? null,
                    'name' => $child['name'],
                    'type' => $child['type'],
                    'is_active' => true,
                    'sort_order' => $child['sort_order'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }
};
