# Интеграция заявок на технику и объяснимый подбор

## Цель и границы

Заявка типа `equipment_request`, созданная в модуле «Заявки с объекта», автоматически становится источником диспетчерской заявки MachineryOperations. Изменения затрагивают backend и admin. Mobile продолжает отправлять совместимый date-only payload и не требует отдельного изменения. Canonical `organization_assets`, operational projection `machinery_assets` и rollback storage остаются без изменений.

## Источник истины и связь

`site_requests` остаётся источником пользовательских полей и lifecycle для заявок, пришедших с объекта. В `asset_requests` добавляется nullable `site_request_id` с внешним ключом и уникальным ограничением. Поэтому повторная проекция использует одну и ту же строку, а не создаёт второй несвязанный документ. `origin_type` различает `site_request`, `manual` и `direct`.

Публичный `SiteRequestAssetProjectionService` в MachineryOperations вызывается из уже авторизованного `SiteRequestService` внутри его DB-транзакций. Он повторно проверяет organization/project scope, выполняет `updateOrCreate` по `site_request_id`, записывает immutable `asset_request_events` и не вводит queue/job/новую инфраструктуру. События SiteRequests сохраняются для существующих уведомлений и календаря, но не являются границей атомарности проекции.

Маппинг: `site_request.id` становится отображаемым номером источника; `project_id` и actor переносятся без смены scope; `title` и непустое `description` формируют `purpose`; `medium` преобразуется в `normal`; `equipment_start_at/equipment_end_at` имеют приоритет над legacy `rental_start_date/rental_end_date`; непустой `equipment_specs` переносится в nullable `requirements`. `required_profile` всегда пуст, пока пользователь явно не выбрал структурированное profile-требование. `tracks_fuel`, `tracks_production` и другие flags не выводятся из типа заявки или defaults.

## Время и совместимость

Для заявок на технику добавляются nullable `equipment_start_at` и `equipment_end_at` (`timestampTz`). Старые date-only клиенты продолжают использовать обязательный `rental_start_date`; projector превращает его в начало локального дня приложения, а `rental_end_date` — в конец дня. Admin отправляет новые datetime-поля и совместимые date-поля. Остальные типы заявок не получают новых обязательных полей.

## Минимальная двусторонняя синхронизация

- создание/редактирование активной site request создаёт или обновляет одну pending/approved asset request;
- `cancelled`, `rejected`, `fulfilled` и `completed` закрывают связанную asset request и активное назначение в той же транзакции;
- назначение по asset request переводит связанную site request в `in_progress` и пишет обе audit-истории;
- возврат техники завершает assignment, asset request и связанную site request;
- закрытые записи не переоткрываются повторной доставкой старого события.

`machinery_assignments.asset_request_id` хранит стабильную связь назначения с заявкой. Backend resource возвращает `asset_request_id`, `origin_type`, `request_number`, `site_request_id` и проект. Для старых строк все новые поля nullable, UI использует fallback без внутреннего assignment ID.

## Рейтинг кандидатов

Hard exclusions (`not_active`, `not_serviceable`, `period_overlap`, несовместимое явно заданное поле operation profile) вычисляются до рейтинга. Исключённый кандидат получает `score=null`, `suitability=excluded` и причины; число пригодности для него не показывается.

Для eligible-кандидата итог ограничен диапазоном 0–100:

- requirements: 40/40, поскольку все явно заданные требования уже прошли hard gate;
- location: 30 для текущего проекта; для известных координат линейно от 30 при 0 км до 0 при 100 км и далее; при отсутствии геоданных нейтрально 15;
- cost: 30 для минимальной стоимости и 0 для максимальной среди eligible-кандидатов, линейно между ними; если eligible-кандидат один или цены равны, всем даётся 30.

API возвращает округлённый score, `suitability` (`excellent` от 80, `good` от 60, иначе `acceptable`) и breakdown с points/max/label. Таким образом стоимость, размещение и требования ограничены сопоставимыми весами, а отсутствие координат не создаёт штраф масштаба 100000.

## UX

DecisionQueue форматирует ISO-значения через browser locale/timezone: `18.08.2026, 09:00 — 18.08.2026, 18:00`; открытый конец — `без даты окончания`. Карточка техники показывает `Заявка №<номер> · <начало>` либо `Прямое назначение · <начало>`, затем период, проект и русский статус. Для legacy назначения fallback — `Назначение · <начало>` без `#id`.

Eligible-кандидат показывает `Оценка N/100`, уровень и breakdown. Excluded-кандидат показывает `Не подходит` и причины без score. Формулировки profile-причин описывают требование и отключённую возможность техники, включая полный текст про фиксацию объёма выполненных работ.

## Проверки и выпуск

Сначала добавляются RED-тесты backend и admin на каждый дефект, затем минимальная реализация. Backend проверяется изолированными unit/integration-тестами, целевыми feature-тестами в штатном PostgreSQL CI, Pint/PHPStan; admin — Vitest, `tsc --noEmit` и ESLint затронутых файлов. Backend и admin выпускаются отдельными PR. Deployment выполняется только существующими workflows и считается завершённым лишь при `conclusion=success` и успешных штатных health/cutover checks.
