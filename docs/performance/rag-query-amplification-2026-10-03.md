# RAG: повторные запросы PostgreSQL, 3 октября 2026

## Граница расследования

Расследование выполнено по исходникам и локальным PostgreSQL-тестам. Production-запросы, изменения инфраструктуры и миграции не выполнялись. Сообщённые владельцем SIGKILL, перезапуски и около 120 000 выполнений compatibility aggregate приняты как факты инцидента. Причина SIGKILL, инициатор и наличие OOM/bloat по исходникам не устанавливаются.

## Основная причина нагрузки

Цепочка `RefreshRagCoverageJob → RagCoverageService::refreshCoverage → buildCoverageSnapshot → reconcileBatch → RagIndexer::matchesSource` повторно проверяла каждый логический source, хотя подготовка expected projection уже выполнялась пакетами по 100.

При включённом registry для каждого текущего source выполнялись:

1. Агрегирование provider/model/dimensions.
2. Чтение содержимого и хешей chunks.
3. Отдельный `COUNT(total)/SUM(compatible)` с `vector_dims(embedding)` — запрос из описания инцидента.

`RagExpectedSourceProjection::stage` и последующий reconciliation отдельно загружали источники и их состояние. `withCount(chunks)` добавлял коррелированные подсчёты. На один проход с S текущими sources и B=ceil(S/100) приходилось около `3S + 3B` чтений, затрагивающих chunks, при включённом registry. Отдельно выполнялись B upsert expected projection и фиксированные запросы статуса/коллекторов.

Без registry этот же сценарий даёт `2S + B` чтений. Воспроизводящий PostgreSQL-тест на 101 source до исправления получил **204 чтения chunks** и упал на лимите 6.

Количество отдельных compatibility aggregate масштабировалось как `sources × полные проходы`. Повторные ревизии кеша, истечение TTL, retry jobs и параллельные refresh одного проекта/типа увеличивали число проходов. Число прочитанных строк дополнительно зависело от chunks каждого source. Например, 4 000 sources × 30 проходов дают 120 000 агрегатов; это объясняющий пример, а не измеренная частота production.

## Другие обнаруженные причины повторной работы

- Scoped refresh проекта/типа обходил существующий lock глобальной projection.
- Маркер queued включал ревизию кеша. Уже устаревшие задания могли начинать новый полный обход. Дубликат задания после завершённого refresh также повторял обход.
- Индексатор записывал каждый новый chunk без vector, затем выполнял отдельный UPDATE embedding по PK: C INSERT + C UPDATE.
- Индексатор повторно читал профиль и совместимость одних chunks в одном проходе. При обновлении только metadata проверка повторялась под блокировкой источника; эта транзакционная перепроверка сохранена.

## Изменение

`RagIndexer::coverageBatch` загружает sources один раз и читает chunks сразу для нескольких source_id. PostgreSQL возвращает `vector_dims(embedding)` вместо самого vector. Загруженные строки используются для выбора профиля, проверки содержимого/хешей, индексов chunks, полноты и совместимости. Projection получает уже рассчитанные identities; повторной подготовки нет.

Логический batch остаётся 100. Внутри него пакеты планируются максимум на 200 физических chunks по полученному chunks_count. Источник с более чем 200 chunks читается отдельно, с прежней границей памяти одного source. Размер источника и конкурентные изменения могут превысить оценку; это не жёсткий лимит всей памяти процесса. Полные vectors при проверке не передаются клиенту. Размер batch embeddings и provider/model/dimensions не меняются.

Индексатор использует одно чтение chunks для профиля, содержимого и совместимости. Вектор включён в параметризованный INSERT через Eloquent forceCreate, который сохраняет события модели и обработку атрибутов. Отдельный UPDATE embedding убран. Массовый raw INSERT вместо событий модели не вводился; запись остаётся по одному INSERT на chunk в существующей транзакции источника.

Все refresh одного scope используют lock, не зависящий от ревизии. Coverage job передаёт свой cache key: устаревшее задание и дубликат с уже известным snapshot обход не выполняют. Проверка повторяется после получения lock, закрывая гонку с завершением другого worker. Неизвестный/неудачный snapshot допускает повторную попытку. Guard ревизии/срока действует также для scoped refresh. Глобальные generation, staged upsert и publication/prune сохраняются.

Область organization/project/part/type/entity остаётся в идентичности source. Несоответствие области collector отклоняется до чтения batch и stage. Кеш состояния живёт только внутри вызова; он не переносится между jobs и транзакциями. Блокировки source, lease tokens, отмена и транзакционная перепроверка metadata не отключаются.

## Проверенные числа SQL

| Сценарий | До | После |
| --- | --- | --- |
| Coverage 101 sources, один chunk каждый, без registry | 204 SELECT, затрагивающих chunks — измерено | 4 — измерено |
| То же с registry | 309 SELECT — расчёт по прежнему коду | 4 — измерено |
| 100 проверок одинакового source в одном batch | До 200/300 чтений без/с registry — расчёт | 2 SELECT — измерено |
| Compatibility `COUNT(total)/SUM(compatible)` в новом пути | Один на текущий source за проход | 0 |
| Новые C chunks | C INSERT + C UPDATE | C INSERT, 0 UPDATE embedding |
| Неизменившийся source при indexing | 1 чтение source + 2/3 чтения chunks без/с registry | 1 чтение source + 1 чтение chunks |

Фиксированные запросы статуса, обращения collectors, usage accounting, lease heartbeat, source UPDATE и transaction BEGIN/COMMIT в эти числа не включены. При нескольких физических chunks на source число пакетов растёт; полнота проверок сохраняется.

## Остальной исследованный код

- `RagIndexer`: indexOrganization/indexEntity, source locks, генерация embeddings, metadata reuse, pruning; compatibility aggregate удалён из обычных проверок. `coverageIdentities` сохраняет пакетный GROUP BY для независимых callers stage.
- `RagExpectedSourceProjection`: batch upsert, actorCounts и integrity proof. Проверки EXISTS chunks с organization/project остаются; новая generation публикуется после полного успешного прохода.
- `RagCoverageService`, `AssistantIndexStatusService`: кеш, ожидаемые/фактические источники, проверки прав и версии proof. Actor-проверки не заменены глобальными счётчиками.
- `RagRetriever`: discovery embedding profiles, семантический/лексический поиск, кеш query embeddings. Не выполняет reindex после каждого поискового chunk.
- `AssistantSourceReferenceGuard`: проверка актуальности references; вызов matchesSource для сущностей без updated_at также больше не выполняет отдельные profile/compatibility aggregates. Несколько кандидатов могут требовать отдельных чтений; постоянный кеш свежести не вводился.
- `RagIndexingCoordinator`, `IndexRagSourceJob`: queued dedup, claim/lease/token, retry/recover, guard heartbeat. Непосредственного пересчёта coverage после каждого UPDATE chunk нет: инвалидирование происходит на уровне run. Heartbeat остаётся на контрольных точках для проверки владения lease.
- `GlobalRagQueue`, `IndexGlobalRagEntityJob`: revision/claim глобальных событий, fanout организаций пакетами по 50, затем обычные entity jobs.
- Scheduler: backfill каждые пять минут, общий limit ≤2, withoutOverlapping; recover каждую минуту, onOneServer/withoutOverlapping. Entity mutations могут породить несколько разных sources, включая summary и родителей смет. Намеренное последующее событие для меняющегося RUNNING source сохранено.
- `OpenAIRagEmbeddingProvider`, registry и mutation bridges: документный embed вызывается для каждого физического chunk; provider retry и job retry могут перемножать внешние попытки. Записанный совместимый source переиспользуется. Профиль/dimensions и правила retry не изменены.
- `InspectEstimateGenerationProductionCommand`: диагностические source/chunk счётчики; команда не запускалась.

## Индексы и физическая стоимость UPDATE

По migrations схема уже предусматривает PK id обеих таблиц; unique `(source_id, chunk_index)` chunks покрывает поиск по ведущему source_id. Sources имеют unique полной scope/entity/part identity и индексы области. Expected sources имеют unique organization/generation/identity и индекс organization/generation/type/project. Внешние ключи chunks к source, organization и project сохраняются.

Индекс `(source_id, embedding_provider, embedding_model)` не обнаружен и не добавлен: в устранённом aggregate эти поля были внутри CASE, а source_id уже покрыт. После изменения читаются все chunks выбранных sources для проверки содержимого и vectors; новый индекс не уменьшает количество SQL и не исключает чтение embedding. Он добавил бы стоимость INSERT и non-HOT UPDATE. Миграций в исправлении нет. Валидность и фактические размеры production-индексов требуют отдельного чтения production-каталога.

Смешанная vector-колонка имеет частичные HNSW expression indexes для 256/1024 dimensions. PK ускоряет поиск строки, но не построение/обновление vector-индекса. Изменение индексируемого embedding создаёт новую версию строки и не удовлетворяет условиям HOT. Отдельный UPDATE после INSERT добавлял лишнюю версию строки и работу обычных индексов; построение HNSW для нового vector требуется и после исправления. См. [PostgreSQL HOT](https://www.postgresql.org/docs/16/storage-hot.html).

Vector занимает `4 × dimensions + 8` байт: 1 032 для 256 и 4 104 для 1024, без остальных атрибутов/индексов. Крупная строка может использовать TOAST; это дополнительная возможная работа при изменении vector. Фактическое хранение зависит от значений и storage/compression. См. [pgvector](https://github.com/pgvector/pgvector#vector-type) и [PostgreSQL TOAST](https://www.postgresql.org/docs/16/storage-toast.html). Bloat, cache misses и доля HNSW в production UPDATE не измерены.

## Проверки

- PostgreSQL запускается только через `tests/Runtime/run-postgres-tests.ps1` в каноническом локальном контуре.
- RagActorCoverageTest: первоначальный набор 14 тестов / 94 assertions прошёл после пакетной правки.
- RagIndexerTest: 14 тестов / 85 assertions, включая binding vector в единственном INSERT, metadata reuse, repair, отмену и повторную индексацию.
- RagEmbeddingCompatibilityTest: 11 тестов / 67 assertions, включая смешанные 256/1024, legacy checksums, смену профиля и поиск с изоляцией организации.
- Новые проверки: 6 тестов / 27 assertions — оба режима registry, повторы source, повреждённое содержимое/профиль/vector, scoped lock и старые/завершённые jobs.
- RagCoverageIdentityTest: 1 pure unit test / 18 assertions; DB заменена mock, реальное SQLite-подключение не используется.
- Larastan по семи изменённым PHP-файлам, php -l, git diff --check.
- Полный AIAssistant-набор не запускался: использованы узкие проверки изменённого поведения; ранее известна посторонняя несовместимость сигнатуры CountingAssistantPermissionChecker при discovery полного набора.

## Что измерить после штатного деплоя

1. Дельты pg_stat_statements за одинаковые интервалы: calls и total_exec_time chunk-запросов, shared_blks_hit/read, temp writes, rows, WAL при наличии. Старый COUNT(total)/SUM(compatible) и отдельный UPDATE embedding из индексатора должны перестать увеличивать calls после смены всех workers на новый код.
2. SELECT chunks на один успешный coverage job относительно sources/chunks: для коротких sources около двух SELECT на batch 100, плюс фиксированная работа. Сравнивать одинаковые объёмы данных.
3. Количество refresh jobs, cache revision churn, duration/p95, retries/failures, scoped overlap, число одновременно работающих RAG workers и общий backend кеша для locks.
4. Индексация: chunks inserted/updated, повторные provider calls для unchanged sources, processed_sources, lease expiry/recovery, queue age/depth. Heartbeat SQL выделить отдельно от chunk SQL.
5. CPU/RSS PostgreSQL и queue workers, активные connections/wait events; kernel/container события OOM/SIGKILL и restart timeline, чтобы установить причину аварии отдельно от query amplification.
6. pg_stat_user_tables для chunks: n_live_tup/n_dead_tup, n_tup_ins/upd/hot_upd, autovacuum timestamps; размеры heap/TOAST/HNSW и валидность индексов. Эти счётчики сами по себе не доказывают bloat; обслуживание БД данным исправлением не выполняется.
7. RAG-семантика: expected/indexed/pending/stale/lag, mixed profile distribution и результаты tenant/project access checks. Не должно быть лишнего reembedding текущих источников или ложного coverage_complete.
