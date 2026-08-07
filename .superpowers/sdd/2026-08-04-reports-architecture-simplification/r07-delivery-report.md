# R07 — «Готовность Lookahead»: отчёт о поставке

## Итог

R07 `lookahead_readiness` опубликован как двадцать шестое встроенное определение единого каталога управленческой отчётности МОСТ. Отчёт доступен в контуре управления графиками выбранного проекта. При отсутствии исходных фактов формирование не запускается: интерфейс сообщает, что за точный выбранный период данных нет.

## Поставленные блоки

- Tenant-scoped availability/options: backend PR #275, merge `05fbdd7fd635afba8d0186a33e9ad8d85c2be1e3`, production workflow `31180478748` — success.
- Исправление канонического drill-down input: backend PR #276, reviewed commit `ee1f088a0ef57797c11d092753aca569e9cdfc5d`, merge `f0bc9d36220637dc156d28ff10a232bfd174c433`, production workflow `31182034365` — success.
- Publication и runtime binding: backend PR #277, reviewed commit `9fb68386fb13a9688266fe6a761b6b161e9a2a16`, merge `86aff1fdfd31ea320a947c0dfadfd0aa61433f58`, production workflow `31182389225` — success.
- Admin UI: PR #42, reviewed commit `c6fe942ffea7c737c1f46cd1509ca8d0e2408e86`, merge `5ec943a95ceb975d12372f7ebae7f0e358b60115`, production workflow `31183674186` — success.

## Контракт данных и расчёта

- Grain: `constraint_task_window`.
- Контракт: `1.0.0`.
- Формула: `lookahead_readiness.v1`.
- Source schema: `lookahead_events_v1`.
- Formula fingerprint: `2a038d2d3876dfcc103d2139a37549d4a8d5999cd32d0d0518e8c449c135175e`.
- Source fingerprint: `46f4908a65866aeab8b12a8df343df9ca8297eb6c0e60fc05d82de5e9356d69d`.
- В знаменатель входят только незавершённые задачи допустимого статуса, плановое начало которых попадает от даты среза до конца выбранного горизонта.
- Задача готова, только если все обязательные ограничения закрыты или имеют действующее подтверждённое исключение. Просроченное исключение и отсутствие обязательного связанного подтверждения сохраняют блокировку.
- Итоги: задачи к началу, готовые задачи, доля готовности, критические и некритические ограничения. При нулевом знаменателе доля остаётся неизвестной.

## Контекст, права и availability

- Организация, проект, actor и разрешённый scope определяются сервером из auth/current project context. Клиент передаёт только дату среза и горизонт; подмена `organization_id`, `project_id`, actor, роли или scope не принимается.
- View permission: `schedule.view`; export permission: `schedule.reports.export`; дополнительных sensitive/audit прав нет.
- `available`: факты и обязательная история полны, run разрешён.
- `no_data`: в выбранном окне нет подходящих задач; run и snapshot не создаются, интерфейс показывает точные границы периода.
- `source_incomplete`: подходящие задачи есть, но история или подтверждения источника неполны; run запрещён, пустой результат не имитируется.

## UI и production evidence

- UI русскоязычный: read-only организация и проект, дата начала, горизонт, availability, формула, итоги, строки, signed drill-down и CSV/XLSX.
- Внутренние status/reason codes, hashes, lineage и технические поля в интерфейс не выводятся; неизвестные доменные значения получают нейтральные бизнес-подписи.
- Production backend checkout после publication — exact `86aff1fdfd31ea320a947c0dfadfd0aa61433f58`. SHA-256 пяти ключевых publication/registry/route файлов совпали с локально проверенными артефактами: builtin `40a97b7ce8242487587bbb6f179f7ce13d7ce9daa5fd09652e1cb97a6db3727f`, registrar `b0ed7136d11a1cff1353838c0c33dc041876d1b93677b6d554d0fbaab6d04f42`, binding factory `1a2f09b6e10e377f9af3ff1788e653904503bc0feab70f2fe56e142ff43405a2`, definition registry `69e541914b076605da8c6b9199097e7fea132e7373e88e1973c5282032c4f524`, routes `65fc173b8ba9e35a454980c9fb8a1a3c93bcbcee135c5b8e4405934d05f8cadb`. В реестре найден R07, project-run route присутствует, в последних 5000 строках лога — 0 совпадений `lookahead_readiness`.
- Production admin `/release.json` вернул exact SHA `5ec943a95ceb975d12372f7ebae7f0e358b60115`; route `/reports/lookahead-readiness` и активный asset `/assets/index-CuVEVVOy.js` ответили 200. Активный asset содержит route `lookahead-readiness`, report code `lookahead_readiness`, formula version `lookahead_readiness.v1` и русские состояния отсутствия/неполноты данных.

## Проверки

- Backend availability: 6 тестов, 30 assertions; Pint и `git diff --check`.
- Backend drill-down fix: 1 тест, 9 assertions; Pint, `php -l` и `git diff --check`.
- Backend publication: 10 тестов, 187 assertions; Pint и `git diff --check`. PHPStan дошёл до общего bootstrap и остановился на известной несвязанной `storage_configuration_invalid`; исполняемый R07-контракт покрыт целевыми тестами и успешным production workflow.
- Admin: `npx tsc --noEmit`, 20 целевых Vitest-тестов, ESLint изменённых файлов, Prettier новых файлов и `git diff --check`.
- Локальный frontend build не запускался по ограничениям проекта; штатный production workflow выполнил install, Reverb verification, build, release attestation, проверку assets, copy и activation.

## Честные границы

- Production DB-команды не выполнялись. Известный read-only `codex-tinker` остаётся недоступен из-за `bootstrap/cache/services.php permission denied`; права не расширялись и ограничение не обходилось.
- Отсутствие фактов у отдельного SaaS-клиента не блокирует глобальную публикацию R07 и не создаёт фиктивных строк. Достоверность конкретного запуска определяется server-scoped availability для выбранного проекта и периода.
- После поставки R01 и R07 доказанно опубликовано 26/28 отчётов. Следующие отдельные блоки — R13 и R14.
