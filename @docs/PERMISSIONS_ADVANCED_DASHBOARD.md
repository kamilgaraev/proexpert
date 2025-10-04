# Права доступа к модулю Advanced Dashboard

## 📋 Обзор

Настроены права доступа к модулю "Продвинутый дашборд" для всех ролей пользователей в ProHelper.

**Дата настройки:** 4 октября 2025

---

## 🔐 Структура прав

Права модуля имеют иерархическую структуру:

```
advanced-dashboard.{категория}.{действие}
```

### Категории прав:

1. **`dashboards`** - Управление дашбордами
   - `view` - Просмотр дашбордов
   - `create` - Создание дашбордов
   - `edit` - Редактирование дашбордов
   - `delete` - Удаление дашбордов
   - `share` - Расшаривание дашбордов
   - `*` - Все права на дашборды

2. **`analytics.financial`** - Финансовая аналитика
   - `view` - Просмотр финансовой аналитики (Cash Flow, P&L, ROI, etc.)

3. **`analytics.predictive`** - Предиктивная аналитика
   - `view` - Просмотр прогнозов (завершение контрактов, риски бюджета, материалы)

4. **`analytics.hr`** - HR аналитика
   - `view` - Просмотр KPI сотрудников, топ исполнителей, загрузки ресурсов

5. **`alerts`** - Система алертов
   - `view` - Просмотр алертов
   - `create` - Создание алертов
   - `manage` - Управление алертами (edit, delete, toggle)
   - `*` - Все права на алерты

6. **`export`** - Экспорт
   - `pdf` - Экспорт в PDF
   - `excel` - Экспорт в Excel
   - `scheduled_reports` - Управление scheduled reports
   - `*` - Все права на экспорт

---

## 👥 Права по ролям

### 🔴 System Roles

#### Super Admin
**Файл:** `config/RoleDefinitions/system/super_admin.json`

```json
"advanced-dashboard": ["*"]
```

**Права:** Полный доступ ко всему модулю

---

#### System Admin
**Файл:** `config/RoleDefinitions/system/system_admin.json`

```json
"advanced-dashboard": [
  "advanced-dashboard.dashboards.*",
  "advanced-dashboard.analytics.*",
  "advanced-dashboard.alerts.*",
  "advanced-dashboard.export.*"
]
```

**Права:**
- ✅ Все операции с дашбордами
- ✅ Вся аналитика (финансовая, предиктивная, HR)
- ✅ Все операции с алертами
- ✅ Весь экспорт

---

#### Support
**Файл:** `config/RoleDefinitions/system/support.json`

```json
"advanced-dashboard": [
  "advanced-dashboard.dashboards.view"
]
```

**Права:**
- ✅ Только просмотр дашбордов
- ❌ Нет аналитики
- ❌ Нет алертов
- ❌ Нет экспорта

---

### 🟢 Organization Roles

#### Organization Owner
**Файл:** `config/RoleDefinitions/lk/organization_owner.json`

```json
"advanced-dashboard": ["*"]
```

**Права:** Полный доступ ко всему модулю

---

#### Organization Admin
**Файл:** `config/RoleDefinitions/lk/organization_admin.json`

```json
"advanced-dashboard": [
  "advanced-dashboard.dashboards.view",
  "advanced-dashboard.dashboards.create",
  "advanced-dashboard.dashboards.edit",
  "advanced-dashboard.dashboards.delete",
  "advanced-dashboard.dashboards.share",
  "advanced-dashboard.analytics.financial.view",
  "advanced-dashboard.analytics.predictive.view",
  "advanced-dashboard.analytics.hr.view",
  "advanced-dashboard.alerts.view",
  "advanced-dashboard.alerts.create",
  "advanced-dashboard.alerts.manage",
  "advanced-dashboard.export.pdf",
  "advanced-dashboard.export.excel",
  "advanced-dashboard.export.scheduled_reports"
]
```

**Права:**
- ✅ CRUD дашбордов
- ✅ Расшаривание дашбордов
- ✅ Вся аналитика (финансовая, предиктивная, HR)
- ✅ Управление алертами
- ✅ Экспорт в PDF/Excel
- ✅ Scheduled reports

---

#### Accountant (Бухгалтер)
**Файл:** `config/RoleDefinitions/lk/accountant.json`

```json
"advanced-dashboard": [
  "advanced-dashboard.dashboards.view",
  "advanced-dashboard.dashboards.create",
  "advanced-dashboard.analytics.financial.view",
  "advanced-dashboard.analytics.predictive.view",
  "advanced-dashboard.analytics.hr.view",
  "advanced-dashboard.export.pdf",
  "advanced-dashboard.export.excel",
  "advanced-dashboard.export.scheduled_reports"
]
```

**Права:**
- ✅ Просмотр и создание дашбордов
- ✅ Вся аналитика (финансовая, предиктивная, HR)
- ✅ Экспорт в PDF/Excel
- ✅ Scheduled reports
- ❌ Нет управления алертами

---

#### Viewer (Наблюдатель)
**Файл:** `config/RoleDefinitions/lk/viewer.json`

```json
"advanced-dashboard": [
  "advanced-dashboard.dashboards.view",
  "advanced-dashboard.analytics.financial.view",
  "advanced-dashboard.analytics.hr.view"
]
```

**Права:**
- ✅ Только просмотр дашбордов
- ✅ Финансовая аналитика (просмотр)
- ✅ HR аналитика (просмотр)
- ❌ Нет создания дашбордов
- ❌ Нет предиктивной аналитики
- ❌ Нет алертов
- ❌ Нет экспорта

---

### 🔵 Admin Interface Roles

#### Web Admin
**Файл:** `config/RoleDefinitions/admin/web_admin.json`

```json
"advanced-dashboard": [
  "advanced-dashboard.dashboards.view",
  "advanced-dashboard.dashboards.create",
  "advanced-dashboard.dashboards.edit",
  "advanced-dashboard.dashboards.delete",
  "advanced-dashboard.dashboards.share",
  "advanced-dashboard.analytics.financial.view",
  "advanced-dashboard.analytics.predictive.view",
  "advanced-dashboard.analytics.hr.view",
  "advanced-dashboard.alerts.view",
  "advanced-dashboard.alerts.create",
  "advanced-dashboard.alerts.manage",
  "advanced-dashboard.export.pdf",
  "advanced-dashboard.export.excel",
  "advanced-dashboard.export.scheduled_reports"
]
```

**Права:** Полный доступ (аналогично Organization Admin)

---

#### Finance Admin
**Файл:** `config/RoleDefinitions/admin/finance_admin.json`

```json
"advanced-dashboard": [
  "advanced-dashboard.dashboards.view",
  "advanced-dashboard.dashboards.create",
  "advanced-dashboard.analytics.financial.view",
  "advanced-dashboard.analytics.hr.view",
  "advanced-dashboard.export.pdf",
  "advanced-dashboard.export.excel"
]
```

**Права:**
- ✅ Просмотр и создание дашбордов
- ✅ Финансовая аналитика
- ✅ HR аналитика
- ✅ Экспорт в PDF/Excel
- ❌ Нет предиктивной аналитики
- ❌ Нет алертов
- ❌ Нет scheduled reports

---

## 📊 Сводная таблица

| Роль | Дашборды | Финансы | Прогнозы | HR | Алерты | Экспорт |
|------|----------|---------|----------|----|---------|---------| 
| **Super Admin** | ✅ Все | ✅ Все | ✅ Все | ✅ Все | ✅ Все | ✅ Все |
| **System Admin** | ✅ Все | ✅ Все | ✅ Все | ✅ Все | ✅ Все | ✅ Все |
| **Support** | 👁️ View | ❌ | ❌ | ❌ | ❌ | ❌ |
| **Organization Owner** | ✅ Все | ✅ Все | ✅ Все | ✅ Все | ✅ Все | ✅ Все |
| **Organization Admin** | ✅ CRUD + Share | 👁️ View | 👁️ View | 👁️ View | ✅ CRUD | ✅ PDF/Excel + SR |
| **Web Admin** | ✅ CRUD + Share | 👁️ View | 👁️ View | 👁️ View | ✅ CRUD | ✅ PDF/Excel + SR |
| **Finance Admin** | 👁️ View + Create | 👁️ View | ❌ | 👁️ View | ❌ | ✅ PDF/Excel |
| **Accountant** | 👁️ View + Create | 👁️ View | 👁️ View | 👁️ View | ❌ | ✅ PDF/Excel + SR |
| **Viewer** | 👁️ View | 👁️ View | ❌ | 👁️ View | ❌ | ❌ |

**Легенда:**
- ✅ Все - Полный доступ (CRUD)
- ✅ CRUD - Create, Read, Update, Delete
- 👁️ View - Только просмотр
- ✅ PDF/Excel - Экспорт в PDF и Excel
- ✅ SR - Scheduled Reports
- ❌ - Нет доступа

---

## 🎯 Рекомендации по ролям

### Для финансового директора
**Роль:** Accountant или Organization Admin

**Почему:**
- Полный доступ к финансовой аналитике
- Возможность создавать дашборды
- Экспорт отчетов в PDF/Excel
- Scheduled reports для автоматизации

### Для технического директора
**Роль:** Organization Admin или Web Admin

**Почему:**
- Доступ к предиктивной аналитике
- HR аналитика (KPI сотрудников)
- Управление алертами

### Для генерального директора
**Роль:** Organization Owner

**Почему:**
- Полный доступ ко всей аналитике
- Управление биллингом модуля

### Для просмотра отчетов
**Роль:** Viewer

**Почему:**
- Безопасный просмотр без возможности редактирования
- Доступ к ключевым метрикам

---

## 🔧 Проверка прав

### В коде (PHP)
```php
// Проверка конкретного права
if (auth()->user()->hasModulePermission('advanced-dashboard', 'dashboards.create')) {
    // Разрешено создавать дашборды
}

// Проверка wildcard
if (auth()->user()->hasModulePermission('advanced-dashboard', '*')) {
    // Полный доступ
}

// Проверка категории
if (auth()->user()->hasModulePermission('advanced-dashboard', 'analytics.financial.view')) {
    // Разрешен просмотр финансовой аналитики
}
```

### Middleware
```php
// В routes
Route::middleware(['module.permission:advanced-dashboard,dashboards.create'])
    ->post('/dashboards', [DashboardController::class, 'store']);
```

---

## 📝 Обновление прав

### Добавление нового права

**1. Определите категорию и действие:**
```
advanced-dashboard.{новая_категория}.{новое_действие}
```

**2. Обновите нужные роли:**
```json
"advanced-dashboard": [
  "advanced-dashboard.новая_категория.новое_действие"
]
```

**3. Запустите обновление прав:**
```bash
php artisan modules:scan
```

---

## 🔗 Связанные документы

- **OpenAPI спецификация:** `docs/openapi/advanced-dashboard.yaml`
- **Спецификация модуля:** `@docs/specs/advanced-dashboard-monetization-spec.md`
- **README модуля:** `app/BusinessModules/Features/AdvancedDashboard/README.md`

---

**Версия:** 1.0.0  
**Дата:** 4 октября 2025  
**Обновлено ролей:** 9  
**Статус:** Production Ready ✅

