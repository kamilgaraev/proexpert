# Production-развёртывание backend МОСТ

Единственный штатный путь — `.github/workflows/deploy-backend.yml`. Workflow развёртывает неизменяемый digest образа и сериализуется группой `prod-backend-deploy`.

## Admission-последовательность

1. Сервер получает точный commit и digest образа до остановки трафика.
2. Все изменения `.env` выполняет `.github/scripts/atomic-env.sh`: временный файл создаётся рядом с `.env`, получает владельца исходного файла и режим `0600`, после чего атомарно заменяет `.env`. После каждой операции `stat` повторно проверяет mode, uid и gid. EXIT-trap удаляет незавершённый временный файл. Существующий inode никогда не перезаписывается через перенаправление или `cat`.
3. `LEGAL_ARCHIVE_AUDIT_WRITER_SECRET` создаётся на сервере через `openssl rand -hex 32`, если отсутствует. Слабое или повторное значение блокирует deployment. Секрет не пересекает GitHub/SSH boundary, не попадает в аргументы команд и вывод.
4. Nginx и writer-runtime останавливаются по tracked allowlist `deploy/backend-runtime-allowlist.sh`: compose, supervisor и systemd. Затем `/proc` проверяется по command line, cwd и cgroup; оставшийся PHP, Artisan, Octane или RoadRunner-процесс МОСТ блокирует продолжение.
5. One-off контейнер выполняет `migrate:safe --force`. Затем с process-local флагом выполняются drain confirmation и Phase B cutover.
6. Привилегированный one-off `immutable-audit:repair-invariants --confirm-repair` запускается только с process-local `LEGAL_ARCHIVE_AUDIT_REPAIR_ENABLED=true`. Он под advisory lock заново устанавливает канонические функции, триггеры, sequence и индексы, точно проверяет каталоги PostgreSQL и только после этого обновляет baseline криптографических отпечатков.
7. `immutable-audit:writer-readiness` без кеша сравнивает свежие fingerprints с baseline. Только после успеха запускаются backend-сервисы. API проходит `/ready`, отдельно проверяются `/up`, WebSocket и geometry runtime; nginx запускается последним.

## Поведение при ошибке

После начала остановки любой ненулевой код активирует failure trap. Nginx, compose writer-сервисы и tracked host runtimes остаются остановленными, workflow завершается ошибкой. Автоматического отката к старому writer нет. Повторный запуск идемпотентно восстанавливает канонические инварианты до допуска трафика.

## Постоянные инварианты

Readiness проверяет точные catalog fingerprints, а не наличие marker-строк:

- тело, identity arguments, return type, language и volatility allocator-функции;
- writer guard function и точную привязку trigger;
- append-only function и trigger;
- sequence-sync function и trigger;
- тип, increment, cache, cycle и ownership sequence;
- valid/ready/unique, колонки, predicate и полное определение обоих Phase B индексов.

Изменённая функция не может легитимизировать себя обновлением baseline: repair всегда сначала заменяет объект каноническим определением, выполняет точную проверку и лишь затем сохраняет новый fingerprint. Любой последующий drift закрывает `/ready` с HTTP 503.
