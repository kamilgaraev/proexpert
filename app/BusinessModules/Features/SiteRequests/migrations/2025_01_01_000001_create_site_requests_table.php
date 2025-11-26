<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Создание основной таблицы заявок с объекта
     */
    public function up(): void
    {
        Schema::create('site_requests', function (Blueprint $table) {
            // PRIMARY KEY
            $table->id();

            // FOREIGN KEYS
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->onDelete('cascade');

            $table->foreignId('project_id')
                ->constrained('projects')
                ->onDelete('cascade');

            $table->foreignId('user_id')
                ->comment('Прораб-создатель заявки')
                ->constrained('users')
                ->onDelete('cascade');

            $table->foreignId('assigned_to')
                ->nullable()
                ->comment('Исполнитель заявки')
                ->constrained('users')
                ->onDelete('set null');

            // ОСНОВНЫЕ ПОЛЯ
            $table->string('title', 255)
                ->comment('Краткое название заявки');

            $table->text('description')
                ->nullable()
                ->comment('Подробное описание');

            $table->string('status', 50)
                ->default('draft')
                ->comment('Статус заявки');

            $table->string('priority', 50)
                ->default('medium')
                ->comment('Приоритет: low/medium/high/urgent');

            $table->string('request_type', 50)
                ->comment('Тип: material/personnel/equipment/info/issue');

            $table->date('required_date')
                ->nullable()
                ->comment('Желаемая дата исполнения');

            $table->text('notes')
                ->nullable()
                ->comment('Дополнительные примечания');

            // ПОЛЯ ДЛЯ ЗАЯВОК НА МАТЕРИАЛЫ
            $table->unsignedBigInteger('material_id')
                ->nullable()
                ->comment('ID материала из каталога (если активен модуль catalog-management)');

            $table->string('material_name', 255)
                ->nullable()
                ->comment('Название материала (если нет в каталоге)');

            $table->decimal('material_quantity', 15, 3)
                ->nullable()
                ->comment('Количество материала');

            $table->string('material_unit', 50)
                ->nullable()
                ->comment('Единица измерения: м³, т, шт...');

            $table->text('delivery_address')
                ->nullable()
                ->comment('Адрес доставки');

            $table->time('delivery_time_from')
                ->nullable()
                ->comment('Время доставки с');

            $table->time('delivery_time_to')
                ->nullable()
                ->comment('Время доставки по');

            $table->string('contact_person_name', 255)
                ->nullable()
                ->comment('Контактное лицо для доставки');

            $table->string('contact_person_phone', 50)
                ->nullable()
                ->comment('Телефон контактного лица');

            // ПОЛЯ ДЛЯ ЗАЯВОК НА ПЕРСОНАЛ
            $table->string('personnel_type', 50)
                ->nullable()
                ->comment('Тип персонала: mason, electrician...');

            $table->integer('personnel_count')
                ->nullable()
                ->comment('Количество человек');

            $table->text('personnel_requirements')
                ->nullable()
                ->comment('Требования к квалификации');

            $table->decimal('hourly_rate', 8, 2)
                ->nullable()
                ->comment('Почасовая ставка');

            $table->integer('work_hours_per_day')
                ->nullable()
                ->comment('Часов работы в день');

            $table->date('work_start_date')
                ->nullable()
                ->comment('Дата начала работ');

            $table->date('work_end_date')
                ->nullable()
                ->comment('Дата окончания работ');

            $table->text('work_location')
                ->nullable()
                ->comment('Место выполнения работ');

            $table->text('additional_conditions')
                ->nullable()
                ->comment('Доп. условия: проживание, питание, транспорт');

            // ПОЛЯ ДЛЯ ЗАЯВОК НА ТЕХНИКУ
            $table->string('equipment_type', 100)
                ->nullable()
                ->comment('Тип техники: crane, excavator...');

            $table->text('equipment_specs')
                ->nullable()
                ->comment('Характеристики: грузоподъемность...');

            $table->date('rental_start_date')
                ->nullable()
                ->comment('Дата начала аренды');

            $table->date('rental_end_date')
                ->nullable()
                ->comment('Дата окончания аренды');

            $table->integer('rental_hours_per_day')
                ->nullable()
                ->comment('Часов аренды в день');

            $table->boolean('with_operator')
                ->nullable()
                ->default(false)
                ->comment('С оператором');

            $table->text('equipment_location')
                ->nullable()
                ->comment('Место использования техники');

            // МЕТАДАННЫЕ
            $table->jsonb('metadata')
                ->nullable()
                ->comment('Гибкие кастомные поля');

            $table->unsignedBigInteger('template_id')
                ->nullable()
                ->comment('ID шаблона, если создано из шаблона');

            // СИСТЕМНЫЕ ПОЛЯ
            $table->timestamps();
            $table->softDeletes();

            // ИНДЕКСЫ
            $table->index('organization_id', 'idx_site_requests_org');
            $table->index('project_id', 'idx_site_requests_project');
            $table->index('user_id', 'idx_site_requests_user');
            $table->index('assigned_to', 'idx_site_requests_assigned');
            $table->index('status', 'idx_site_requests_status');
            $table->index('priority', 'idx_site_requests_priority');
            $table->index('request_type', 'idx_site_requests_type');
            $table->index('required_date', 'idx_site_requests_required_date');
            $table->index(['work_start_date', 'work_end_date'], 'idx_site_requests_work_dates');
            $table->index(['rental_start_date', 'rental_end_date'], 'idx_site_requests_rental_dates');
            $table->index(['organization_id', 'status'], 'idx_site_requests_org_status');
            $table->index(['organization_id', 'project_id', 'status'], 'idx_site_requests_org_project_status');
            $table->index('created_at', 'idx_site_requests_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_requests');
    }
};

