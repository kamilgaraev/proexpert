# Production readiness AI-сметчика МОСТ

## Хранилище S3

AI-сметчик использует только приватные объекты с серверным шифрованием и включённым versioning:

- `org-*/estimate-generation/sessions/`
- `org-*/estimate-generation/sessions/*/vision/v1/`
- `org-*/estimate-generation/benchmarks/`
- `org-*/estimate-generation/benchmark-imports/`

IAM разрешает приложению `GetObject`, `HeadObject`, `PutObject`, `DeleteObjectVersion`,
`GetObjectVersion` и `ListBucketVersions` только для этих префиксов. Запись нового
неизменяемого объекта выполняется условно с `If-None-Match: *`; удаление всегда требует
конкретный `versionId`. Публичные ACL запрещены политикой бакета. Включаются SSE-KMS или
SSE-S3, Block Public Access и журналирование обращений.

CORS бакета разрешает только домены МОСТ, методы `GET` и `HEAD`, заголовок `Range`,
а в exposed headers — `ETag`, `Content-Length`, `Content-Range`, `x-amz-version-id`.
Lifecycle удаляет незавершённые multipart-загрузки через 1 день; сроки хранения текущих
и старых версий задаются отдельно для sessions, benchmarks и benchmark-imports после
согласования требований к аудиту. Delete markers не заменяют удаление конкретной версии.

## Очереди и переменные окружения

На production задаются все `ESTIMATE_GENERATION_*` из `.env.example`. Секреты API и S3
не фиксируются в репозитории. Для benchmark используется отдельное соединение очереди:
`REDIS_ESTIMATE_GENERATION_BENCHMARK_QUEUE_CONNECTION`, очередь
`REDIS_ESTIMATE_GENERATION_BENCHMARK_QUEUE`, `retry_after=4200`, один процесс. Таймаут
job — 3600 секунд, supervisor — 3700 секунд. Соблюдается порядок
`job timeout < supervisor timeout < retry_after`.

## Миграции

Миграции запускаются одноразовым контейнером нового полного SHA до переключения runtime.
Старые контейнеры в этот момент продолжают обслуживать запросы. Схема меняется только
вперёд; автоматический reverse rollback запрещён.

- `000400` добавляет nullable/DEFAULT-поля и NOT VALID ограничения. Перенос существующих
  записей необходимо выполнять небольшими батчами с контролем `lock_timeout` и метрик.
- `000450` добавляет nullable hash. Заполнение выполняется батчами без длительного table
  lock; `VALIDATE CONSTRAINT` и `SET NOT NULL` допускаются только после проверки нулевого
  остатка и профиля блокировок на staging.
- Остальные новые миграции проходят статическую проверку на `NOT VALID`, отсутствие
  удаления/переименования используемых колонок и совместимость со старым runtime.

Перед production: staging-копия объёма production, замер длительности каждого батча,
`pg_locks`, replica lag и возможность остановить backfill. При ошибке runtime возвращается
на предыдущий immutable image; база остаётся на расширенной совместимой схеме и
исправляется следующей миграцией.

## Координатор релиза

Backend один раз устанавливает root-owned `/usr/local/libexec/most/coordinate-most-release`.
Файл `/etc/most/release-coordinator.conf` принадлежит `root:root` и имеет режим `0600`:

```bash
BACKEND_ROOT=/var/www/prohelper
BACKEND_RELEASE_URL=https://api.example.test/release.json
ADMIN_ROOT=/var/www/admin
ADMIN_STAGING_ROOT=/var/www/admin/incoming
ADMIN_RELEASE_URL=https://app.example.test/release.json
```

Инфраструктурный владелец заранее создаёт `ADMIN_ROOT/releases` как `root:root 0755`,
а `ADMIN_STAGING_ROOT` — как каталог загрузки deploy-пользователя. Координатор проверяет
staging, меняет владельца на root, снимает право записи и только затем переносит каталог
в `releases`. Nginx обслуживает admin через `ADMIN_ROOT/current` и для точного location
`/release.json` добавляет `Cache-Control: no-store`. Backend отдаёт тот же заголовок
маршрутом приложения. Каталоги admin `releases/<полный-sha>` неизменяемы; `current`
переключается атомарно. Пользователю admin deploy разрешается через sudoers только:

```text
deploy-admin ALL=(root) NOPASSWD: /usr/local/libexec/most/coordinate-most-release admin [0-9a-f]*
```

Перед активацией координатор под единым `flock` удаляет прежний pair manifest и
attestation компонента. После публичной проверки точного полного SHA публикуется новый
`/var/lib/most-active-release/smoke-ready.manifest`. При неуспехе активируется предыдущий
artifact, он заново проверяется, и только затем может быть опубликована проверенная пара.
