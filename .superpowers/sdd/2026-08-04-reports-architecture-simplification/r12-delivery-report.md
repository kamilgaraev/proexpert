# R12 — «Расчёты и риски по договорам»: отчёт о поставке

## Итог

R12 `contract_settlement_exposure` опубликован как двадцать третье встроенное определение единого каталога управленческой отчётности МОСТ. Отчёт доступен в обычном контуре управления договорами и не зависит от корпоративного модуля нескольких организаций.

## Поставленные блоки

- История владельцев расчёта: backend PR #237, merge `8d17c6be258bf9cdbc0d44dbcde1cd9bfcf6b9e2`, production workflow `31054652160` — success.
- Runtime: backend PR #240, merge `e0a171f6382a9c09b7d71be9e240d112d2c4485e`, production workflow `31057348975` — success.
- Типизированная сторона договора: backend PR #243, merge `014a71df0e61218958039f99e593b3d87f59b13b`, production workflow `31060461348` — success.
- Point-in-time производительность owner history: backend PR #246, merge `f731c1c7e644fe33499659ec5c650f989c289832`, production workflow `31061499900` — success.
- Scoped options: backend PR #247, merge `e471ecbc723df10daf316299e590ed12f89fb25c`, production workflow `31062988080` — success.
- Admin UI: PR #38, merge `9d6c13eb638fa99d5f6454b9d2ac088f6dfa0be8`, production workflow `31083647822` — success.

## Контракт данных и расчёта

- Grain: `allocation_direction_currency_as_of`.
- Контракт: `3.0.0`.
- Формула: `contracts.settlement-exposure.v2`.
- Source schema: `contract_settlement_owner_history_latest_v3`.
- Formula fingerprint: `b0c715bcda2e44886ac32fd37e8dc3e30edc333adb01801e5f7f21481d65b9f2`.
- Source fingerprint: `a9003f712e1a298d546cada5dda0232d476157a6e10ae8d418116608d680ba1c`.
- Расчёт: сальдо = принято − оплачено; неисполненный объём = max(действующая сумма − принято, 0); неоплаченный объём = max(сальдо, 0).
- Денежные показатели агрегируются только внутри одной валюты; валюты не смешиваются и не подменяются значением по умолчанию.
- Подрядчик и поставщик с одинаковым числовым ID различаются типизированным ключом стороны. В пользовательский контракт попадает русская подпись, а не машинный тип или ключ.

## Контекст, права и options

- Организация, actor и разрешённый scope определяются сервером из auth/context. Клиент не отправляет `organization_id`, `current_organization_id`, `project_id`, `actor_id`, роль или permission как доверенный контекст.
- Проекты, договоры, распределения, стороны, направления, инструменты расчёта, статусы, валюты и интервалы просрочки поступают из реальных point-in-time options текущей организации.
- Зависимые options сужаются по выбранным проектам, договорам, распределениям, сторонам, направлениям и валютам; смена родителя очищает несовместимый выбор.
- View permission: `contracts.management_report.view`; export permission: `contracts.management_report.export`; дополнительных sensitive/audit прав нет.
- Исходные факты защищены object-level ABAC повторно для строк, drill-down и экспорта.

## UI и production evidence

- UI русскоязычный: read-only организация, дата среза, реальные filters/options, раздельные валютные итоги, таблица, signed drill-down, CSV/XLSX и состояния loading/empty/error/unavailable/stale/partial.
- Технические `row_key`, source codes и внутренние причины в интерфейс не выводятся.
- Смена организации, даты или фильтров инвалидирует незавершённый run, загрузку строк, drill-down и export. Export polling ограничен и прекращается до получения download link, если контекст уже устарел.
- Production `/release.json` вернул exact SHA `9d6c13eb638fa99d5f6454b9d2ac088f6dfa0be8`; страницы каталога и R12 route ответили 200. Активный asset `/assets/index-BkY7XIno.js` ответил 200 и содержит route `contract-settlement-exposure`, report code `contract_settlement_exposure` и formula version `contracts.settlement-exposure.v2`.
- Backend runtime/options ранее проверены после соответствующих production deploy; отсутствие сессии не раскрывает данные и возвращает стандартный auth-контракт.

## Проверки

- Backend-блоки: `php -l` затронутых PHP-файлов, точные semantic fingerprints, изолированные PostgreSQL-контракты по риску, `git diff --check` и независимые review — CLEAN. Локальные PHPUnit/Larastan не запускались из-за отсутствующего `vendor`; дальнейшим доказательством стали успешные основные production workflows.
- Admin: `npx tsc --noEmit`, ESLint изменённых файлов, 27 целевых Vitest-тестов в пяти файлах, `git diff --check`; независимое review после исправления зависимых options и отмены устаревших async-операций — CLEAN.
- Локальный frontend build не запускался по ограничениям проекта; штатный production workflow выполнил install, Reverb verification, build, release attestation, asset verification, copy и activation.

## Ограничения и честные границы

- R12 не является корпоративным отчётом. Из 23 опубликованных определений R02 и R03 дополнительно закрыты модульным gate `multi-organization`; их отсутствие у некорпоративного клиента ожидаемо.
- Опубликованный R12 без подходящих данных показывает предметное empty/unavailable состояние и не создаёт фиктивные строки.
- Read-only SSH к production во время финального документирования был недоступен по timeout banner exchange, поэтому в handoff не добавлены непроверенные текущие DB-счётчики. Это не подменяет и не отменяет ранее выполненные production-проверки runtime/options и успешные deploy evidence.
- YouTrack evidence не создан: доступный namespace `180-*` остаётся недоступным; фиктивный идентификатор не использовался.
