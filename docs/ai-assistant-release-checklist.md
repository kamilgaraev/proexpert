# МОСТ: проверка выпуска ИИ-помощника

Срез на 29 сентября 2026 года, backend-ветка `task/ai-assistant-luna-audit`. Этот документ разделяет локальные точечные проверки, подготовку хоста и действующий production. Полный выпуск не подтверждён.

## Точечно проверено локально

- [x] Backend framework Unit-набор: 120 файлов, 745 тестов, 36 275 assertions, ноль предупреждений. Отдельный Legal unit target: 2 теста / 163 assertions; framework suite не запускался повторно combined с ним. Лог: `storage/logs/assistant-framework-unit-final-source.log`. Unit-результат не является числом реальных QA-сценариев.
- [x] PHP 8.2.31: базовая syntax-проверка 612 файлов прошла; последние пять-file delta, scoped Stan и два Portal delta прошли. Лог основного syntax-прогона: `storage/logs/assistant-php82-syntax-frozen.log`.
- [x] Канонические legacy-финансы: 5 тестов / 27 assertions прошли. Лог: `storage/logs/assistant-coordinator-3.log`.
- [x] Source pins: 28 проверок проходят. Переносимость хешей Windows/LF проверялась отдельно.
- [x] Core/Finance owner static и pure checks прошли; это не подтверждает PostgreSQL-регрессии.
- [x] Админка: TypeScript, ESLint и 72 Vitest прошли. ЛК: TypeScript, ESLint и 19 Vitest прошли.
- [x] Mobile commit `58b5673` включает main commit `1c7a6bd` (warehouse); task branch и release tag `android-v1.0.33+41` проверены. Full Flutter CI, tests и analyze прошли; local combined suite: 59 PASS. Native `jarsigner` завершился с exit 0.
- [x] Mobile artifact-only workflow `36559099723` завершился успешно. AAB SHA-256 `ec517e2eec9865b1cf89d1a19a3bd884be17b519756193bd19d5109a2db81b69`, размер 82 442 920 байт. Артефакт не опубликован в store.
- [x] Pricing/alias/readiness pure suite: 56 тестов / 692 assertions PASS. Effective Luna rates — 13,5 / 67,5 ₽ за миллион токенов, `effective=2026-09-29-v2`; исторические округлённые 14 / 68 сохранены. Source pins: 28 PASS.
- [x] Native Finance target: 3 теста / 24 assertions PASS; это отдельная проверка, не часть native inventory из 527 cases.
- [x] Целевой PostgreSQL-набор: 3 теста / 22 assertions прошли. Лог: `storage/logs/assistant-coordinator-6.log`. Он не заменяет полный набор.
- [ ] Native inventory: исходный набор содержит 527 случаев. Прерванный прогон оставил 283 dot markers без итогового отчёта — это не полный PASS. Root подтвердил 189 случаев полностью; отдельный `collect58` дал 50 чистых случаев и 8 E/F. Узкий повторный прогон 24 случаев идёт; новый `lockedPayroll`-тест увеличит полный набор до 528. Не объединять partial markers и отдельные targets в итог PASS.
- [x] Отдельно подтверждены native guard 1 / 37, refund/webhook isolation 53 / 332, Runtime Infrastructure 5 / 42, Native3 backfill 43 и Finance target 3 / 24. Это отдельные проверки, не часть полного inventory.
- [ ] Coupled Core rerun и проверка исправлений price/policy/reader/JSONB/nullable-file остаются текущими gates; актуальный статус обновит root после 24-case и shadow-проверок.

## Хост и действующий runtime

- [x] Пользователь разрешил подготовку production-конфигурации и полный deploy после готовности; повторное разрешение не требуется. Пользователь потребовал выпускать только полное обновление.
- [ ] Подготовлены 31 AI env-переменная и два activation flag; они ещё не применены. Эта подготовка не является выпуском помощника. Исторические копии: `.env.20260929T015203Z.before-precise-pricing.bak`, runtime `runtime-luna.20260929T005510Z` в `/var/backups/most-ai-assistant/`.
- [x] Текущий внешний baseline deploy: восемь активных PHP-сервисов, image `cd3f5b0557ddf49030667c52b4db8f5de4a24e6e3427738dcacf25ac995435fa`, source SHA `9277406ed1e4de1896773a95094a99da09b451bc`. Это не AI-релиз; AI env и activation flags не применены. Более ранние image/SHA ниже — исторические snapshots.
- [x] Исторический env/runtime baseline backup: `/var/backups/most-ai-assistant/full-release-baseline-20260929T071116Z`; 2 098 506 752 байта, SHA-256 `6d5e2ee84c89628f1652bb28a3f13ffb629a3ac623983a64b2557e9b3b3419a0`.
- [x] Текущий runtime backup: `/var/backups/most-ai-assistant/full-release-runtime-20260929T123141Z-566151c05b24`. Image tar: 78 407 294 байта, SHA-256 `beade9bca2cbcc5d9f8abe25e7da26d50a036282569639ac7445092be4cfcc65`; host snapshot: 41 799 466 байт, SHA-256 `219257b148dd9846faf5f224a02706fd56a0404e00246bc7d67d52b003f1fcc4`; `.env` mode 0600.
- [x] Предыдущий DB backup `full-release-database-20260929T092004Z` сохранён как исторический. Текущая копия: `/var/backups/most-ai-assistant/full-release-database-20260929T123802Z/database.custom`, 869 752 329 байт, SHA-256 `97c5d2f1abd3c5907c0d4a5620d0d92ec42aff4936f50d2395236187ebf1729a`; `pg_restore --list`: 13 988 entries, client `pg_dump` 17.11. Backup log подтверждает `production_database_changed=false`.
- [x] Свежая read-only проверка allocation column/function/trigger прошла: все три объекта отсутствуют. Это проверка наличия, не применение миграций.
- [ ] Полный AI-релиз не выполнен. Backup подтверждён; перед миграциями всё ещё нужны актуальный preflight, проверка порядка и rollback.
- [ ] После полного deploy сверить образ, конфигурацию, модель и provider usage каждого активного PHP-сервиса.
- [ ] До deploy повторить read-only preflight по актуальному состоянию. Последний зафиксированный срез показывал 984 применённые миграции и 12 ожидающих AI-миграций; это историческое наблюдение, не свежая проверка.
- [ ] Перед миграциями подтвердить актуальный backup, состав полного выпуска, порядок миграции и rollback. Preview переноса старых кредитов и retention purge в production не запускались.

## Списания и оценка готовности

- [x] Списания отключены: `AI_ASSISTANT_CREDITS_ENFORCE=false`. Readiness approval пустой. Автоматическая retention-очистка отключена; не включать её до проверенного preview и отдельного решения.
- [ ] Завершить текущие PostgreSQL reruns и получить итоговые отчёты; interrupted inventory markers не засчитывать как PASS. Отдельные native guard, refund/webhook, Runtime Infrastructure, backfill и Finance targets не заменяют полный inventory. Unit, static и pure PASS не заменяют DB-регрессии.
- [ ] Проверить неполный ответ `incomplete/length`: не публиковать неполный текст и незавершённые tool calls, не списывать пользовательские единицы, освобождать резерв и сохранять фактическую себестоимость; API возвращает 409. Точечные проверки ожидают актуального root-прогона.
- [ ] Подтвердить закрепление профиля и цены между quote, подтверждением и выполнением. Больший профиль требует новой оценки и согласия; ответ `length` не повышает лимиты автоматически.
- [ ] Проверить маршрутизацию Scout для явно выбранной сметы и проекта, компактную wire-схему (6 154 токена против 6 835) и сохранение строгой серверной allowlist-валидации. Pure-проверка полного вопроса в 4 000 символов не заменяет API/DB и реальный model QA.
- [ ] Выполнить PostgreSQL-регрессии пяти legacy финансовых действий: организация, entity scope и ACL должны проверяться до SQL-агрегатов; десятичные расчёты, канонические акты и раздельные валюты должны сохранять точные значения выше `2^53`.
- [ ] Manifest содержит 389 сценариев; actual QA ещё не выполнен, порог ≥200 разных подтверждённых успешных model-quality входов не достигнут. План запуска: сначала первые 8 сценариев, затем один полный прогон 389; `smoke1` дублирует первые 8 и raw transport probes. Raw incomplete probe с cap 1 и usage 26/16, а также embedding probe 26/0 tokens, 256 dimensions — транспортные измерения, не сценарии и не scenario journal. Приватные синтетические бизнес-данные допустимы для настоящих вызовов Luna; synthetic/fabricated traces, usage, mock-ответы, отказы, ошибки и повторы не считаются положительными сценариями.
- [x] Admin/LK task-branch remotes проверены. Mobile commit `58b5673`, annotated tag `android-v1.0.33+41` и успешный artifact workflow `36559099723` подтверждены. Store publication не выполнялась.
- [ ] Покрыть обязательные категории минимум двумя сценариями на категорию и профили `short`, `normal`, `detailed`. Пять будущих типов данных presale недоступны и не индексируются; не выдумывать для них покрытие.
- [ ] Manifest из 389 предметных сценариев — план, не выполненные прогоны. После запуска сохранить фактический результат, provider usage/cost evidence и каждую проверку.
- [ ] Учесть фактические расходы провайдера по `assistant`, `memory`, `index`, `errors` и `ocr`, включая фоновые задачи и повторы. Подтвердить, что измеренные внешние расходы не выше 30% утверждённой проекции.

## Экономическая база и реальные данные

- [x] До появления фактической выручки согласован `economics_basis=prelaunch_projection`, `assistant_revenue_minor=0` и измеренные расходы провайдера. Проекция использует закреплённое распределение 3 990 ₽ / 5 000 включённых единиц (0,798 ₽ на единицу); это не cash revenue. Runtime-монитор фактически оплаченной выручки после выпуска учитывается отдельно.
- [ ] `price_version=2` не равен `EVALUATOR_VERSION=5`; формат traces имеет `SCHEMA_VERSION=2`. Не менять версии друг за друга и не принимать тариф без фактических provider usage/cost.
- [ ] Проверить на рабочих данных предметные права, суммы и уточнения по сметам, полноту индекса и архива, восстановление очереди и отзыв прав. Не запускать destructive purge при выключенном retention.
- [ ] Проверить негативные сценарии prompt injection в текстах, файлах, OCR и истории. Текст модели или источника не должен менять организацию, полномочия и подтверждение действия.
- [ ] После подтверждения результатов сформировать launch readiness approval по implementation fingerprint и price policy. Подписать настроенный `MOST_RELEASE_SHA` как provenance; verifier проверяет fingerprint и цену, не сравнивает approval с произвольным текущим Git HEAD. Launch envelope не имеет 7-дневного expiry; TTL относится к time-bound approval и свежести периода на момент launch approval. Только затем выполнить разрешённый полный deploy, миграции после DB backup и post-deploy smoke.
- [ ] Обновить пользовательскую документацию и YouTrack после подтверждения фактического состояния выпуска.

Число автоматических тестов не является числом экономических сценариев. Подготовленный host `.env`, построенный образ, транспортный бэкпорт и резервная копия не доказывают, что активные контейнеры обновлены или помощник готов к списаниям.
