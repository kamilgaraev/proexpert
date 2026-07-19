# Production-развёртывание backend МОСТ

Единственный штатный путь production-развёртывания — `.github/workflows/deploy-backend.yml`. Workflow использует неизменяемый digest образа и сериализуется через `prod-backend-deploy`.

## Admission-последовательность

1. Workflow собирает и загружает образ, production-сервер скачивает exact digest до остановки трафика.
2. На сервере проверяется `LEGAL_ARCHIVE_AUDIT_WRITER_SECRET`. Если ключ отсутствует, он один раз создаётся через `openssl rand -hex 32` и сохраняется в `.env` с `umask 077`. Существующий сильный секрет сохраняется; слабый секрет блокирует deployment. Значение не передаётся из GitHub, не выводится и не становится аргументом внешней команды.
3. Останавливаются nginx, API, WebSocket, Horizon, scheduler, специализированные queue workers и старые supervisor-процессы. Compose ждёт их завершения; наличие оставшегося Laravel runtime блокирует продолжение.
4. One-off контейнер нового образа выполняет `migrate:safe --force`, пока ни один новый runtime не обслуживает трафик.
5. One-off процессы выполняют `immutable-audit:confirm-drain`, Phase B cutover и `immutable-audit:writer-readiness`. Флаг `LEGAL_ARCHIVE_AUDIT_PHASE_B_CUTOVER_ENABLED=true` передаётся только двум процессам cutover через `docker compose run -e` и никогда не сохраняется в `.env`.
6. Только после успешного worker-readiness пересоздаются backend-сервисы. API допускается compose healthcheck по `/ready`; `/up` проверяется отдельно как liveness. Nginx запускается последним после внутренних `/ready`, `/up`, WebSocket и geometry-проверок.

## Поведение при ошибке

После начала остановки любой ненулевой код активирует failure trap: nginx и все backend writer-сервисы остаются остановленными, workflow завершается ошибкой. Автоматического отката на старый writer нет, потому что после миграции и Phase B такой откат был бы ложным и небезопасным. Повторный запуск использует тот же серверный секрет, идемпотентные миграции, свежий drain marker и восстановление concurrent indexes под отдельной advisory-блокировкой.

Перед разбором ошибки разрешены только чтение логов и состояния. Нельзя вручную включать ingress или workers, пока one-off `immutable-audit:writer-readiness` не завершится успешно.

## Постоянные инварианты admission

Каждый `/ready` и `immutable-audit:writer-readiness` без кеша проверяет Phase B marker, writer version, fingerprint, sequence, allocator, включённый DB guard и точные valid/ready определения обоих idempotency indexes. Дрейф любого элемента закрывает admission.

Временный Phase A-контур удаляется по критерию из `PHERP-138`: все production-окружения работают в Phase B не менее 30 дней, legacy-writer rejection отсутствует, а откат к старому writer исключён из release-плана.
