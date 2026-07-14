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
      "Action": [
        "s3:GetObject",
        "s3:GetObjectVersion",
        "s3:PutObject",
        "s3:DeleteObjectVersion"
      ],
      "Resource": "arn:aws:s3:::BUCKET/org-*/estimate-generation/*",
      "Condition": {
        "StringEquals": {"yc:access-key-id": "STATIC_ACCESS_KEY_ID"}
      }
    }
  ]
}
```

В policy нельзя переносить AWS KMS actions/conditions: Yandex KMS авторизуется ролями
`kms.keys.*`, а не полями `kms:ViaService` и `kms:EncryptionContext`. Bucket настраивается
на default encryption ключом `KMS_KEY_ID` и единственным поддерживаемым S3-значением
алгоритма `aws:kms`.

К разрешающим правилам выше добавляются явные запреты незашифрованного транспорта,
публичных ACL и записи без `If-None-Match`:

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
    },
    {
      "Sid": "DenyNonConditionalAiEstimatorWrites",
      "Effect": "Deny",
      "Principal": "*",
      "Action": "s3:PutObject",
      "Resource": "arn:aws:s3:::BUCKET/org-*/estimate-generation/*",
      "Condition": {"Null": {"s3:if-none-match": "true"}}
    }
  ]
}
```

Неизменяемая запись выполняется с `If-None-Match: *`. Удаление требует конкретный
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
      "Filter": {"Prefix": "org-"},
      "AbortIncompleteMultipartUpload": {"DaysAfterInitiation": 1},
      "Expiration": {"Days": 365},
      "NoncurrentVersionExpiration": {"NoncurrentDays": 90}
    },
    {
      "ID": "ai-estimator-expired-delete-markers",
      "Status": "Enabled",
      "Filter": {"Prefix": "org-"},
      "Expiration": {"ExpiredObjectDeleteMarker": true}
    }
  ]
}
```

Все команды AWS CLI выполняются с `--endpoint-url https://storage.yandexcloud.net`.
Конфигурация проверяется командами `aws s3api get-bucket-versioning`,
`get-bucket-encryption`, `get-bucket-cors`, `get-bucket-lifecycle-configuration` и
`get-bucket-policy`. Закрытость публичного доступа проверяется через настройки bucket/ACL
и отрицательный анонимный запрос, а не через неподдерживаемый AWS Block Public Access API.
После проверки выполняются условный повторный put,
чтение точной версии и удаление только этой версии на тестовом объекте организации.

## Миграции

Миграции запускает одноразовый контейнер нового digest до переключения runtime; старые
контейнеры остаются активными. Schema rollback запрещён.

- `000400` работает без внешней транзакции Laravel, использует `lock_timeout=2s`,
  resumable batches по 250 строк с `SKIP LOCKED` и журналирует прогресс. Immutable trigger
  не удаляется; он разрешает только строго ограниченный legacy-переход.
- `000450` только добавляет nullable колонку и `NOT VALID` check с коротким
  `lock_timeout`. Immutable snapshots не обновляются. Канонические hashes переносятся в
  отдельную side table следующей forward migration.
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

Token имеет только `read:packages`, принадлежит `root:root`, mode `0600`. Затем root
запускает из проверенного checkout:

```bash
scripts/install-most-release-coordinator.sh deploy-backend deploy-admin /root/release-coordinator.conf
```

Скрипт устанавливает root-owned executable, проверяет sudoers через `visudo` и создаёт
единый state-каталог. Репозиторий backend доступен deploy-backend только для точного
detached checkout; Docker, `/etc/most`, releases и state доступны только координатору.
Nginx обслуживает admin через `/var/www/admin/current`; exact location `/release.json`
всегда добавляет `Cache-Control: no-store`.

## Релиз и rollback

Backend передаёт координатору полный Git SHA, repository и digest build output. Под
единым `flock` координатор проверяет checkout, OCI revision, RepoDigest и текущий
предыдущий digest/SHA; при недоказуемом предыдущем runtime переключение запрещено.
После additive migration удаляется прежняя attestation, активируются сервисы, затем
проверяются running/healthy состояния, `/up`, публичный no-store release SHA и digest
каждого контейнера.

Admin передаёт архив с SHA-256 из CI в уникальный staging
`<sha>-<run_id>-<run_attempt>`. Root копирует архив в закрытый sealing-каталог, проверяет
digest, отклоняет symlink/FIFO/device/socket и path escape, после чего публикует
root-owned immutable `releases/<sha>` и атомарно меняет `current`.

Только после повторной публичной проверки обоих компонентов атомарно публикуется
`/var/lib/most-active-release/smoke-ready.manifest`. При ошибке возвращается предыдущий
digest/release, он заново проходит health и public verification; schema rollback не
выполняется.
