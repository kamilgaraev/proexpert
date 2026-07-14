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

Live smoke разрешён только после подтверждения одной атомарной пары полных SHA backend и admin frontend. Текущие deployment workflows эту пару не публикуют: backend сохраняет только короткий тег, admin не связывает активный bundle с полным SHA. Поэтому gate остаётся `BLOCKED_BY_DEPLOYMENT`, пока общий release coordinator не реализует протокол ниже. Короткий тег, `HEAD` изменяемой рабочей копии, два независимо обновляемых файла и значение, введённое оператором, доказательством не являются.

### Граница доверия

- Проверенные SHA берутся из одобренного release/PR и передаются verifier только как ожидаемые значения. Допустимы исключительно 40 строчных шестнадцатеричных символов.
- Verifier читает только `/var/lib/most-active-release/smoke-ready.manifest`. Production wrapper фиксирует этот путь и путь библиотеки при сборке; переменные окружения и аргументы не могут их переопределить.
- Manifest, его каталог и библиотека verifier принадлежат `root:root`, не доступны на запись группе и остальным пользователям и не являются symlink. Read-only пользователь `codex-ro` может их прочитать, но не изменить.
- Строгая схема manifest состоит ровно из четырёх строк в указанном порядке: `schema=most-active-release/v1`, положительный десятичный `generation`, `backend_sha`, `admin_sha`. Оба SHA полные и записаны строчными символами.
- Отсутствие, небезопасные владелец/права, неверная схема или несовпадение пары дают exit code `78` до любого вызова GStack.

Инфраструктурный владелец один раз устанавливает проверенные скрипты и создаёт root-owned state. Эти операции выполняет deployment-владелец, не оператор smoke:

```bash
install -d -o root -g root -m 0755 /usr/local/lib/most /usr/local/libexec/most
install -o root -g root -m 0555 scripts/lib/release-attestation.sh /usr/local/lib/most/release-attestation.sh
install -o root -g root -m 0555 scripts/verify-ai-estimator-release-attestations.sh /usr/local/libexec/most/verify-ai-estimator-release-attestations
install -d -o root -g root -m 0755 /var/lib/most-active-release
if [[ ! -e /var/lib/most-active-release/deploy.lock ]]; then
  install -o root -g root -m 0600 /dev/null /var/lib/most-active-release/deploy.lock
fi
if [[ ! -e /var/lib/most-active-release/generation.counter ]]; then
  printf '0\n' >/var/lib/most-active-release/generation.counter
  chown root:root /var/lib/most-active-release/generation.counter
  chmod 0644 /var/lib/most-active-release/generation.counter
fi
```

### Обязательный протокол deploy и rollback

Backend и admin activation hooks используют один fixed root-owned lock `/var/lib/most-active-release/deploy.lock` на одном release coordinator. Если компоненты активируются на разных узлах, coordinator и state должны быть вынесены в общий доверенный сервис; локальные независимые locks недопустимы.

Каждый deploy или rollback выполняет под эксклюзивным `flock` следующую последовательность:

1. До любого изменения активного контейнера, symlink или каталога удалить `/var/lib/most-active-release/smoke-ready.manifest` и active SHA изменяемого компонента. Это обязательная pre-activation invalidation, а не cleanup после ошибки.
2. Активировать целевой backend или admin artifact.
3. Дождаться public readiness и propagation и доказать связь активного artifact с полным SHA CI.
4. Атомарно заменить только соответствующий внутренний `backend.active` или `admin.active`.
5. Проверить оба active SHA, увеличить root-owned generation и одним `mv` опубликовать общий pair manifest.

Любой сбой после шага 1 завершает hook без повторной публикации: public manifest остаётся отсутствующим, verifier возвращает `78`, старый manifest не переживает неуспешную активацию. Rollback следует той же последовательности и не восстанавливает сохранённый старый manifest напрямую.

`generation.counter` монотонно увеличивается только под тем же эксклюзивным lock. Deploy и rollback никогда не сбрасывают и не переиспользуют generation; пропуски после сбоя допустимы, уменьшение или повтор значения запрещены.

Каркас coordinator hook, который должен быть встроен в оба deployment workflow:

```bash
set -euo pipefail

: "${COMPONENT:?CI must select backend or admin}"
: "${RELEASE_SHA:?CI must provide the activated full SHA}"
[[ "$COMPONENT" == backend || "$COMPONENT" == admin ]]
[[ "$RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]]

STATE=/var/lib/most-active-release
MANIFEST="$STATE/smoke-ready.manifest"
exec 9<>"$STATE/deploy.lock"
flock -x 9

rm -f "$MANIFEST" "$STATE/$COMPONENT.active"

activate_component_and_wait_for_public_readiness "$COMPONENT" "$RELEASE_SHA"

ACTIVE_TMP=$(mktemp "$STATE/.${COMPONENT}.active.XXXXXX")
trap 'rm -f "$ACTIVE_TMP" "${PAIR_TMP:-}" "${COUNTER_TMP:-}"' EXIT
printf '%s\n' "$RELEASE_SHA" >"$ACTIVE_TMP"
chown root:root "$ACTIVE_TMP"
chmod 0444 "$ACTIVE_TMP"
mv -f "$ACTIVE_TMP" "$STATE/$COMPONENT.active"

for ACTIVE in "$STATE/backend.active" "$STATE/admin.active"; do
  [[ -f "$ACTIVE" && ! -L "$ACTIVE" ]]
  [[ $(stat -c '%u:%g:%a' "$ACTIVE") == '0:0:444' ]]
done

BACKEND_SHA=$(<"$STATE/backend.active")
ADMIN_SHA=$(<"$STATE/admin.active")
[[ "$BACKEND_SHA" =~ ^[0-9a-f]{40}$ ]]
[[ "$ADMIN_SHA" =~ ^[0-9a-f]{40}$ ]]

GENERATION=$(<"$STATE/generation.counter")
[[ "$GENERATION" =~ ^[0-9]+$ ]]
GENERATION=$((GENERATION + 1))
COUNTER_TMP=$(mktemp "$STATE/.generation.XXXXXX")
printf '%s\n' "$GENERATION" >"$COUNTER_TMP"
chown root:root "$COUNTER_TMP"
chmod 0644 "$COUNTER_TMP"
mv -f "$COUNTER_TMP" "$STATE/generation.counter"

PAIR_TMP=$(mktemp "$STATE/.smoke-ready.XXXXXX")
printf 'schema=most-active-release/v1\ngeneration=%s\nbackend_sha=%s\nadmin_sha=%s\n' \
  "$GENERATION" "$BACKEND_SHA" "$ADMIN_SHA" >"$PAIR_TMP"
chown root:root "$PAIR_TMP"
chmod 0444 "$PAIR_TMP"
mv -f "$PAIR_TMP" "$MANIFEST"
trap - EXIT
```

`activate_component_and_wait_for_public_readiness` — обязательный deployment-owned hook, а не команда оператора. Для backend он проверяет публичный `/up` и полный immutable revision/digest фактически запущенного image. Для admin CI до активации вкладывает полный SHA в статический файл активируемого bundle; после атомарной смены build hook читает SHA из активного каталога и опрашивает этот же файл через публичный URL с `Cache-Control: no-store` до точного совпадения. Только после этого разрешено обновить `admin.active`. Такой hook ещё не установлен; до его реализации и проверки pair manifest публиковать нельзя.

### Проверка перед smoke

Перед запуском задать только проверенные SHA, несекретные URL/ID и пути к обезличенным fixtures. Учётные данные передаются через переменные окружения или заранее импортированную тестовую сессию, но не сохраняются в скрипте.

```bash
set -euo pipefail

: "${REVIEWED_BACKEND_SHA:?}"
: "${REVIEWED_ADMIN_SHA:?}"
: "${APP_URL:?}"
: "${FILAMENT_URL:?}"
: "${PROJECT_ID:?}"
: "${PDF_FIXTURE:?}"
: "${JPEG_FIXTURE:?}"
: "${PNG_FIXTURE:?}"
: "${SMOKE_DIR:=/tmp/most-ai-estimator-smoke}"

[[ "$REVIEWED_BACKEND_SHA" =~ ^[0-9a-f]{40}$ ]]
[[ "$REVIEWED_ADMIN_SHA" =~ ^[0-9a-f]{40}$ ]]

test -r "$PDF_FIXTURE" && test -r "$JPEG_FIXTURE" && test -r "$PNG_FIXTURE"
mkdir -p "$SMOKE_DIR"

ROOT=$(git rev-parse --show-toplevel)
FINALIZER="$ROOT/scripts/finalize-ai-estimator-release-gate.sh"
ATTESTATION_BEFORE="$SMOKE_DIR/release-attestation.before"
ATTESTATION_AFTER="$SMOKE_DIR/release-attestation.after"
REVIEWED_RELEASE="$SMOKE_DIR/reviewed-release.sha"
PASS_MARKER="$SMOKE_DIR/PASS"
test -x "$FINALIZER"
rm -f "$ATTESTATION_BEFORE" "$ATTESTATION_AFTER" "$REVIEWED_RELEASE" "$PASS_MARKER"
printf 'backend=%s\nadmin=%s\n' "$REVIEWED_BACKEND_SHA" "$REVIEWED_ADMIN_SHA" >"$REVIEWED_RELEASE"

ssh \
  -o BatchMode=yes \
  -o StrictHostKeyChecking=yes \
  -i /mnt/c/Users/kamilgaraev/.ssh/codex_readonly \
  codex-ro@89.169.44.117 \
  /usr/local/libexec/most/verify-ai-estimator-release-attestations \
  "$REVIEWED_BACKEND_SHA" "$REVIEWED_ADMIN_SHA" \
  >"$ATTESTATION_BEFORE"

B=""
test -n "$ROOT" && test -x "$ROOT/.agents/skills/gstack/browse/dist/browse" && B="$ROOT/.agents/skills/gstack/browse/dist/browse"
test -n "$B" || B="${GSTACK_BROWSE:-$HOME/.codex/skills/gstack/browse/dist/browse}"
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
9. Повторить `console`, `network`, сохранить последние user и Filament screenshots. На этом browser assertions завершены, но статус `PASS` ещё не создаётся.

Сразу после последнего `console`/`network`/screenshot в том же shell повторно проверить release и выполнить byte-for-byte finalization. До этого блока `PASS` отсутствует:

```bash
ssh \
  -o BatchMode=yes \
  -o StrictHostKeyChecking=yes \
  -i /mnt/c/Users/kamilgaraev/.ssh/codex_readonly \
  codex-ro@89.169.44.117 \
  /usr/local/libexec/most/verify-ai-estimator-release-attestations \
  "$REVIEWED_BACKEND_SHA" "$REVIEWED_ADMIN_SHA" \
  >"$ATTESTATION_AFTER"

"$FINALIZER" \
  "$ATTESTATION_BEFORE" \
  "$ATTESTATION_AFTER" \
  "$REVIEWED_BACKEND_SHA" \
  "$REVIEWED_ADMIN_SHA" \
  "$PASS_MARKER"

test -f "$PASS_MARKER" && test ! -L "$PASS_MARKER"
```

Finalizer требует точного совпадения нормализованных `generation/backend/admin` до и после browser flow. Exit `78`, отсутствие/изменение manifest, смена generation или SHA-пары означают `FAIL`: существующий `PASS` удаляется, новый marker или успешный evidence status не создаётся. Evidence сохраняет `release-attestation.before`, `release-attestation.after`, `reviewed-release.sha` и только при стабильной паре — атомарно созданный `PASS` summary.

Этот сценарий подготовлен для post-deploy проверки и сам по себе не означает, что smoke уже выполнен.
