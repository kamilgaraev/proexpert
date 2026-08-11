# Переход на единый реестр имущества

Этот runbook описывает безопасный переход модуля «Техника и оборудование» на `organization_assets`. Он использует только штатный backend deployment workflow и не создаёт отдельный deployment-контур.

## Целевая модель

- `organization_assets` — источник истины для физической единицы, размещения, ответственного, жизненного и технического статуса.
- `asset_operation_profiles` определяет допустимый процесс: `custody`, `site_operation` или `shift_operation`.
- Количественные материалы остаются складскими остатками и не становятся физическими единицами.
- Каждый поэкземплярный складской актив сразу получает совместимую проекцию в реестре «Техника и оборудование». Профиль `custody` виден в реестре, но не получает сменные и диспетчерские действия.
- `machinery_assets` и ссылки в операционных таблицах сохраняются на переходный период как rollback-совместимая проекция.

## Автоматический go/no-go

Запуск:

```bash
php artisan assets:verify-cutover --format=json
```

Результат `GO` допустим только при нулевых значениях:

- `missing_links`;
- `duplicate_canonical_assets`;
- `dual_write_divergence`;
- `operations_without_organization_asset_id`;
- `open_assignments_with_inconsistent_placement`.

Ненулевой счётчик возвращает exit code `1`. Штатный scheduler запускает проверку каждый час, пишет JSON в `storage/logs/asset-registry-cutover.log` и отправляет ошибку в существующий `stderr`-канал логирования.

Для read-only разбивки причин используется `--details`. Перед началом окна наблюдения разрешён идемпотентный `assets:backfill`: он создаёт отсутствующие canonical-пары, переносит ссылку во все таблицы операций и согласует размещение с фактически действующим назначением. Если два legacy-назначения пересекаются, более позднее назначение считается заменой предыдущего, а предыдущий интервал закрывается точно на границе нового. Записи не удаляются. Одинаковое время старта или нарушение границ организации считаются неоднозначным конфликтом: команда завершается с ошибкой до изменения этой карточки.

## Фаза A — наблюдение

1. Развернуть release штатным `.github/workflows/deploy-backend.yml`.
2. Убедиться, что миграция создала недостающие проекции складских поэкземплярных активов и уникальный индекс ссылки.
3. В non-production включить:

```dotenv
ASSET_REGISTRY_STRICT_CANONICAL_READS=true
ASSET_REGISTRY_LEGACY_WRITES_ENABLED=true
```

4. Выполнить backend contract/feature, admin Vitest и mobile Flutter suites. Сравнить списки, workspace и стоимостные отчёты с compatibility-режимом.
5. В production оставить dual-write и compatibility-read минимум на `ASSET_REGISTRY_OBSERVATION_HOURS` (не менее 24 часов и не менее одного фактического цикла приёмки → назначения/выдачи → смены/возврата → утверждения).
6. Зафиксировать для начала и конца интервала: release SHA, UTC-время, JSON команды, число scheduler-запусков и отсутствие ошибок записи.

Production readiness нельзя подтверждать только тестами или единичным запуском команды.

## Фаза B — read cutover и запрет legacy create

Переход разрешён только после завершённой Фазы A и непрерывных нулевых результатов за весь операционный цикл.

```dotenv
ASSET_REGISTRY_STRICT_CANONICAL_READS=true
ASSET_REGISTRY_LEGACY_WRITES_ENABLED=false
```

После изменения выполнить штатный deployment, повторить `assets:verify-cutover`, smoke API/admin/mobile и проверить агрегаты утверждённых затрат. Legacy-таблицы остаются на месте и доступны для rollback-чтения.

Rollback выполняется возвратом предыдущего application release через штатный deployment-процесс. Обратная миграция данных не нужна: canonical links и legacy columns не удалены.

## Фаза C — удаление дублирующего интерфейса

После стабильной Фазы B удалить legacy create endpoint и старую форму создания физических единиц. Создание выполняется через складской поэкземплярный приём; сметный каталог `machinery` остаётся отдельным справочником типов техники. Не удалять маршруты выдачи, назначения, смен, обслуживания и аналитики, использующие canonical ID.

## Отдельный destructive release

Удаление legacy storage запрещено в read-cutover release. Перед отдельной destructive-миграцией обязательны:

1. финальный `assets:verify-cutover --format=json` с `ready=true`;
2. экспорт legacy-таблиц и контроль SHA-256 файла;
3. row-count reconciliation legacy/canonical/operation tables;
4. подтверждённая политика хранения и tested restore экспорта;
5. отдельный commit, PR, штатный deployment и отдельное окно отката.

До выполнения всех пяти пунктов `machinery_assets` и legacy columns не удаляются.

## Журнал наблюдения

| Поле | Начало | Конец |
|---|---:|---:|
| Release SHA | заполняется после deployment | тот же SHA или согласованный follow-up |
| UTC timestamp | заполняется оператором | не ранее чем через 24 часа и после реального цикла |
| Scheduler checks | 0 | не менее 24 успешных почасовых запусков |
| Максимум любого gate | — | 0 |
| Dual-write failures | — | 0 |
| Решение | OBSERVE | GO или NO-GO |
