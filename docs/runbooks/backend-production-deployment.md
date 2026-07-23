# Production-развёртывание backend МОСТ

Единственный штатный путь — `.github/workflows/deploy-backend.yml`. Workflow развёртывает неизменяемый digest образа и сериализуется группой `prod-backend-deploy`.

## Admission-последовательность

1. Сервер получает точный commit и digest образа до остановки трафика.
2. `.github/scripts/atomic-env.sh` создаёт временный файл рядом с `.env`, сохраняет uid/gid, устанавливает `0600` и проверяет атрибуты через `stat`. Перед атомарным rename выполняется `sync -f` временного файла, после rename — `sync -f` каталога. Отсутствие поддерживаемого механизма durability блокирует deployment. EXIT-trap удаляет незавершённый временный файл.
3. `LEGAL_ARCHIVE_AUDIT_WRITER_SECRET` при необходимости создаётся на сервере через `openssl rand -hex 32`. Секрет не пересекает GitHub/SSH boundary и не выводится.
4. Nginx и writer-runtime останавливаются по tracked allowlist `deploy/backend-runtime-allowlist.sh`. Подтверждённые production units: `prohelper-octane.service`, `prohelper-queue.service`, `reverb.service`. Каждая legacy systemd unit до scan получает `systemctl mask --runtime --now`, поэтому параллельный `start` невозможен до перезагрузки или явного снятия маски. Ручной `supervisorctl stop` переводит найденные программы в STOPPED и подавляет их `autorestart`; состояния RUNNING, STARTING и BACKOFF блокируют продолжение. Compose, supervisor, systemd и `/proc` проверяются по command line, cwd и cgroup, включая `php8.2`, `php -d`, relative Artisan, Horizon, Reverb и RoadRunner.
5. После `migrate:safe --force` runtime scan повторяется непосредственно перед каждым `confirm-drain`, Phase B cutover и invariant repair.
6. Первый свежий drain-маркер потребляется Phase B cutover. После cutover создаётся отдельный свежий маркер для repair; повторное использование уже потреблённого маркера невозможно.
7. `immutable-audit:repair-invariants --confirm-repair` требует process-local `LEGAL_ARCHIVE_AUDIT_REPAIR_ENABLED=true`, Phase B, правильный writer secret и свежий внутренний drain-маркер. Самостоятельный запуск без доказанного drain завершается ошибкой.
8. `immutable-audit:writer-readiness` без кеша сравнивает свежий PostgreSQL catalog с versioned canonical descriptors из кода. Только после успеха запускаются backend-сервисы; `/ready`, `/up`, WebSocket и geometry runtime проверяются до запуска nginx.

## Fence и поздний writer

Cutover и repair соблюдают единый порядок блокировок: `immutable_audit_phase_b_index_prep`, затем `immutable_audit_writer_fence`. Индексы подготавливаются до writer-fence. В короткой транзакции после exclusive fence повторно читаются phase, credential binding, marker и TTL, затем берётся `ACCESS EXCLUSIVE` на `immutable_audit_events`.

Каждая production-запись аудита берёт shared transaction lock на тот же `immutable_audit_writer_fence`. Поэтому exclusive fence ожидает завершения начатых writer-транзакций и не допускает новые. `ACCESS EXCLUSIVE` дополнительно закрывает прямой поздний доступ к таблице во время DDL. Host drain уменьшает число ожидающих процессов, а database fence остаётся авторитетной защитой от гонки.

Repair под fence заново устанавливает функции, триггеры и sequence, проверяет exact descriptors и атомарно потребляет тот же marker. Ошибка проверки откатывает DDL и потребление marker вместе.

## Постоянные инварианты

Mutable database baseline отсутствует. Ожидаемые descriptors определены в versioned `ImmutableAuditInvariantDefinitions` и не могут быть изменены записью в БД. Readiness сравнивает напрямую:

- тело, identity arguments, return type, language, volatility, SECURITY INVOKER, точный owner функции и таблицы, равный аутентифицированной роли deployment/readiness, пустой explicit ACL без grantee и grant option, cost, rows, support, exact `search_path`, strict, leakproof, parallel и kind каждой функции;
- нормализованное полное определение, relation, точные schema/OID/identity arguments trigger function, function dependency, row/timing/events type, WHEN, arguments, constraint/deferrability, parent/partition binding, transition tables, enabled и internal каждого trigger;
- data type, START, min/max, increment, cache, cycle и ownership sequence без сравнения текущего значения;
- valid/ready/unique, колонки, predicate и полное определение обоих Phase B индексов.

Canonical repair явно сбрасывает все function attributes и configuration, возвращает таблицу, sequence и функции аутентифицированной deployment-роли, по catalog ACL отзывает с `CASCADE` все явные права и grant option у любых ролей и `PUBLIC`, сохраняя только неявные права владельца, и восстанавливает sequence START metadata. Недостаточные права repair завершают операцию ошибкой; readiness остаётся закрытым. Любой drift закрывает `/ready` с HTTP 503. Устаревшая таблица `immutable_audit_invariant_baselines`, если она осталась в предварительном окружении, удаляется и больше нигде не читается.

## Поведение при ошибке

После начала остановки любой ненулевой код активирует failure trap. Nginx, compose writer-сервисы и tracked host runtimes остаются остановленными. Автоматического отката к старому writer нет. Повторный deployment снова требует свежие runtime scans и отдельные drain-маркеры.

Legacy units штатно не возвращаются после успешного deployment. Если подтверждённое аварийное восстановление действительно требует старый runtime, оператор сначала останавливает новый compose runtime, закрывает nginx, затем явно выполняет `systemctl unmask --runtime prohelper-octane.service prohelper-queue.service reverb.service` и запускает только согласованный набор units. Снятие маски во время admission/cutover запрещено.
