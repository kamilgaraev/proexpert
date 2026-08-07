# R14 — «Изменения, требования и резерв»: отчёт о поставке

## Итог

R14 `change_claim_contingency` опубликован как двадцать восьмое встроенное определение единого каталога управленческой отчётности МОСТ. Отчёт использует реальные зафиксированные версии изменений, связанные требования, события согласования и движения резерва. Если за период нет версий изменений, снимок не создаётся и интерфейс сообщает: «За выбранный период данных нет.»

## Поставленные блоки

- Availability и реальные options: backend PR #282, reviewed commit `4f23b62ac`, merge `4f2a247b40fb96a9609910c4a292633886e9e66c`, production workflow `31189095933` — success.
- Publication и runtime binding: backend PR #283, reviewed commit `01a5ed799540bdd02cc3f4740725ea6cab40be93`, merge `6b73b342cb0887d5419bafd5c47b5c4ad0dafa93`, production workflow `31191399736` — success.
- Admin UI: PR #44, reviewed commit `28d6eb07e13643fa720504856255466002300936`, merge `c62389eae931445dab1435835e20c0ce07e50521`, production workflow `31192600110` — success.

## Контракт данных и расчёта

- Grain: версия изменения, распределение договора и валюта.
- Формула остатка резерва: начальный резерв + выделено − использовано − высвобождено.
- Предложенное и утверждённое влияние, связанные требования и движения резерва хранятся и выдаются в minor units; валюты не смешиваются.
- Источник фиксирует версии изменений, workflow-события, связи требований и ledger резерва. Runtime pin: formula `change-claim-contingency.v1`, source schema `change-claim-history.v1`.
- Фильтры: период, проекты, договоры, распределения, изменения, требования, статусы, валюты, типы и пользователи-инициаторы, ответственные, причины и типы источников резерва.

## Контекст, права и availability

- Организация, actor и разрешённый scope определяются сервером. Клиент не передаёт `organization_id`, actor или scope; проекты выбираются только из server-derived options.
- View permission: `change-management.view`; export permission: `change-management.reports.export`; модульный gate: `change-management`.
- `no_data`: в периоде нет зафиксированных версий изменений; run и snapshot не создаются.
- `source_incomplete`: факты есть, но отсутствует завершённый checkpoint, присутствуют непроецируемые legacy-записи, не заполнены распределение/валюта, утверждение не подтверждено движением резерва или ledger не покрыт выбранной последней версией. Run запрещён.
- `available`: обязательная история полна; canonical run разрешён.

## UI и production evidence

- Русскоязычный экран использует read-only текущую организацию, период и дату среза, все реальные server-derived options, состояния загрузки/ошибки/отсутствия/неполноты, итоги по валютам, строки, signed drill-down, CSV/XLSX, формулу и критерии доверия.
- Production backend checkout после publication — exact `6b73b342cb0887d5419bafd5c47b5c4ad0dafa93`.
- SHA-256 production совпали с reviewed release: builtin `8fbf25c487ebc8342afdbfd96e5eab92bbfcea0be9aa58b5dec1bef9ddb02c33`, registrar `a3e2a368e54c365b70190602952ed72e2462b7e27ca1faeedd498de79531b682`, binding factory `5230302e0ceb697f76fccef8f88e2c270cec11f1666710ac803ca1984f4c950f`, guarded provider `e3d8c2f18213fe41cd091a1e0faf395490202a8f8036baaa200296d41d7b137c`.
- Production definition registry содержит R14, dedicated run route найден один раз; в последних 5000 строках production-лога — 0 совпадений `change_claim_contingency|ChangeClaim`.
- Production admin `/release.json` вернул exact `c62389eae931445dab1435835e20c0ce07e50521`. Активный asset `/assets/index-9V9HSDV9.js` содержит route `change-claim-contingency`, report code `change_claim_contingency`, русский заголовок и состояния «За выбранный период данных нет.» / неполной истории.

## Проверки

- Availability/options: 7 изолированных PHPUnit-тестов, 25 assertions; Pint, PHP syntax и `git diff --check`.
- Publication/binding: 20 изолированных PHPUnit-тестов, 59 assertions; Pint, PHP syntax и `git diff --check`.
- Admin: 25 целевых Vitest-тестов, TypeScript `--noEmit`, ESLint изменённых файлов и `git diff --check`.
- Локальный frontend build не запускался по ограничениям проекта; основной production workflow успешно выполнил install, build, attestation, проверку assets, copy и activation.

## Итоговый аудит 28/28

- Backend authoritative registry содержит 28 опубликованных определений, включая R01, R07, R13 и R14.
- Admin authoritative list содержит 28 уникальных карточек и строится из одного списка; отдельные legacy-каталоги не добавлялись.
- R07, R13 и R14 имеют отдельные server-scoped options/run routes и fail-closed readiness.
- Production checkout backend и release attestation admin совпадают с точными merge SHA соответствующих релизов.
- В проверенных последних 5000 строках production-лога отсутствуют ошибки новых source/runtime R14; ранее зафиксированные проверки R01/R07/R13 остаются частью их delivery evidence.

## Честные границы

- Production DB-команды не выполнялись. Известный read-only `codex-tinker` остаётся недоступен из-за `bootstrap/cache/services.php permission denied`; права не расширялись и ограничение не обходилось.
- Исторический production checkpoint ранее показал непроецируемые legacy-записи. Для затронутых организаций R14 честно возвращает `source_incomplete`; это не блокирует глобальную SaaS-публикацию и не создаёт фиктивные строки.
- Пустой результат не используется как имитация готового отчёта: отсутствие данных и неполная история являются разными явными состояниями.
