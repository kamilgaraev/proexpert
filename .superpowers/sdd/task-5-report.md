# Task 5 — явные контуры и права бизнес-уведомлений МОСТ

## Статус

Все 16 прямых вызовов `NotificationService::send` / `Notify::send` в `app/BusinessModules` и `app/Services` переведены на явный named-аргумент `interfaces`. Для чувствительных доменов зафиксированы существующие права, а рассылки системного администратора создают один `customer` target и отклоняют WebSocket до появления клиентской поддержки.

## Inventory отправителей

| Отправитель | Вызовов | Бизнес-аудитория | Контур | Обязательное право | Каналы |
| --- | ---: | --- | --- | --- | --- |
| `ContractEventIntegration` (`contract_status_changed`, `contract_limit_warning`) | 2 | владельцы организации договора | `lk` | `contracts.view` | настройки пользователя |
| `PaymentValidationService::notifyContractExcess` | 1 | пользователи организации с доступом к счетам при превышении суммы договора финальным платежом | `admin` | `payments.invoice.view` **или** `payments.invoice.view_all` | `in_app`, `websocket` |
| `EstimateGenerationNotificationService` | 1 | пользователь сессии генерации сметы | `admin` | `budget-estimates.view` | `in_app`, `websocket` |
| `SendProcurementNotifications` | 5 | менеджеры, создатели заявок и менеджер проекта согласно существующей логике событий | `admin` | `notifications.receive.procurement` | настройки пользователя |
| `SiteRequestNotificationService` | 1 wrapper | существующие получатели жизненного цикла заявки: менеджеры, автор, исполнитель | `admin` | `notifications.receive.site_requests` | настройки пользователя |
| `OneCExchangeIncidentService` | 1 | активные участники организации с доступом к интеграции 1С | `admin` | `one_c_exchange.view` | `in_app`, `websocket` |
| `OneCExchangeIncidentNotificationService` | 1 | активные участники организации с доступом к интеграции 1С | `admin` | `one_c_exchange.view` | `in_app`, `websocket` |
| `ContractorRegistrationNotificationService::sendNotificationToLK` | 1 | существующие администраторы/владельцы заказчика | `lk` | без нового gate: аудитория уже выбрана доменной логикой | `in_app`, `websocket`, при низкой оценке также `email` |
| `ContractorRegistrationNotificationService::sendNotificationToAdmin` | 1 | та же существующая аудитория, отдельное уведомление админки | `admin` | без нового gate: аудитория уже выбрана доменной логикой | `in_app`, `websocket`, при низкой оценке также `email` |
| `UserAuthSessionService` (`auth.new_device_login`) | 1 | вошедший пользователь | `admin` | без нового gate: self-security notification | `in_app`, `websocket` |
| `NotificationTemplateManagementService` | 1 | явно выбранные активные пользователи или активная аудитория шаблона | `customer` | явный `[]`: системный администратор уже определил аудиторию | один канал шаблона: `email`, `telegram` или `in_app`; `websocket` отклоняется до отправки |

Дополнительно `NotificationService::sendBulk` теперь требует непустой `options['interfaces']` и не допускает возврат к runtime default через `null`/пустой массив.

## Решение неоднозначностей

- `payments.view` оказался мёртвым slug: его нет в `PaymentsModule`, RoleDefinitions, маршрутах и переводах. Событие относится к финальному `PaymentDocument`, поэтому recipient prefilter и `requiredPermissions` синхронно переведены на OR из `payments.invoice.view` и `payments.invoice.view_all`. Resolver уже реализует OR-семантику массива прав.
- Для входа с нового устройства не добавлялся новый `lk` target: до задачи существовал один in-app вызов для `admin` и отдельное Laravel email-уведомление. Бизнес-аудитория не расширялась.
- Два security-вызова регистрации подрядчика сохранены раздельными (`lk` и `admin`), без случайного объединения в broadcast и без нового permission gate, способного исключить текущих владельцев.
- Для customer-template broadcast `null` продолжает означать вывод доменного права, а явный `requiredPermissions: []` означает осознанное отсутствие дополнительного gate. Поиск подтвердил отсутствие прежних production-вызовов named `requiredPermissions: []`; payload-поля AI-модуля этим различием не затрагиваются.

## TDD: RED

- Добавлен token-aware тест на `nikic/php-parser` с maintained manifest: точные 10 файлов, per-file counts и общий nonzero known count 16.
- Первый корректный запуск упал для всех отправителей без named `interfaces`, для payment/domain permissions и для implicit `sendBulk`/customer-template контрактов.
- Поведенческий тест customer template упал, потому что `system.test` неявно выводил `notifications.receive.system` и пропускал выбранного пользователя до persistence.
- Отдельный resolver-тест подтвердил RED: явный пустой массив ошибочно трактовался как `null` и запускал domain inference.

## GREEN и проверки

- `vendor/bin/phpunit tests/Unit/Notifications --colors=never` — **74 passed, 396 assertions**.
- Focused sender/resolver/behavioral tests в составе suite проверяют:
  - договор владельца сохраняется только для `lk` с `contracts.view`;
  - payment permissions работают как OR и target остаётся `admin`;
  - поддерживаемый customer channel создаёт одну logical notification;
  - customer WebSocket отклоняется до persistence;
  - explicit `[]` отключает permission inference, `null` сохраняет inference.
- Focused PHPStan для 12 изменённых production-файлов — **No errors**.
- `php -l` для 17 изменённых PHP-файлов — **No syntax errors**.
- Pint для 17 изменённых PHP-файлов — **PASS**.
- `git diff --check` — **clean**.

Дополнительный payment batch: 20 тестов прошли, `PaymentExportServiceTest` завершился до теста на существующей несовместимой с SQLite warehouse-миграции (`BTRIM` отсутствует в SQLite). Этот DB-backed прогон не повторялся и не является регрессией текущего diff.

## CI-only проверки

- `tests/Feature/Filament/NotificationManagementTest.php` расширен проверкой customer target и отказа WebSocket до создания уведомления. Тест использует БД и локально повторно не запускался.
- `tests/Feature/EstimateGeneration` и DB-backed `PaymentAccessControlTest` локально не запускались согласно запрету на DB/migration suites; остаются CI gate.

## Изменённые файлы

- 10 sender files из maintained inventory в `app/BusinessModules` и `app/Services`, включая template broadcast.
- `app/BusinessModules/Features/Notifications/Services/NotificationService.php`.
- `app/BusinessModules/Features/Notifications/Services/NotificationRecipientPermissionResolver.php`.
- `lang/ru/notifications.php`.
- `tests/Unit/Notifications/NotificationSenderContractTest.php`.
- `tests/Unit/Notifications/NotificationRecipientPermissionResolverTest.php`.
- `tests/Unit/Notifications/NotificationServiceTargetTest.php`.
- `tests/Feature/Filament/NotificationManagementTest.php`.

## Self-review и риски

- Legacy `data.interface` оставлен только как совместимое поле; final named `interfaces` является источником истины и normalizer канонизирует payload.
- Не добавлены роли и не захардкожены role slug-и.
- Organization/project context продолжает передаваться существующим resolver-ом; массив required permissions проверяется по OR, как и recipient prefilter платежа.
- WebSocket customer отклоняется до цикла отправки, поэтому chunk/selected broadcast не оставляет частично отправленную аудиторию.
- Единственный незакрытый runtime gate — CI-прогон DB-backed template/estimate/payment suites в мигрированном PostgreSQL окружении.
- Независимые `.cbmignore`, `.codebase-memory/` и `tmp/` сохранены и не добавляются в commit.

## Доработка после review

- Шаблонная рассылка считает отправленным только уведомление, которое вернулось сохранённым и с непустым итоговым набором каналов. Подавленные настройками или правами доставки учитываются отдельно в `suppressed_count`; принудительная отправка не используется.
- Для выбранной аудитории и рассылки всем разрешены только `email`, `telegram` и `in_app`. Любое другое или пустое значение отклоняется до проверки аудитории, запроса пользователей и сохранения уведомлений.
- `sent_count` и `suppressed_count` возвращаются вызывающему коду и сохраняются в `after` и `context` события аудита. В выборку идентификаторов и её хеш входят только реально поставленные в очередь уведомления.
- Инвентаризационный тест рекурсивно разбирает все PHP-файлы в `app/BusinessModules` и `app/Services`, разрешает полные имена через `NameResolver`, распознаёт типизированные зависимости `NotificationService`, получение через `app(NotificationService::class)` и фасад `Notify`, затем требует точного совпадения с манифестом из 16 вызовов.
- Ограничение статического анализа: намеренно не распознаются динамические контейнерные алиасы и вызовы через нетипизированные промежуточные обёртки; такой новый способ отправки должен сопровождаться расширением анализатора и манифеста.

### TDD и финальная проверка review

- RED: **11 tests, 18 assertions, 8 failures** — завышенный `sent_count`, поздняя/неполная валидация каналов и отсутствие `suppressed_count` в общей рассылке и аудите.
- GREEN: `vendor/bin/phpunit tests/Unit/Notifications tests/Unit/Filament/NotificationTemplateManagementServiceTest.php --colors=never` — **88 passed, 6008 assertions**.
- Focused PHPStan для изменённой логики и unit-тестов с `--memory-limit=512M` — **No errors**. Первый запуск с дефолтными 128 МБ завершился только по лимиту памяти и был повторён без изменения состава анализа.
- `php -l` и Pint для пяти затронутых PHP-файлов — **PASS**; `git diff --check` — **clean**.
- DB-backed `tests/Feature/Filament/NotificationManagementTest.php` обновлён новым ключом перевода, но локально не запускался согласно запрету на DB/migration suites; остаётся CI-проверкой.
