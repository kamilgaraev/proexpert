# Инвентарь Filament-суперадминки

Документ составлен для выполнения плана `docs/superpowers/plans/2026-05-27-filament-superadmin-production-grade.md`.

Дата фиксации: 2026-05-27.
Ветка: `main`.
Панель: Filament panel `admin`, URL `/admin`, guard `system_admin`.

## Границы инвентаря

Проверена серверная Filament-панель в `app/Filament`, модель системного администратора, provider панели и JSON-роли контекста `system_admin`.

Текущие незавершенные изменения в `main`, которые не относятся к этой работе и не должны затрагиваться:

- `app/BusinessModules/Features/AIAssistant/Console/Commands/BackfillRagIndexCommand.php`
- `tests/Feature/Console/AIAssistantRagBackfillCommandTest.php`
- `storage/prometheus/`

## Доступ к панели

- Provider панели: `app/Providers/Filament/AdminPanelProvider.php`.
- Panel ID: `admin`.
- Panel path: `admin`.
- Auth guard: `system_admin`.
- User model: `app/Models/SystemAdmin.php`.
- Проверка входа в панель: `SystemAdmin::canAccessPanel()`.
- Условия входа:
  - panel id равен `admin`;
  - системный администратор активен;
  - роль имеет `interface_access: ["admin"]`;
  - роль имеет permission `system_admin.access`.

Важно: путь `/admin` и panel id `admin` не означают, что используются роли из `config/RoleDefinitions/admin`. Для этой Filament-суперадминки используется `SystemAdminRoleService`, который читает роли из `config/RoleDefinitions/system_admin`.

## Роли суперадминки

Файлы ролей:

- `config/RoleDefinitions/system_admin/super_admin.json`
- `config/RoleDefinitions/system_admin/content_manager.json`
- `config/RoleDefinitions/system_admin/qa_engineer.json`
- `config/RoleDefinitions/system_admin/security_auditor.json`

Ключевые наблюдения:

- `super_admin` получает полный доступ через `*`.
- `content_manager` сфокусирован на блоге, медиатеке, SEO, ревизиях и шаблонах уведомлений.
- `qa_engineer` имеет просмотр и ограниченное управление пользователями, организациями, тарифами, блогом и уведомлениями.
- `security_auditor` имеет в основном read-only доступ к системным разделам, аудиту, блогу и уведомлениям.
- Permission namespace уже выстроен вокруг `system_admin.*`, но нет единого PHP-реестра permission-констант.

## Страницы

Текущие Filament pages:

- `app/Filament/Pages/Dashboard.php`
- `app/Filament/Pages/EditSystemAdminProfile.php`

Наблюдения:

- `Dashboard` проверяет `system_admin.dashboard.view`.
- `EditSystemAdminProfile` проверяет `system_admin.profile.view` и `system_admin.profile.update`.
- Страница профиля уже завязана на `system_admin` guard.

## Ресурсы

Текущие top-level resources:

- `BlogArticleResource`
- `BlogCategoryResource`
- `BlogCommentResource`
- `BlogMediaAssetResource`
- `BlogSeoSettingsResource`
- `BlogTagResource`
- `NotificationAnalyticsResource`
- `NotificationResource`
- `NotificationTemplateResource`
- `OrganizationResource`
- `SubscriptionPlanResource`
- `SystemAdminResource`
- `UserResource`

Текущая группировка навигации:

- Блоговые ресурсы: `Content`.
- Уведомления: `Уведомления`.
- Организации, пользователи, тарифы, системные администраторы: `System`.

Риски по навигации:

- Группы смешивают английские и русские названия.
- SaaS-операции пока не разложены по рабочим зонам: Platform, Organizations, Billing, Users, Blog CMS, Support, Notifications, Audit, Settings.
- Нет отдельного command center для организации как главной операционной сущности.

## Виджеты

Текущие widgets:

- `NotificationDeliveryStatsWidget`
- `SaaSIncomeStatsWidget`
- `SubscriptionPlanStatsWidget`
- `UsersStatsWidget`

Наблюдения:

- `NotificationDeliveryStatsWidget` уже имеет `canView()` и проверяет permissions уведомлений.
- `SaaSIncomeStatsWidget`, `SubscriptionPlanStatsWidget` и `UsersStatsWidget` должны получить явный `canView()`.
- `AdminPanelProvider` использует widget discovery, поэтому отсутствие `canView()` у метрик является риском утечки операционных данных между ролями.

## Авторизация ресурсов

Ресурсы с явными `canViewAny`, `canCreate`, `canEdit`, `canDelete`, `canDeleteAny`:

- `OrganizationResource`
- `SubscriptionPlanResource`
- `SystemAdminResource`
- `UserResource`

Ресурсы, где требуется дополнительная проверка единообразной авторизации:

- `BlogArticleResource`
- `BlogCategoryResource`
- `BlogCommentResource`
- `BlogMediaAssetResource`
- `BlogSeoSettingsResource`
- `BlogTagResource`
- `NotificationResource`
- `NotificationAnalyticsResource`
- `NotificationTemplateResource`

Текущий риск:

- Часть ресурсов авторизуется явно, часть полагается на поведение Filament, page actions или локальные `visible()`.
- Нужен общий слой `FilamentPermission` и helper/trait для resource-level authorization.

## Опасные действия

Найдены destructive actions:

- `BlogArticleResource`: `DeleteAction`.
- `BlogCategoryResource`: `DeleteAction`.
- `BlogCommentResource`: `DeleteAction`.
- `BlogTagResource`: `DeleteAction`.
- `NotificationTemplateResource`: `DeleteAction`.
- `BlogMediaAssetResource`: `DeleteAction`.
- `SubscriptionPlanResource`: `DeleteBulkAction`.
- `OrganizationResource`: `DeleteBulkAction`.

Критичный риск:

- `DeleteBulkAction` на организациях и тарифах должен быть заменен на доменные безопасные операции: deactivate, archive candidate, hide from sale, mark deprecated.
- Удаление media asset должно учитывать использование в опубликованных статьях.
- Удаление blog entities должно быть привязано к статусам, зависимостям и audit trail.
- Удаление notification templates должно учитывать отправленные уведомления и историю.

## Блог CMS

Текущая поверхность:

- Articles: create, edit, list, revisions relation manager.
- Categories: create, edit, list.
- Tags: create, edit, list.
- Comments: list.
- Media assets: create, edit, list.
- SEO settings: create, edit, list.

Наблюдения:

- `BlogArticleResource` уже имеет отдельные page actions для preview, publish, unpublish, duplicate и draft save с проверками permissions на уровне видимости.
- `BlogMediaAssetResource` содержит `FileUpload::make('upload_file')`; требуется усилить MIME, размер, image rules, alt text и проверку использования файла.
- Для идеального редакторского UX нужны checklist, revisions, publication history, editorial calendar, понятные статусы и защита публикации от неполных SEO/контентных данных.

## Уведомления

Текущая поверхность:

- `NotificationResource`
- `NotificationAnalyticsResource`
- `NotificationTemplateResource`
- `NotificationDeliveryStatsWidget`
- Relation manager для attempts у notifications.

Наблюдения:

- Раздел уже присутствует, но требует проверки read-only полей, preview/send-test workflow, audit для действий и единых permissions.

## SaaS Management Gaps

Для production-grade управления платформой не хватает цельных рабочих поверхностей:

- организация как command center;
- платежи и транзакции;
- подписки и ручные продления;
- модули и entitlements;
- support workspace;
- audit/activity resource;
- platform health dashboard;
- безопасные настройки и операционные действия.

## Тесты

На момент инвентаря отдельные Filament feature tests не найдены через поиск `tests/**/*Filament*.php`.

Первый тестовый каркас должен покрыть:

- доступ активного системного администратора в panel `admin`;
- отказ для неактивного системного администратора;
- отказ для обычного пользователя приложения;
- загрузку ролей `system_admin`;
- wildcard permission `*`;
- prefix permission вида `system_admin.blog.*`;
- отсутствие доступа при неизвестной роли;
- отсутствие доступа при неактивном системном администраторе.

## Ближайшие контрольные точки

1. Добавить тестовый каркас для `SystemAdmin::canAccessPanel()` и `SystemAdminRoleService`.
2. Ввести единый реестр Filament permissions.
3. Закрыть `canView()` у всех dashboard widgets.
4. Убрать unsafe bulk delete из организаций и тарифов.
5. Усилить медиатеку блога.
6. Привести blog resources и notification resources к единым resource authorization methods.
7. Добавить audit trail для high-risk действий.

