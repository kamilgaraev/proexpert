# R08 — «Динамика принятой выработки»: отчёт о поставке

## Итог

R08 `accepted_production_progress` опубликован как двадцать четвёртое встроенное определение единого каталога управленческой отчётности МОСТ. Отчёт доступен в обычном контуре управления договорами и не зависит от корпоративного модуля нескольких организаций.

## Поставленные блоки

- Неизменяемая граница истории приёмки: backend PR #259, merge `8c83015a2b5ecf78b034a4971900c16758602812`, production workflow `31088702559` — success.
- Точность количественных показателей: backend PR #260, merge `231a0325cc993099d661591f98227c7f65163bed`, production workflow `31096575965` — success.
- Общая защита чувствительных источников: backend PR #261, merge `f0c52f0c85087446ffe7dd959645e2dcb7c56593`, production workflow `31101957096` — success.
- Корректный readiness-контракт источников: backend PR #262, merge `13aed05f8cbf0fb59edfa2cad7470f69842ee7c8`, production workflow `31102872892` — success.
- Runtime и публикация: backend PR #263, merge `899a87bc4f9b6782870aa0ef4955d90aee815fdd`, production workflow `31109201740` — success.
- Project-scoped options: backend PR #264, merge `d7e70739b6030388e5a8ae7c83723d5de6840e1f`, production workflow `31111774777` — success.
- Admin UI: PR #40, merge `6438faa401acd0fdbc678f70ef1cbae8f3e572fd`, production workflow `31117691721`, attempt 2 — success. Первый attempt не получил runner и был отменён без выполнения шагов; повтор того же основного workflow прошёл полностью.

## Контракт данных и расчёта

- Grain: `accepted_work_day`.
- Контракт: `1.0.0`.
- Формула: `accepted_production.v1`.
- Source schema: `production_acceptance_events_v2`.
- Formula fingerprint: `839ea0b2787a0d73872bf5f7a63292437abaae05abb108ae92731abe3264f06b`.
- Source fingerprint: `620c23ba9f377b1b1b0b963a3b8dc39e5c3824920af37f715a429f0203981813`.
- Принятый объём учитывает события приёмки и отмены на их собственные даты признания; заявленный, но не принятый объём равен заявленному минус принятому, отклонение от плана — принятому минус плановый объём.
- Доля принятого объёма при нулевом плановом объёме остаётся неизвестной. Количества агрегируются только внутри совместимых единиц и версии пересчёта.
- Денежные показатели рассчитываются по согласованной ставке и группируются отдельно по валютам. RUB, USD и EUR не смешиваются, неизвестная валюта не подменяется RUB.

## Контекст, права и options

- Организация, проект, actor и разрешённый scope определяются сервером из auth/context. Клиент не отправляет `organization_id`, `current_organization_id`, `project_id`, `actor_id`, роль или permission как доверенный контекст.
- Дата среза и период обязательны. Работы, акты, подрядчики, единицы измерения, зоны и статусы поступают из реальных options выбранного проекта.
- View permission: `reports.production_progress.view`; export permission: `reports.production_progress.export`; sensitive permission: `budgeting.wip_forecast.view_sensitive_costs`; audit permissions отсутствуют.
- Без sensitive-права согласованная ставка, принятая стоимость и соответствующие экспортные значения скрыты. Строки, drill-down и экспорт повторно проходят object-level ABAC и source redaction.

## UI и production evidence

- UI русскоязычный: read-only организация и проект, дата среза, период, реальные options, таблица, итоги по единице и валюте, формулы, критерии доверия, signed drill-down, CSV/XLSX и состояния loading/empty/error/unavailable/stale/partial.
- Технические ключи, внутренние причины и служебное объяснение способа определения scope в интерфейс не выводятся.
- Создание run и export использует idempotency key; queued/materializing run остаётся заблокированным от повторного запуска. Смена контекста или фильтров инвалидирует устаревшие run, export и drill-down ответы.
- Drill-down открывает первую страницу подтверждений и позволяет последовательно загрузить остальные по действию «Показать ещё»; разрешаются только поддерживаемые server route names. Ссылки открывают конкретный акт, выполненную работу или запись журнала; произвольный `href` не используется.
- Production `/release.json` вернул exact SHA `6438faa401acd0fdbc678f70ef1cbae8f3e572fd`; R08 route ответил 200, catalog API без сессии — стандартным 401. Активный asset `/assets/index-C99DkF2i.js` ответил 200 и содержит route `accepted-production-progress`, report code `accepted_production_progress` и formula version `accepted_production.v1`.

## Проверки

- Backend-блоки: минимальные синтаксические и статические проверки по риску, точные semantic fingerprints, PostgreSQL-контракты истории/точности, `git diff --check` и независимые review. Успешные основные production workflows подтверждают поставку каждого отдельного блока.
- Admin: 41 целевой Vitest-тест в семи файлах, ESLint изменённых файлов, `npx tsc --noEmit`, `git diff --check`; независимое review после исправления idempotency, пагинации drill-down, exact deep links и защиты от устаревших ответов — CLEAN.
- Локальный frontend build не запускался по ограничениям проекта; штатный production workflow выполнил install, Reverb verification, build, release attestation, asset verification, copy и activation.

## Workflow sync и честные границы

- Пользовательская инструкция создана в YouTrack Knowledge Base: internal ID `180-79`, public ID `Pro-A-68`, заголовок «МОСТ: как работать с отчётом „Динамика принятой выработки“», родитель — `180-60` / `Pro-A-53` «МОСТ».
- Опубликованный R08 без подходящих фактов показывает предметное empty/unavailable состояние и не создаёт фиктивные строки. Неполная историческая граница закрывает расчёт стандартной ошибкой источника.
- R08 не является корпоративным отчётом. При 24 опубликованных определениях два корпоративных отчёта R02 и R03 дополнительно закрыты модульным gate `multi-organization`; без этого модуля ожидаемый максимум каталога при наличии всех остальных модулей и прав — 22 карточки.
