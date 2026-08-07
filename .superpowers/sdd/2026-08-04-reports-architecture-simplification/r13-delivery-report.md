# R13 — «Управленческий P&L»: отчёт о поставке

## Итог

R13 `management_pnl` опубликован как двадцать седьмое встроенное определение единого каталога управленческой отчётности МОСТ. Отчёт использует только реальные зафиксированные факты текущей организации. Если за период фактов нет, снимок не создаётся и интерфейс сообщает: «За выбранный период данных нет.»

## Поставленные блоки

- Availability и реальные options: backend PR #279, reviewed commit `129498afebeb85b5dbb093d08e4dbf06618ccd12`, merge `0f2a61e61bc92df58458f84feabf36137f994403`, production workflow `31185690318` — success.
- Publication и runtime binding: backend PR #280, reviewed commit `be784b6f1852a5cbb3bc14667ffd377dfd34359e`, merge `14b24fb3314bb80884a8ac168f0ab8e0b5bfac87`, production workflow `31186740496` — success.
- Admin UI: PR #43, reviewed commit `3e1d41b2448f372300594a380c12e70f0eef81a3`, merge `390daed4b0dd86bf1eebe973e930aef80aa2f36f`, production workflow `31187878806` — success.

## Контракт данных и расчёта

- Grain: период, сценарий, проект, центр ответственности, статья бюджета и валюта.
- Формула: валовая прибыль = выручка − прямые затраты; операционный результат = валовая прибыль − операционные расходы; валовая рентабельность = валовая прибыль / выручка × 100%.
- Источник состоит из точного зафиксированного tuple четырёх компонентов управленческого учёта и действующей на дату среза учётной политики.
- Строки и итоги используют minor units и не смешивают валюты. Нулевой знаменатель оставляет процент неизвестным.
- Фильтры: период, сценарий, доступные проекты, центры ответственности, статьи бюджета и валюты.

## Контекст, права и availability

- Организация, actor и разрешённый scope определяются сервером. Клиентские `organization_id`, `project_id`, `project_ids` в options, actor и scope запрещены; проекты для запуска выбираются только из server-derived options.
- View permission: `budgeting.management_pnl.view`; export permission: `budgeting.management_pnl.export`.
- `no_data`: за период нет ни одного факта; run и snapshot не создаются.
- `source_incomplete`: факты есть, но отсутствует действующая политика или точный sealed tuple; run запрещён, пустой результат не имитируется.
- `available`: политика и все четыре зафиксированные части источника совпадают с точным запросом; canonical run разрешён.

## UI и production evidence

- UI русскоязычный: read-only текущая организация, период, дата среза, сценарий, реальные server-derived options, состояния загрузки/ошибки/отсутствия/неполноты, итоги по валютам, строки, signed drill-down, CSV/XLSX, формула и критерии доверия.
- Production backend checkout после publication — exact `14b24fb3314bb80884a8ac168f0ab8e0b5bfac87`. SHA-256 совпали для builtin `67abad3799f5b3e8b4b097df652aaeb8f55d23c53454bd4c63524e3767eee2c4`, registrar `24fb7198d8eb98e96c58df02df53571de0772557c50cea794c704e763b897f5f`, binding factory `656a92a058c5b434682811ed8f03e08802d0fc2ec7bab90654a9b80b9d4ff399`, definition registry `4548783e35d9af9f26e36fc37cf72de19a73429b8e422f6ae9fcbe09776c7120` и routes `c374e6fb6220fbc54e4de01adecf74e4af40f406d2163b0939aedae709299104`. Run route найден один раз; в последних 5000 строках production-лога — 0 совпадений `management_pnl`.
- Production admin `/release.json` вернул exact SHA `390daed4b0dd86bf1eebe973e930aef80aa2f36f`. Активный asset `/assets/index-DczXyA5Z.js` содержит route `management-pnl` и утверждённое состояние «За выбранный период данных нет.».

## Проверки

- Backend availability/options: 6 изолированных PHPUnit-тестов, 21 assertion; Pint, PHPStan и `git diff --check`.
- Backend publication/binding: 19 изолированных PHPUnit-тестов, 217 assertions; Pint, PHPStan и `git diff --check`.
- Admin: 21 целевой Vitest-тест, `npx tsc --noEmit`, ESLint изменённых файлов и `git diff --check`.
- Локальный frontend build не запускался по ограничениям проекта; основной production workflow успешно выполнил install, Reverb verification, build, attestation, проверку assets, copy и activation.

## Честные границы

- Production DB-команды не выполнялись. Известный read-only `codex-tinker` остаётся недоступен из-за `bootstrap/cache/services.php permission denied`; права не расширялись и ограничение не обходилось.
- Отсутствие данных у отдельного SaaS-клиента не мешает глобальной публикации и не создаёт фиктивных строк. Доступность каждого запуска определяется server-scoped readiness для точного периода и снимка.
- После R01, R07 и R13 доказанно опубликовано 27/28 отчётов. Последний незавершённый отчёт — R14.
