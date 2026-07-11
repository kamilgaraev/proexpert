# Plan 1 Task 4 — отчёт

## Результат

- Добавлены восемь доменных прав AI-сметчика МОСТ: `view`, `create`, `upload_documents`, `generate`, `review`, `select_normative`, `export`, `apply`.
- Все 22 существующие ручки получили явный `authorize:<permission>`; групповой `authorize:admin.access` удалён.
- Несуществующая ручка `review-decisions` не создавалась. Проверка документов (`retry`, `ignore`) и обратная связь защищены правом `review`.
- Чувствительные FormRequest проверяют право через `AuthorizationService::can()` в контексте организации и проекта и запрещают запрос при отсутствии контекста.
- `organization_admin` и `parent_administrator` получили полный набор в группе `estimate-generation`. Viewer/observer-роли не получили `apply`.
- Русские названия группы и всех прав добавлены в `lang/ru/permissions.php`.

## Карта маршрутов

- `view`: список/карточка сессии, документы, статус, пакеты, черновик, элементы проверки.
- `create`: создание сессии.
- `upload_documents`: загрузка документов.
- `generate`: анализ, генерация, перестроение раздела.
- `review`: повтор/игнорирование документа, обратная связь.
- `select_normative`: поиск/выбор кандидата и справочник статусов нормативов.
- `export`: экспорт.
- `apply`: создание обычной сметы из AI-черновика.

## TDD и проверки

- RED: новый параметризованный контракт маршрутов падал на отсутствии доменных middleware и наличии `admin.access`.
- GREEN: `vendor/bin/phpunit tests/Feature/EstimateGeneration/EstimateGenerationRbacTest.php` — 23 теста, 53 проверки.
- Переводы: `vendor/bin/phpunit tests/Unit/Authorization/PermissionTranslatorTest.php` — 12 тестов, 122 проверки.
- `php -l` — без ошибок во всех изменённых и новых PHP-файлах.
- JSON ролей успешно разобран `ConvertFrom-Json`.
- PHPStan с `--memory-limit=512M` — без ошибок для изменённых FormRequest. Стандартный лимит 128 МБ был недостаточен.

## Ограничение окружения

Стандартный Laravel Feature bootstrap с миграциями в текущем SQLite-окружении блокируется существующей миграцией, использующей PostgreSQL-функцию `BTRIM`. RBAC-тест загружает приложение и реальный реестр маршрутов, но явно отключает `refreshDatabase()`: миграции и соединение с БД не выполняются.

## Исправление по результатам review

- Root cause: `AuthorizeMiddleware` без дополнительных аргументов автоматически формирует только organization-context. Поэтому проектная роль `parent_administrator` не могла применить выданные ей права к маршрутам AI-сметчика.
- RED: реальный Laravel route registry test упал на первом проектном маршруте, где отсутствовал `authorize:estimate_generation.view,project,project`.
- GREEN: все 21 project-scoped маршруты используют `authorize:<permission>,project,project`; глобальный справочник статусов намеренно использует `authorize:estimate_generation.select_normative,organization,current_organization_id`.
- Regex-контракт заменён тестом зарегистрированных Laravel routes без миграций и БД. Дополнительно напрямую выполняется реальный `AuthorizeMiddleware` с route-bound проектом и mocked `AuthorizationService`: разрешённое project-context право достигает следующего обработчика, отсутствующее право возвращает 403.
- Повторная проверка RBAC: 25 тестов, 78 проверок.
