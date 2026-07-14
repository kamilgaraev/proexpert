# AI-сметчик МОСТ: эксплуатационный runbook

## Область и правило безопасности

Runbook относится только к AI-сессиям. Нельзя вручную менять статусы, очищать таблицы, повторять все задания, редактировать обычные сметы или обходить `Application/Apply`. Любая операция начинается с определения организации, проекта, сессии, текущей `state_version`, активной попытки и этапа. Секреты и содержимое документов не копируются в тикеты и чаты.

## Зависимости production

- PostgreSQL со всеми миграциями модуля, ограничениями, immutable triggers и индексами.
- Приватное S3-хранилище через `FileService`; пути сессий изолированы префиксом организации, pipeline/dataset/benchmark артефакты неизменяемы.
- Постоянно работающие workers для очередей `estimate-generation`, `estimate-generation-units`, `estimate-generation-unit-maintenance` и `estimate-generation-benchmarks`.
- Laravel scheduler каждую минуту запускает recovery units, recovery pipelines, доставку finalization и delivery геометрических регенераций; recovery lease datasets запускается каждые пять минут.
- Доступные AI/OCR/CAD/нормативные/ценовые провайдеры и корректные server-side credentials. Значения credentials в диагностику не выводятся.
- Наблюдаемость по безопасным логам, Filament dashboard, usage, failures, checkpoints и audit.

Наличие кода не доказывает готовность этих зависимостей. После каждого deploy проверяются worker, scheduler heartbeat, доступ к приватному S3 и provider readiness в целевом окружении.

## Ежедневный контроль

1. На дашборде выбрать последние 24 часа и проверить объём сессий, apply rate, p95, backlog, running/stale jobs и возраст старейшего задания.
2. Разделить стоимость по валюте, провайдеру, модели, этапу и организации; сопоставить с дневными и месячными бюджетами.
3. Проверить новые и повторяющиеся ошибки по категории, коду, этапу и fingerprint. Не закрывать ошибку до подтверждения восстановления.
4. Проверить сессии в `processing_documents`, `generating` и `applying`, которые не меняли состояние дольше согласованного окна. Сравнить lease, heartbeat и активную попытку.
5. Проверить scheduler и каждую очередь отдельно: процесс существует, нет устойчивого роста backlog, recovery/finalization задания поступают и завершаются.
6. Проверить последние dataset import и benchmark: нет истёкших lease, незавершённой компенсации S3 и непринятой регрессии.
7. Зафиксировать аномалию безопасными ID и временным диапазоном; не прикладывать документы, prompts, raw responses или stack traces.

## Retry, cancel и stuck

Retry выполняется только для одной сессии и сохранённого упавшего этапа. Сначала убедиться, что старая попытка не активна, lease истёк или failure зафиксирован, а provider/S3/queue восстановлены. Затем выполнить подтверждённое действие из Filament или доступное сервером действие snapshot. Повтор создаёт новую попытку и не должен публиковать поздний результат старой.

Cancel применяется к незавершённой сессии, когда продолжение больше не нужно или безопасное восстановление невозможно. Он не удаляет аудит и не изменяет уже созданную обычную смету. Archive допустим только для `failed`, `cancelled` и `applied` и скрывает сессию из рабочего списка без удаления истории.

Сессия считается подозрительно зависшей, если её активный статус не меняется, прогресс и heartbeat стоят, lease истёк, а соответствующего задания нет в работе. Порядок действий: проверить queue worker → scheduler/recovery → checkpoint и attempt → provider/S3 → выполнить один разрешённый retry. Если lease активен или попытка ещё выполняется, ручной retry запрещён. После повторного зависания эскалировать, не наращивая число попыток.

## Недоступность провайдера

При росте connection/HTTP failures сгруппировать ошибки по provider/model/stage и определить начало инцидента. Остановить ручные повторы, чтобы не увеличить стоимость и очередь. Проверить официальное состояние провайдера и внутреннюю сетевую доступность без вывода credentials. Если есть согласованная модель в versioned settings, изменение выполняет только `super_admin` новым snapshot; уже созданные операции сохраняют старую authority. После восстановления выполнить одну canary-сессию, затем контролируемо повторить ограниченную выборку.

## Регрессия benchmark

Регрессией считается нарушение согласованных метрик или порогов относительно immutable baseline при совпадающем dataset manifest и понятной версии pipeline/settings. Не продвигать изменение и не переобучать на acceptance-наборе. Сверить dataset kind/version/hash, execution snapshot, adapter/prompt/models, normative/price/currency и completeness отчёта. Повторить один запуск с теми же authority данными. Если результат воспроизводится, блокировать rollout и эскалировать владельцам pipeline/ML/QA с безопасным run UUID и метриками.

## Эскалация

- P1: риск изменения или дублирования обычных смет, утечка данных, массовая tenant-ошибка, неконтролируемая стоимость — остановить rollout/повторы и немедленно привлечь backend lead, security и владельца продукта.
- P2: устойчиво недоступен provider, растёт очередь, recovery/finalization не работает, массовые stuck sessions — привлечь backend/SRE и владельца интеграции.
- P3: единичная восстанавливаемая ошибка или локальная benchmark regression без production impact — зарегистрировать безопасные ID, версии и метрики для планового разбора.

Закрытие инцидента требует подтверждённой canary-сессии, нормального backlog/стоимости, отсутствия новых ошибок и записи причины с корректирующим действием.

## Deployment gate

Live smoke разрешён только после подтверждения, что backend и admin frontend развёрнуты из проверенных SHA. Если SHA не совпадает, статус gate — `BLOCKED_BY_DEPLOYMENT`; скриншоты и поведение старого release не являются evidence задачи.

Перед запуском задать только несекретные URL/ID и пути к обезличенным fixtures. Учётные данные передаются через переменные окружения или заранее импортированную тестовую сессию, но не сохраняются в скрипте.

```bash
set -euo pipefail

: "${EXPECTED_BACKEND_SHA:?}"
: "${EXPECTED_ADMIN_SHA:?}"
: "${DEPLOYED_BACKEND_SHA:?}"
: "${DEPLOYED_ADMIN_SHA:?}"
: "${APP_URL:?}"
: "${FILAMENT_URL:?}"
: "${PROJECT_ID:?}"
: "${PDF_FIXTURE:?}"
: "${JPEG_FIXTURE:?}"
: "${PNG_FIXTURE:?}"
: "${SMOKE_DIR:=/tmp/most-ai-estimator-smoke}"

test "$EXPECTED_BACKEND_SHA" = "$DEPLOYED_BACKEND_SHA"
test "$EXPECTED_ADMIN_SHA" = "$DEPLOYED_ADMIN_SHA"
test -r "$PDF_FIXTURE" && test -r "$JPEG_FIXTURE" && test -r "$PNG_FIXTURE"
mkdir -p "$SMOKE_DIR"

ROOT=$(git rev-parse --show-toplevel 2>/dev/null || true)
B=""
test -n "$ROOT" && test -x "$ROOT/.agents/skills/gstack/browse/dist/browse" && B="$ROOT/.agents/skills/gstack/browse/dist/browse"
test -n "$B" || B="$GSTACK_BROWSE/browse"
test -x "$B"

$B goto "$APP_URL/projects/$PROJECT_ID/estimates/ai-workspace"
$B snapshot -i -a -o "$SMOKE_DIR/user-start.png"
$B console
$B network
```

После стартового snapshot оператор выполняет шаги по refs, каждый раз делает `snapshot -D`, `console` и `network`:

1. Создать сессию, загрузить `$PDF_FIXTURE`, `$JPEG_FIXTURE`, `$PNG_FIXTURE` командой `$B upload @ref "$FILE"`, запустить анализ.
2. Убедиться, что показан прогресс; закрыть вкладку/перейти на другой URL, снова открыть URL с session ID и подтвердить продолжение состояния.
3. Проверить масштаб и исправить геометрию; подтвердить, что overlay/инспектор и snapshot обновились без ошибок.
4. Проверить модель здания, таблицу объёмов и evidence для выбранного элемента.
5. Проверить черновик, нормативные кандидаты, обязательные решения и readiness. Кнопка применения неактивна до закрытия обязательных вопросов.
6. Применить результат, открыть созданную новую обычную смету и записать её ID. Повторить apply для той же сессии и подтвердить тот же ID и отсутствие второй сметы.
7. Сохранить `$B screenshot "$SMOKE_DIR/user-final.png"`; console не содержит uncaught errors, network не содержит неожиданных 4xx/5xx.
8. Открыть `$FILAMENT_URL`, проверить dashboard/filters, session timeline, usage/cost, failures, checkpoints/queue, datasets, benchmark и settings/audit. Для роли без `monitor` прямой URL должен дать отказ. Опасные действия скрыты без `operate`, а с правом требуют confirmation. В DOM/ответах не должно быть prompt, raw document/response, stack trace, token, secret или Authorization.
9. Повторить `console`, `network`, сохранить annotated screenshots. Только после прохождения всех пунктов gate получает `PASS` с SHA, session ID, estimate ID, временем и путями evidence.

Этот сценарий подготовлен для post-deploy проверки и сам по себе не означает, что smoke уже выполнен.
