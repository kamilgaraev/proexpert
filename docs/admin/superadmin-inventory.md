# Инвентарь Filament-суперадминки

Документ фиксирует финальное состояние Filament-суперадминки после выполнения production-grade плана `docs/superpowers/plans/2026-05-27-filament-superadmin-production-grade.md`.

- Дата фиксации: 2026-05-27.
- Ветка: `main`.
- Панель: Filament panel `admin`, URL `/admin`, guard `system_admin`.
- Роли панели: `config/RoleDefinitions/system_admin/*.json`.
- Основной permission namespace: `system_admin.*`.

## Границы

Проверенная зона:

- Filament panel provider: `app/Providers/Filament/AdminPanelProvider.php`.
- Pages, resources, relation managers, widgets and support classes in `app/Filament`.
- System admin model and role service: `app/Models/SystemAdmin.php`, `app/Services/Security/SystemAdminRoleService.php`.
- Domain services for high-risk operations: `app/Services/Filament`, `app/Services/Blog`.
- Role JSON files in `config/RoleDefinitions/system_admin`.

Не относящиеся к суперадминке dirty-файлы, оставленные нетронутыми:

- `app/BusinessModules/Features/AIAssistant/Console/Commands/BackfillRagIndexCommand.php`
- `tests/Feature/Console/AIAssistantRagBackfillCommandTest.php`
- `storage/prometheus/`

## Доступ

Вход в панель управляется моделью `SystemAdmin`.

Условия доступа:

- panel id равен `admin`;
- системный администратор активен;
- роль имеет `interface_access: ["admin"]`;
- роль имеет permission `system_admin.access`.

Важно: path `/admin` не означает использование ролей из `config/RoleDefinitions/admin`. Эта панель читает роли из `config/RoleDefinitions/system_admin`.

## Роли

| Роль | Назначение | Характер доступа |
| --- | --- | --- |
| `super_admin` | Владелец платформы | Полный доступ через `*`. |
| `content_manager` | Контент, блог, медиатека, SEO, шаблоны | Управляет редакционным контуром и частью уведомлений. |
| `qa_engineer` | Проверка платформы и клиентских сценариев | Просмотр операционных разделов, ограниченные действия с пользователями. |
| `security_auditor` | Аудит, проверки доступа, расследования | Read-only доступ к критичным областям и журналу аудита. |
| `support_operator` | Обработка обращений | Управляет обращениями поддержки, видит базовый контекст организаций и пользователей. |
| `support_viewer` | Наблюдение поддержки | Только просмотр обращений. |

Единый PHP-реестр permissions находится в `app/Filament/Support/FilamentPermission.php`. JSON-роли проверяются тестами `FilamentPermissionTest` и `RoleDefinitionJsonContractTest`.

## Навигация

Группы навигации централизованы в `app/Filament/Support/NavigationGroups.php`, русские названия вынесены в `lang/ru/filament_navigation.php`.

Текущие группы:

- Обзор
- Платформа
- Организации
- Биллинг
- Пользователи
- Блог CMS
- Поддержка
- Уведомления
- Аудит
- Настройки

Ресурсы скрываются из меню через `shouldRegisterNavigation()`, если текущая роль не может их просматривать.

## Pages

- `Dashboard`: command center платформы, проверяет `system_admin.dashboard.view`.
- `EditSystemAdminProfile`: профиль системного администратора, проверяет `system_admin.profile.view` и `system_admin.profile.update`.

## Resources

Top-level resources:

- `ActivityEventResource`
- `BlogArticleResource`
- `BlogCategoryResource`
- `BlogCommentResource`
- `BlogMediaAssetResource`
- `BlogSeoSettingsResource`
- `BlogTagResource`
- `ModuleResource`
- `NotificationAnalyticsResource`
- `NotificationResource`
- `NotificationTemplateResource`
- `OrganizationModuleActivationResource`
- `OrganizationPackageSubscriptionResource`
- `OrganizationResource`
- `OrganizationSubscriptionResource`
- `PaymentTransactionResource`
- `SubscriptionPlanResource`
- `SupportRequestResource`
- `SystemAdminResource`
- `UserResource`

Все ресурсы имеют явную resource-level authorization через policies, `AuthorizesSystemAdminResource` или собственные `can*` методы. Проверка покрытия: `SystemAdminResourceAuthorizationTest`.

## Widgets

Dashboard widgets:

- `PlatformHealthStatsWidget`
- `PlatformGrowthStatsWidget`
- `PlatformRiskStatsWidget`
- `NotificationDeliveryStatsWidget`
- `SaaSIncomeStatsWidget`
- `SubscriptionPlanStatsWidget`
- `UsersStatsWidget`

Все виджеты имеют `canView()` и не раскрывают метрики ролям без нужных permissions. Проверка покрытия: `SystemAdminWidgetVisibilityTest`.

## Операционные поверхности

- Организации: профиль, верификация, подписка, метрики, приостановка и реактивация.
- Пользователи: блокировка, разблокировка, подтверждение email, отправка сброса пароля.
- Биллинг: тарифы, подписки, платежи, ручные продления, отмена и реактивация подписок.
- Модули: каталог модулей, подключения организаций, ручная синхронизация прав.
- Блог CMS: статьи, календарь, ревизии, чеклист публикации, медиатека, SEO, комментарии.
- Уведомления: шаблоны, preview, test-send, доставка и аналитика.
- Поддержка: рабочее место обращений, назначение, статусы, внутренние заметки, эскалация.
- Аудит: read-only просмотр событий с фильтрами, маскированием чувствительных деталей и корреляцией.

## Опасные действия

Опасные действия не реализуются как bulk-delete:

- `rg "DeleteBulkAction|ForceDeleteAction" app\Filament` возвращает пустой результат.
- Удаление Filament-записей проходит через `HasDestructiveActionGuardrails`.
- Доменные действия используют сервисы и пишут audit event.
- Организации, пользователи, подписки, модули, support и blog media меняются через безопасные workflow-сервисы.

Покрывающие тесты:

- `SystemAdminDangerousActionsTest`
- `SystemAdminAuditServiceTest`
- `OrganizationCommandCenterTest`
- `UserManagementWorkflowTest`
- `BillingOperationsTest`
- `OrganizationModuleManagementTest`
- `SupportWorkspaceTest`
- `BlogMediaLibraryTest`

## Блог CMS

Редактор статьи вынесен в отдельные schema-классы:

- `BlogArticleForm`
- `BlogArticleTable`
- `BlogArticleInfolist`
- `BlogEditorBlocks`

Финальное состояние:

- полноэкранный редактор с черновым сохранением;
- публикационные действия сгруппированы отдельно от сохранения черновика;
- публикация защищена редакционным чеклистом;
- календарь поддерживает безопасные bulk-действия без массовой публикации и удаления;
- ревизии доступны для просмотра и восстановления черновиков;
- медиатека проверяет тип, размер, alt-текст и блокирует удаление/замену, если файл используется опубликованными статьями;
- SEO-поля, Open Graph, canonical URL и noindex находятся в форме статьи.

Покрывающие тесты:

- `BlogCmsEditorWorkflowTest`
- `BlogMediaLibraryTest`
- `BlogDocumentRendererTest`
- `BlogEditorialChecklistServiceTest`
- `BlogEditorialOperationsServiceTest`
- `BlogRevisionServiceTest`

## UI и тексты

Финальная проверка UI-copy:

- пользовательские empty states добавлены через `app/Filament/Support/TableEmptyState.php`;
- тексты empty states находятся в `lang/ru/filament_empty_states.php`;
- навигация и основные тексты суперадминки русифицированы;
- служебные слова вроде `fallback`, `legacy`, `payload`, `dto`, `exception`, `sql`, `constraint` не появляются в проверяемом UI-copy.

Команды проверки описаны в `docs/admin/superadmin-ui-copy-checks.md`.

## Проверки

Финальный targeted набор:

```powershell
php artisan test tests\Feature\Filament --stop-on-failure
php artisan test tests\Unit\Filament --stop-on-failure
php artisan test tests\Unit\Blog --stop-on-failure
php artisan test tests\Unit\RoleDefinitions --stop-on-failure
vendor\bin\phpstan analyse app\Filament app\Services\Filament app\Services\Blog app\Services\Security app\Models\SystemAdmin.php --memory-limit=1G --no-progress
```

Дополнительно выполнен `php -l` по 147 PHP-файлам из Filament, связанных сервисов, тестов и scoped translations.

## Browser QA

Browser QA не выполнен, потому что ни один локальный admin URL не был доступен:

- `http://127.0.0.1:8000/admin`
- `http://localhost:8000/admin`
- `http://127.0.0.1/admin`
- `http://localhost/admin`

Dev-сервер не запускался, потому что правила проекта запрещают поднимать его только ради QA. Скриншоты не создавались по той же причине.
