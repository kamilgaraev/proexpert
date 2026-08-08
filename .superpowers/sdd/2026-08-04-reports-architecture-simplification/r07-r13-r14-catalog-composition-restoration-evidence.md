# Восстановление production-композиции R07, R13 и R14

Дата проверки: 2026-08-08.

## Причина корректировки evidence

Реализации, маршруты и runtime binding отчётов R07, R13 и R14 были поставлены ранее, но последующие параллельные слияния перезаписали общую композицию `ReportingCatalogServiceProvider`. Конструкторы трёх авторитетных builtin-реестров сохранили необязательные параметры новых отчётов, однако production provider передавал только первые 25 определений. Поэтому прежнее утверждение о фактической публикации 28/28 стало недостоверным и потребовало отдельного восстановления.

Исправление не добавляет данные, backfill, инфраструктуру, feature flags или новые workflow. Оно возвращает существующие опубликованные определения в единый каталог, метаданные и scheduling capability registry.

## Отдельные восстановительные релизы

- R07 `lookahead_readiness`: PR #293, reviewed commit `3ddcdef50124e48b479989dfa9539dfa8bb8fb59`, merge `cd93dd7aafc382bff91936486085ddc690f40862`, production workflow `31234715360` — success.
- R13 `management_pnl`: PR #294, reviewed commit `24c2be950e6404ecc16a114130c3997e899589e6`, merge `5aa46ac5c5a9284d5f9eff4c1330e9a02fc276ff`, production workflow `31234936793` — success.
- R14 `change_claim_contingency`: PR #295, reviewed commit `289b0c6f01459192b8fe05d61d5bb4f192965a9c`, merge `d1b553f2e3d53c76064917ed61bd2b9672747078`, production workflow `31235118694` — success.

Каждый релиз выполнен из отдельной ветки и worktree от свежего `origin/main`, содержит только wiring одного отчёта и его регрессионную проверку.

## Проверки исполняемого контракта

- R07: 3 PHPUnit-теста, 22 утверждения.
- R13: 4 PHPUnit-теста, 20 утверждений.
- R14: 4 PHPUnit-теста, 20 утверждений.
- Общая композиция definition и metadata registry: 2 PHPUnit-теста, 98 утверждений.
- Изменённые PHP-файлы прошли `php -l`; все три diff прошли `git diff --check`.
- Admin-сценарии R07/R13/R14: целевой Vitest-прогон успешен; проверены состояния отсутствия данных, неполного источника и запрета запуска без фактов.

## Read-only production evidence

- Итоговый backend checkout: exact `d1b553f2e3d53c76064917ed61bd2b9672747078`.
- В блоке создания `BuiltinPublishedReportDefinitionRegistry` production provider обнаружено ровно 28 уникальных `BuiltinPublishedReport`.
- `LookaheadReadinessBuiltinPublishedReport`, `ManagementPnlBuiltinPublishedReport` и `ChangeClaimBuiltinPublishedReport` присутствуют по три раза каждый: definition registry, metadata registry и scheduling capability registry.
- В последних проверенных 5000 строках production-лога нет ошибок `lookahead_readiness`, `management_pnl`, `change_claim_contingency` или общего report catalog после соответствующих релизов.
- Неавторизованный запрос единого production-каталога отвечает ожидаемым `401`, то есть маршрут опубликован и защищён JWT-контуром.
- Production admin workflow `31228568835` успешно развёрнул текущий `main` SHA `756242ce7be309f36d95d6cbdcd88f2baf6f5c73`.
- Единый admin-список `publishedReportDefinitions` содержит ровно 28 report code, включая R07, R13 и R14; для всех трёх существуют отдельные русскоязычные страницы и маршруты `PublishedReportRoute`.

Production DB-команды, миграции вручную, tinker, dev servers и frontend build локально не запускались. Read-only `codex-tinker` не использовался из-за известного `permission denied` на `bootstrap/cache/services.php`; права не расширялись и ограничение не обходилось.

## Удаление лишней инфраструктуры подписи снимков

Отдельный runtime-релиз PR #292, merge `1ee319791b79e95a58f20ff4ecf27f4bd27f6859`, production workflow `31234354202` удалил обязательные `REPORT_SNAPSHOT_SIGNING_KEY_ID` и `REPORT_SNAPSHOT_SIGNING_PRIVATE_KEY`, trusted-key registry и Ed25519 sealer/verifier. Целостность снимка сохраняется локальным SHA-256 content hash формата `content_hash_v1`; совместимость существующих записей сохранена миграцией, применённой штатным deploy workflow. Целевой набор: 80 тестов, 560 утверждений.

## Итог 28/28

Все 28 канонических отчётов присутствуют в одном backend definition registry и одном admin-списке. R07, R13 и R14 больше не теряются при production-композиции. Отсутствие данных конкретного SaaS-клиента не создаёт фиктивный снимок: UI показывает, что за выбранный период данных нет; неполный обязательный источник остаётся отдельным fail-closed состоянием.
