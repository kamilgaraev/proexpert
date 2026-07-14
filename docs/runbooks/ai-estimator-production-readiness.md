# Production readiness AI-сметчика МОСТ

## Обязательные переменные production

Production `.env` должен содержать все ключи `ESTIMATE_GENERATION_*` и
`REDIS_ESTIMATE_GENERATION_*` из `.env.example`, а также `TIMEWEB_AI_API_KEY`,
`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`,
`AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT`, `MOST_IMAGE_REF` и `MOST_RELEASE_SHA`.
Пустыми допускаются только необязательные тарифные значения и acceptance manifest.
Секреты хранятся вне Git. Перед релизом сравниваются именно имена ключей:

```bash
comm -23 \
  <(grep -E '^(ESTIMATE_GENERATION_|REDIS_ESTIMATE_GENERATION_|TIMEWEB_AI_API_KEY|AWS_(ACCESS_KEY_ID|SECRET_ACCESS_KEY|DEFAULT_REGION|BUCKET|ENDPOINT|USE_PATH_STYLE_ENDPOINT))' .env.example | cut -d= -f1 | sort -u) \
  <(cut -d= -f1 .env | sort -u)
```

Команда не должна выводить строки. Для benchmark используются отдельная очередь,
`retry_after=4200`, один процесс, job timeout 3600 и supervisor timeout 3700:
`job timeout < supervisor timeout < retry_after`.

## Yandex Object Storage: IAM и KMS

Bucket имеет включённые versioning, закрытый публичный доступ, default SSE-KMS и access logs.
Разрешённый object scope ограничен префиксами:

- `org-*/estimate-generation/sessions/`
- `org-*/estimate-generation/sessions/*/vision/v1/`
- `org-*/estimate-generation/benchmarks/`
- `org-*/estimate-generation/benchmark-imports/`
- `org-*/estimate-generation/training-datasets/`

`BUCKET`, `SERVICE_ACCOUNT_ID`, `STATIC_ACCESS_KEY_ID`, `FOLDER_ID` и `KMS_KEY_ID`
заменяются фактическими значениями. Для runtime используется отдельный service account. Ему
назначается bucket-level роль `storage.editor`, а на KMS key — роль
`kms.keys.encrypterDecrypter`. Folder-level роли для runtime не используются:

```bash
yc storage bucket add-access-binding BUCKET \
  --role storage.editor \
  --subject serviceAccount:SERVICE_ACCOUNT_ID

yc kms symmetric-key add-access-binding KMS_KEY_ID \
  --role kms.keys.encrypterDecrypter \
  --subject serviceAccount:SERVICE_ACCOUNT_ID
```

Bucket policy дополнительно сужает data-plane до ключа runtime и AI-префиксов. Отдельного
policy action для `HeadObject` нет: этот запрос разрешается действием `s3:GetObject`.

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "ListOnlyAiEstimatorVersions",
      "Effect": "Allow",
      "Principal": {"CanonicalUser": "SERVICE_ACCOUNT_ID"},
      "Action": ["s3:ListBucketVersions"],
      "Resource": "arn:aws:s3:::BUCKET",
      "Condition": {
        "StringLike": {"s3:prefix": ["org-*/estimate-generation/*"]},
        "StringEquals": {"yc:access-key-id": "STATIC_ACCESS_KEY_ID"}
      }
    },
    {
      "Sid": "AiEstimatorVersionedObjects",
      "Effect": "Allow",
      "Principal": {"CanonicalUser": "SERVICE_ACCOUNT_ID"},
      "Action": "*",
      "Resource": "arn:aws:s3:::BUCKET/org-*/estimate-generation/*",
      "Condition": {
        "StringEquals": {"yc:access-key-id": "STATIC_ACCESS_KEY_ID"}
      }
    }
  ]
}
```

Для object resource используется документированный wildcard, потому что Yandex Object Storage
поддерживает `PutObjectTagging`, но не публикует отдельный bucket-policy action для этого метода.
Wildcard ограничен одновременно service account, конкретным static key и AI-префиксом. IAM остаётся
bucket-scoped; KMS-доступ не расширяется. Каждый успешно созданный объект под
`org-<id>/estimate-generation/` приложение маркирует на точной `versionId` тегом
`most-module=estimate-generation`. При ошибке маркировки запись fail-closed; для immutable write с
доказанной созданной `versionId` приложение пытается удалить только эту версию. Версия, полученная
после обычного adapter write, не удаляется без доказательства владения. Обычные пути других модулей
не маркируются.

В policy нельзя переносить AWS KMS actions/conditions: Yandex KMS авторизуется ролями
`kms.keys.*`, а не полями `kms:ViaService` и `kms:EncryptionContext`. Bucket настраивается
на default encryption ключом `KMS_KEY_ID` и единственным поддерживаемым S3-значением
алгоритма `aws:kms`.

К разрешающим правилам выше добавляются явные запреты незашифрованного транспорта и публичных ACL:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "DenyInsecureTransport",
      "Effect": "Deny",
      "Principal": "*",
      "Action": "s3:*",
      "Resource": ["arn:aws:s3:::BUCKET", "arn:aws:s3:::BUCKET/*"],
      "Condition": {"Bool": {"aws:SecureTransport": "false"}}
    },
    {
      "Sid": "DenyPublicAcl",
      "Effect": "Deny",
      "Principal": "*",
      "Action": ["s3:PutObject", "s3:PutObjectAcl"],
      "Resource": "arn:aws:s3:::BUCKET/*",
      "Condition": {
        "StringEqualsIfExists": {
          "s3:x-amz-acl": ["public-read", "public-read-write", "authenticated-read"]
        }
      }
    }
  ]
}
```

Неизменяемые benchmark/import записи выполняются с `If-None-Match: *`. Удаление требует конкретный
`versionId`; delete marker не считается удалением версии.

## Yandex Object Storage: CORS и lifecycle

```json
[
  {
    "AllowedOrigins": [
      "https://xn--1-xtbgmf.xn--p1ai",
      "https://lk.xn--1-xtbgmf.xn--p1ai"
    ],
    "AllowedMethods": ["GET", "HEAD"],
    "AllowedHeaders": ["Range"],
    "ExposeHeaders": ["ETag", "Content-Length", "Content-Range", "x-amz-version-id"],
    "MaxAgeSeconds": 300
  }
]
```

```json
{
  "Rules": [
    {
      "ID": "ai-estimator-version-retention",
      "Status": "Enabled",
      "Filter": {
        "Tag": {"Key": "most-module", "Value": "estimate-generation"}
      },
      "Expiration": {"Days": 365},
      "NoncurrentVersionExpiration": {"NoncurrentDays": 90}
    }
  ]
}
```

Ни одно lifecycle-правило не использует общий `org-` как фильтр удаления. Незавершённые multipart
uploads не удаляются широким prefix-rule: они контролируются отдельной метрикой и точечной
операционной очисткой только после доказательства AI scope.

Все команды AWS CLI выполняются с `--endpoint-url https://storage.yandexcloud.net`.
Конфигурация проверяется командами `aws s3api get-bucket-versioning`,
`get-bucket-encryption`, `get-bucket-cors`, `get-bucket-lifecycle-configuration` и
`get-bucket-policy`. Закрытость публичного доступа проверяется через настройки bucket/ACL
и отрицательный анонимный запрос, а не через неподдерживаемый AWS Block Public Access API.
После проверки выполняются условный повторный put, `get-object-tagging` с проверкой
`most-module=estimate-generation`, чтение точной версии и удаление только этой версии на тестовом
объекте организации. Контрольный объект вне AI-префикса обязан оставаться без этого тега.

## Миграции

Миграции запускает одноразовый контейнер нового digest до переключения runtime; старые
контейнеры остаются активными. Schema rollback запрещён.

- `000400` работает без внешней транзакции Laravel, использует `lock_timeout=2s`,
  resumable batches по 250 строк с `SKIP LOCKED` и журналирует прогресс. Immutable trigger
  не удаляется; он разрешает только строго ограниченный legacy-переход.
- `000450` только добавляет nullable колонку и `NOT VALID` check с коротким
  `lock_timeout`. Immutable snapshots не обновляются. Канонические hashes переносятся в
  отдельную side table следующей forward migration.
- `000950_canonicalize_settings_snapshot_hashes` — сохранённый compatibility marker. Он намеренно
  ничего не изменяет, чтобы fresh install не запускал прежний небезопасный immutable backfill.
- `001125_create_canonical_settings_snapshot_hashes` создаёт side table и заполняет её resumable
  batches без обновления immutable settings snapshots.
- `001150_enforce_exactly_once_ai_budget_wire_claims` вводит финальные exactly-once guards для
  budget wire claims и входит в rollout после `001125`.
- `VALIDATE CONSTRAINT` и усиление nullable выполняются отдельной короткой forward
  migration только после нулевого остатка, проверки `pg_locks`, replica lag и rehearsal
  на staging-копии production-объёма.

При runtime-ошибке координатор возвращает предыдущие image digest и сервисы. Схема
остаётся расширенной и исправляется только новой forward migration.

## Одноразовый bootstrap координатора

Backend deploy-user и admin deploy-user не имеют root SSH и не входят в группу Docker.
Root вне CI создаёт `/etc/most/release-coordinator.conf` с mode `0600`:

```bash
BACKEND_ROOT=/var/www/prohelper
BACKEND_SERVICES='api websockets horizon worker-heavy worker-ifc scheduler'
BACKEND_RELEASE_URL=https://api.xn--1-xtbgmf.xn--p1ai/release.json
ADMIN_ROOT=/var/www/admin
ADMIN_STAGING_ROOT=/var/www/admin/incoming
ADMIN_RELEASE_URL=https://lk.xn--1-xtbgmf.xn--p1ai/release.json
GHCR_USERNAME=most-production-pull
GHCR_TOKEN_FILE=/etc/most/ghcr-token
```

Token имеет только `read:packages`, принадлежит `root:root`, mode `0600`. Coordinator обновляется
только как отдельная out-of-band root-операция. Сначала оператор фиксирует заранее reviewed Git SHA,
сверяет checkout и вычисляет digest ровно этого файла; digest сохраняется одинаковым секретом
`RELEASE_COORDINATOR_SHA256` в backend и admin CI:

```bash
REVIEWED_COORDINATOR_GIT_SHA=<reviewed-full-git-sha>
git fetch origin "$REVIEWED_COORDINATOR_GIT_SHA"
git checkout --detach "$REVIEWED_COORDINATOR_GIT_SHA"
test "$(git rev-parse HEAD)" = "$REVIEWED_COORDINATOR_GIT_SHA"
EXPECTED_COORDINATOR_SHA256=$(sha256sum scripts/coordinate-most-release.sh | awk '{print $1}')
test "${#EXPECTED_COORDINATOR_SHA256}" -eq 64

sudo scripts/install-most-release-coordinator.sh \
  deploy-backend deploy-admin /root/release-coordinator.conf \
  "$EXPECTED_COORDINATOR_SHA256"
test "$(sha256sum /usr/local/libexec/most/coordinate-most-release | awk '{print $1}')" \
  = "$EXPECTED_COORDINATOR_SHA256"
test "$(/usr/local/libexec/most/coordinate-most-release --version)" \
  = 'most-release-coordinator/v2'

sudo /usr/local/libexec/most/coordinate-most-release bootstrap-backend
```

Скрипт устанавливает root-owned executable, проверяет sudoers через `visudo` и создаёт
единый state-каталог. `bootstrap-backend` fail-closed сверяет текущие container digest/OCI SHA,
Git SHA и сохраняет соответствующий compose в root-owned immutable state. До успешного bootstrap
первый CI deploy запрещён. Репозиторий backend доступен deploy-backend только для точного
detached checkout; Docker, `/etc/most`, releases и state доступны только координатору.
Nginx обслуживает admin через `/var/www/admin/current`; exact location `/release.json`
всегда добавляет `Cache-Control: no-store`.

## Релиз и rollback

Backend передаёт координатору полный Git SHA, repository и digest build output. Под
единым `flock` координатор проверяет checkout, OCI revision, RepoDigest и текущий
предыдущий digest/SHA, а также наличие root-owned sealed compose именно предыдущего SHA; при
недоказуемом предыдущем runtime или compose переключение запрещено.
После additive migration удаляется прежняя attestation, активируются сервисы, затем
проверяются running/healthy состояния, `/up`, публичный no-store release SHA и digest
каждого контейнера.

Admin передаёт архив с SHA-256 из CI в уникальный staging
`<sha>-<run_id>-<run_attempt>`. Root копирует архив в закрытый sealing-каталог, проверяет
digest, отклоняет symlink/FIFO/device/socket и path escape, после чего публикует
root-owned immutable `releases/<sha>` и атомарно меняет `current`.

Только после повторной публичной проверки обоих компонентов атомарно публикуется
`/var/lib/most-active-release/smoke-ready.manifest`. При ошибке возвращаются предыдущие digest, SHA
и его собственный sealed compose; они заново проходят health и public verification. Новый/global
compose для rollback не используется; schema rollback не выполняется.
