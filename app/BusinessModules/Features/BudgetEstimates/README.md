# Модуль "Сметное дело" (Budget Estimates)

## Описание

Полнофункциональный модуль для работы со строительными сметами в системе МОСТ.

## Статус реализации

### ✅ Реализовано (Готово к использованию)

#### База данных
- 7 миграций с полной структурой таблиц
- Индексы для оптимизации запросов
- Foreign keys и каскадные удаления

#### Модели и Repository
- 7 моделей Eloquent с relations
- 4 Repository класса с кешированием
- Scopes и accessor methods

#### Сервисы (Core)
- **EstimateService** - CRUD операции, дублирование
- **EstimateCalculationService** - автоматические расчеты
- **EstimateSectionService** - управление иерархией разделов
- **EstimateItemService** - управление позициями, bulk operations
- **WorkTypeMatchingService** - умное сопоставление (fuzzy matching)

#### Сервисы (Advanced)
- **EstimateVersionService** - версионирование, сравнение, откат
- **EstimateTemplateService** - создание и применение шаблонов

#### Интеграции
- **EstimateProjectIntegrationService** - синхронизация с проектами
- **EstimateContractIntegrationService** - связь с контрактами

#### API Controllers
- EstimateController - CRUD, dashboard, пересчет
- EstimateSectionController - управление разделами
- EstimateItemController - управление позициями
- EstimateVersionController - версионирование
- EstimateTemplateController - шаблоны

#### Events & Listeners
- 4 события (Created, Approved, VersionCreated, Imported)
- Канонический listener для EstimateApproved: CreateEstimateApprovalSnapshot
- Утверждение сметы не перезаписывает плановую стоимость проекта автоматически

#### Инфраструктура
- BudgetEstimatesModule класс
- Манифест модуля
- EstimatePolicy с 12 правами доступа
- 30+ API эндпоинтов

#### Документация
- Руководство пользователя
- Техническая документация

### ✅ Импорт из Excel (ГОТОВО)

- **5-шаговый wizard** - upload, detect, map, match, execute
- **Автоопределение структуры** - заголовки, колонки, разделы
- **Умное сопоставление** - fuzzy matching без ML
- **Sync/Async обработка** - до 500 строк мгновенно, больше в фоне
- **Валидация данных** - единицы измерения, числа, дубликаты

### 🔄 В разработке (Следующие фазы)

- **Экспорт в Excel/PDF** - генерация файлов
- **Аналитика** - сравнение смет, визуализация
- **Парсеры** - ФЕР/ГЭСН формат, Grand-Smeta XML
- **Тестирование** - Unit, Feature, Integration тесты

## Быстрый старт

### 1. Установка

```bash
# Выполнить миграции
php artisan migrate

# Убедиться что модуль зарегистрирован
# (автоматически через манифест)
```

### 2. Первая смета

```bash
# Через API
curl -X POST http://your-domain/api/v1/estimates \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Смета на строительство дома",
    "type": "local",
    "estimate_date": "2025-01-01"
  }'
```

### 3. Добавление позиций

```bash
# Создать раздел
curl -X POST http://your-domain/api/v1/estimates/1/sections \
  -d '{
    "section_number": "1",
    "name": "Общестроительные работы"
  }'

# Добавить позицию
curl -X POST http://your-domain/api/v1/estimates/1/items \
  -d '{
    "name": "Монтаж кабеля ВВГ 3х2.5",
    "quantity": 100,
    "unit_price": 500,
    "estimate_section_id": 1
  }'
```

### 4. Система автоматически пересчитает:
- Прямые затраты
- Накладные расходы (15%)
- Сметную прибыль (12%)
- НДС (20%)
- Итоговые суммы

## Основные возможности

### Создание смет
- Ручное создание с нуля
- Создание из шаблонов
- Дублирование существующих
- Привязка к проектам и договорам

### Управление структурой
- Иерархические разделы
- Drag & drop перемещение
- Автоматическая нумерация
- Каскадное удаление

### Автоматические расчеты
- Формула: `Прямые + Накладные + Прибыль`
- Применение коэффициентов
- Расчет НДС
- Пересчет при изменениях

### Версионирование
- Создание версий утвержденных смет
- Сравнение версий (diff)
- Откат к предыдущим версиям
- История изменений

### Шаблоны
- Создание из существующих смет
- Применение к новым сметам
- Публикация для холдинга
- Статистика использования

### Интеграции
- Синхронизация бюджета проекта
- Связь с суммой договора
- Сравнение с фактом
- События и уведомления

## API Endpoints

Все эндпоинты доступны с префиксом `/api/v1/`

### Estimates CRUD
- `GET /estimates` - список
- `POST /estimates` - создать
- `GET /estimates/{id}` - получить
- `PUT /estimates/{id}` - обновить
- `DELETE /estimates/{id}` - удалить
- `POST /estimates/{id}/duplicate` - дублировать
- `POST /estimates/{id}/recalculate` - пересчитать

### Sections
- `GET /estimates/{estimate}/sections` - список
- `POST /estimates/{estimate}/sections` - создать
- `PUT /estimate-sections/{id}` - обновить
- `DELETE /estimate-sections/{id}` - удалить
- `POST /estimate-sections/{id}/move` - переместить

### Items
- `GET /estimates/{estimate}/items` - список
- `POST /estimates/{estimate}/items` - создать
- `POST /estimates/{estimate}/items/bulk` - массовое создание
- `PUT /estimate-items/{id}` - обновить
- `DELETE /estimate-items/{id}` - удалить

### Versions
- `GET /estimate-versions/{estimate}` - история
- `POST /estimate-versions/{estimate}` - создать версию
- `POST /estimate-versions/compare` - сравнить
- `POST /estimate-versions/{version}/rollback` - откат

### Templates
- `GET /estimate-templates` - список
- `POST /estimate-templates` - создать
- `POST /estimate-templates/{id}/apply` - применить
- `POST /estimate-templates/{id}/share` - опубликовать

## Права доступа

```php
'estimates.view'              // Просмотр своих смет
'estimates.view_all'          // Просмотр всех смет
'estimates.create'            // Создание
'estimates.edit'              // Редактирование черновиков
'estimates.edit_approved'     // Редактирование утвержденных
'estimates.delete'            // Удаление
'estimates.approve'           // Утверждение
'estimates.templates.manage'  // Управление шаблонами
'budget-estimates.versions.create'   // Создание версий
'budget-estimates.versions.compare'  // Сравнение версий
```

## Настройки

Настройки модуля через `OrganizationModuleActivation`:

```php
[
    'estimate_settings' => [
        'auto_generate_numbers' => true,
        'number_template' => 'СМ-{year}-{number}',
        'default_vat_rate' => 20,
        'default_overhead_rate' => 15,
        'default_profit_rate' => 12,
    ],
    'calculation_settings' => [
        'round_precision' => 2,
        'recalculate_on_change' => true,
    ],
]
```

## Структура файлов

```
app/BusinessModules/Features/BudgetEstimates/
├── BudgetEstimatesModule.php
├── README.md
├── Services/
│   ├── EstimateService.php
│   ├── EstimateCalculationService.php
│   ├── EstimateSectionService.php
│   ├── EstimateItemService.php
│   ├── EstimateVersionService.php
│   ├── EstimateTemplateService.php
│   ├── Import/
│   │   └── WorkTypeMatchingService.php
│   └── Integration/
│       ├── EstimateProjectIntegrationService.php
│       └── EstimateContractIntegrationService.php
```

## Расширение функционала

### Добавление импорта

Добавляйте новый формат через runtime handler:
```php
final class VendorEstimateHandler implements RuntimeImportFormatHandlerInterface
{
    public function slug(): string
    {
        return 'vendor_estimate';
    }
}
```

После добавления зарегистрируйте handler в `ImportFormatRegistry` и покройте его тестом на detect, structure, preview и streamRows.

### Добавление экспорта

Расширьте EstimateExportService:
```php
public function exportToPDF(Estimate $estimate): string
{
    // Генерация PDF
}
```

## Производительность

- **N+1 queries**: решено через Eager Loading
- **Кеширование**: шаблоны кешируются в Redis
- **Пагинация**: позиции загружаются по 50
- **Индексы**: оптимизированы для частых запросов

## Roadmap

### v1.1 (Ближайшее)
- Импорт из Excel (простые таблицы)
- Экспорт в Excel
- Базовая аналитика

### v1.2 (Среднесрочное)
- Парсеры Гранд-Смета, РИК
- Экспорт в PDF
- Queue Jobs

### v1.3 (Долгосрочное)
- ML-based сопоставление
- Интеграция с внешними базами
- Продвинутая аналитика

## Поддержка

Документация:
- Пользовательская: `@docs/ESTIMATES_USER_GUIDE.md`
- Техническая: `@docs/ESTIMATES_TECHNICAL.md`

## Лицензия

Модуль является частью системы МОСТ.
