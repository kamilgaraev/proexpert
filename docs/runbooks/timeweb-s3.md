# Runbook: Timeweb Cloud S3 для МОСТ

## Назначение и границы

Этот runbook описывает целевую эксплуатационную конфигурацию файлового хранилища МОСТ в Timeweb Cloud S3, порядок её проверки и границы ответственности. Он не содержит ключей, токенов или других секретов.

В область входят backend МОСТ, один бакет `prohelper-storage`, server-side операции и браузерные multipart-потоки с короткоживущими presigned URL. Holdings, сайты холдингов и CMS в эту миграцию не входят.

## Целевая архитектура

Используется ровно один приватный бакет `prohelper-storage` в endpoint `https://s3.twcstorage.ru`. Приложение обращается к нему только через `App\Services\Storage\FileService`; прикладные сервисы не выбирают бакет и не создают S3-клиент напрямую.

Небольшие загрузки проходят через backend. Для крупных объектов backend после авторизации создаёт сессию и выдаёт браузеру presigned URL только для разрешённых multipart-частей; скачивание также получает короткоживущий presigned URL только после серверной проверки доступа. Timeweb поддерживает presigned URL для загрузки ещё не существующего объекта, а ссылки имеют ограниченный срок действия, задаваемый приложением. [Документация Timeweb: presigned URL](https://timeweb.cloud/docs/s3-storage/supported-features/presigned-url)

Бакет не является CDN, статическим сайтом или публичным файловым доменом. Не создаются второй бакет, прокси-хранилище и резервный путь в Yandex Object Storage.

## Пространство ключей и изоляция

Каждый актуальный ключ обязательно начинается с `org-{organization_id}/` и содержит область актора: `user-{user_id}` для пользовательского действия либо `user-system` для системного действия. Ключ без actor-сегмента не является допустимым:

```text
org-{organization_id}/{domain}/.../user-{user_id}/{object_uuid}.{ext}
org-{organization_id}/{domain}/.../user-system/{object_uuid}.{ext}
```

Примеры действующих доменных путей:

```text
org-{organization_id}/reports/exports/{export_uuid}/user-{user_id}/{object_uuid}.{ext}
org-{organization_id}/personal-files/user-{user_id}/{object_uuid}.{ext}
org-{organization_id}/design-models/{package_uuid}/user-{user_id}/{object_uuid}.{ext}
org-{organization_id}/temporary/{purpose}/user-system/{object_uuid}.{ext}
```

Идентификатор объекта генерируется backend до загрузки; имя, переданное пользователем, не становится частью ключа. Нормализация не допускает выход за организационный префикс. `VersionId` не является частью прикладного контракта: целостность и идентичность фиксируются уникальным ключом, SHA-256, ETag, MIME и размером.

## Конфигурация приложения

Ниже только имена `MOST_S3_*` и безопасные примеры. Значения ключа и секрета передаются в защищённые переменные окружения deployment-платформы, не в Git, журнал или этот документ.

Реализованный контракт и значения по умолчанию подтверждаются [config/filesystems.php](../../config/filesystems.php) и [.env.example](../../.env.example): `ru-1`, `prohelper-storage`, `https://s3.twcstorage.ru`, path-style `true`, TTL скачивания `300` и TTL загрузки `900`. Эти файлы не содержат production-значений ключей.

```dotenv
MOST_S3_ACCESS_KEY_ID=<runtime-access-key-id>
MOST_S3_SECRET_ACCESS_KEY=<runtime-secret-kept-out-of-git>
MOST_S3_REGION=ru-1
MOST_S3_BUCKET=prohelper-storage
MOST_S3_ENDPOINT=https://s3.twcstorage.ru
MOST_S3_USE_PATH_STYLE_ENDPOINT=true
MOST_S3_DOWNLOAD_TTL_SECONDS=300
MOST_S3_UPLOAD_TTL_SECONDS=900
```

Отсутствие обязательной переменной должно останавливать production-конфигурацию, а не переключать её на локальный диск или другой endpoint.

## Внешний checklist Timeweb

Статус отражает только имеющиеся доказательства владельца и подтверждённые deploy-артефакты. Отметка `[ ]` означает, что действие или его итог требует проверки владельцем в панели Timeweb либо через S3 API.

- [x] bucket private — подтверждено evidence владельца.
- [x] versioning enabled — подтверждено evidence владельца.
- [ ] runtime user limited to `prohelper-storage` Read+Write — runtime пока использует временный ключ с широкими правами; требуется отдельный additional user и замена ключа.
- [ ] CORS exact HTTPS origins, `PUT`/`POST`/`GET`/`HEAD`, `ExposeHeaders=ETag`.
- [ ] abort incomplete multipart after 1 day.
- [ ] expire noncurrent versions after 30 days.
- [ ] no current-object expiration.
- [x] no CDN/public domain — CDN не подключён по evidence владельца; публичный доступ не должен включаться.
- [ ] rotate temporary runtime key after acceptance.

### Права runtime-пользователя

В панели Timeweb создайте отдельного additional user и назначьте ему **только** `prohelper-storage` с уровнем «Чтение и запись» (Read+Write). Этот уровень покрывает чтение, загрузку, обновление, удаление и multipart, но не позволяет менять ACL, bucket policy, CORS, versioning или lifecycle. [Документация Timeweb: дополнительные пользователи](https://timeweb.cloud/docs/s3-storage/manage-storage/additional-users)

Не используйте для runtime ключ владельца или ключ с «Управлением». После принятия новой конфигурации выполните ротацию по разделу «Ротация ключа» ниже.

### CORS для browser presigned flows

В Timeweb CORS настраивается в панели либо через S3 API; `Allowed Origins`, методы и доступные браузеру response headers задаются в правиле бакета. [Документация Timeweb: CORS](https://timeweb.cloud/docs/s3-storage/supported-features/cors-setup)

Перед применением владелец должен заполнить правило реальными production HTTPS-origin админки и личного кабинета МОСТ. Не использовать `*`, HTTP-origin, домен бакета или неподтверждённые preview-домены. Согласованный минимум:

```text
AllowedOrigins: точный список утверждённых HTTPS-origin МОСТ
AllowedMethods: PUT, POST, GET, HEAD
ExposeHeaders: ETag
AllowedHeaders: только заголовки, которые фактически подписывает текущий multipart/presigned-клиент, включая Content-Type при его использовании
```

После сохранения проверить preflight и upload/download из каждого утверждённого origin, а затем убедиться, что запрос с неутверждённого origin не получает разрешающих CORS-заголовков. CORS не заменяет серверную авторизацию: браузер получает только уже авторизованный краткоживущий URL.

### Versioning и lifecycle

Versioning остаётся включённым как инфраструктурная страховка; приложение не читает и не хранит S3 `VersionId` как бизнес-идентичность. Управление versioning доступно в Timeweb для бакета. [Документация Timeweb: версионирование](https://timeweb.cloud/docs/s3-storage/supported-features/s3-object-versioning)

Lifecycle требует внешней настройки и отдельной проверки её фактического результата. Документация Timeweb описывает настройку lifecycle в панели и через S3-совместимый интерфейс, но этот runbook не утверждает, что конкретные действия уже применены. [Документация Timeweb: lifecycle](https://timeweb.cloud/docs/s3-storage/supported-features/object-lifecycle)

Владелец должен через панель или S3 API применить и затем прочитать обратно конфигурацию, которая соответствует следующим условиям:

1. Незавершённые multipart upload прерываются через 1 день.
2. Неактуальные версии удаляются через 30 дней.
3. У текущей версии нет правила автоматического истечения срока.

Не добавляйте правило удаления текущих объектов. Отчёты хранятся бессрочно; автоматической очистки отчётов, scheduler-задачи или скрытого retention-процесса нет. Явное пользовательское или административное удаление остаётся единственным бизнес-способом удаления объекта.

### Приватность, публичные политики и CDN

Не включать привязку публичного домена, static website или CDN для `prohelper-storage`. При проверке bucket policy исключить public `Allow` для `Principal: "*"`; политики Timeweb могут управлять доступом к объектам и отдельно поддерживают ограничение транспорта HTTPS. [Документация Timeweb: bucket policies](https://timeweb.cloud/docs/s3-storage/supported-features/bucket-policies)

Яндекс Геокодер не относится к S3-хранилищу и остаётся вне scope этого runbook. Удаление legacy-кода касается только Yandex Object Storage и AI storage code, а не несвязанной геокодер-интеграции.

## Ротация ключа runtime-пользователя

Ротация выполняется владельцем Timeweb после того, как additional user с ограниченным Read+Write доступом создан и проверен.

1. Создать или получить новую пару ключей ограниченного runtime-пользователя; не передавать её в задачи, PR, чат или логи.
2. Обновить только секретное хранилище deployment-платформы значениями `MOST_S3_ACCESS_KEY_ID` и `MOST_S3_SECRET_ACCESS_KEY`.
3. Выполнить штатный deploy и провести прикладной smoke без вывода ключей.
4. Убедиться, что приложение работает новой парой, а пользователь не имеет «Управления» и доступа к другим бакетам.
5. Отозвать временный широкий ключ и зафиксировать в журнале изменений только дату, ответственного и идентификатор ротации без секретов.

Если шаг 3 не проходит, вернуть в secret store предыдущую рабочую пару и расследовать права/CORS/lifecycle отдельно. Это не означает возврат к Yandex Object Storage.

## Что уже сделано в коде и без простоя

- Runtime Tasks 1–6 перевели backend МОСТ на единственный Timeweb S3-диск, `FileService` и организационные неизменяемые ключи.
- Крупные дизайн-модели используют управляемый server-side multipart workflow с presigned URL и проверкой SHA-256.
- Удалены старые S3-диски, legacy storage runtime, Yandex Object Storage/AI storage code, scheduler-очистка и зависимость прикладных контрактов от S3 `VersionId`.
- Старые файловые записи и объекты не переносились по явному решению владельца: реальных пользователей и ценных production-файлов не было. Миграция очищает старые записи, а не переносит объекты.
- Одноразовый destructive reset также удалил бумажные юридические подписи вместе с legacy signature storage state. Это необратимое, подтверждённое владельцем решение начать с нуля; будущие подписи reset не затрагивает.
- Отчёты не имеют автоматического удаления и остаются бессрочными до явного действия пользователя или администратора.

Все перечисленное доставлялось без maintenance-окна: новое хранилище было пустым, двойная запись и перенос данных не требовались.

## Evidence runtime-изменений

| Блок | PR / merge | Deploy |
| --- | --- | --- |
| Единая конфигурация и gateway | PR #234 | `31052701364` |
| Доменные переходы на единый storage | PR #236, #238, #239 | `31053775102`, `31055973102`, `31056538177` |
| Дополнительные доменные переходы | PR #241, #242, #244, #245, #248, #249, #250 | успешные штатные deploy |
| Multipart дизайн-моделей | PR #251 | `31065840675` |
| Удаление legacy runtime | PR #252, merge `10ebf719219c93e82b181076284b63bb14935014` | `31067596388` |
| Удаление S3 `VersionId` из runtime | PR #253, merge `9485294a1` | первоначальный deploy остановился до миграции из-за PDO-разбора JSONB `?&` |
| Hotfix миграции | PR #254, merge `caf3a815d1cf706b0ed3ea86b0bb7d56716726eb` | `31074703010`: успешно применена 1 миграция |

Hotfix PR #254 заменил ровно два вызова `DB::statement` на `DB::unprepared`, чтобы PDO не разбирал JSONB-оператор `?&` как placeholder. Он не возвращает старое хранилище и не меняет целевую архитектуру.

## Финальный статус и evidence

Documentation PR [#255](https://github.com/kamilgaraev/proexpert/pull/255) merged в `main` с SHA `9d8ce9c65903dd20e3287d4ab504c970cbf2093e`. Штатный `.github/workflows/deploy-backend.yml` реагирует на push только в runtime-путях и не включает `docs/**`; поэтому documentation-only merge намеренно не создал deploy run. По требованию владельца manual workflow dispatch не выполнялся.

Production остаётся на последнем успешном Timeweb deploy: SHA `caf3a815d1cf706b0ed3ea86b0bb7d56716726eb`, run `31074703010`. Это безопасный floor release для отката. После merge #255 read-only smoke подтвердил HTTP 200 для `https://api.xn--1-xtbgmf.xn--p1ai/ready` и `https://api.xn--1-xtbgmf.xn--p1ai/up`; последние 2000 строк `storage/logs/laravel.log` дали 0 совпадений по `AccessDenied|PutObject|GetObject|DeleteObject|ListObjectVersions|VersionId|reports:cleanup|personals:cleanup`.

Владелец ранее подтвердил прямые Timeweb S3 Put/Get/Delete/Multipart до runtime-миграции. Это evidence транспорта S3, а не свежий прикладной write-smoke. Новый прикладной Put/Head/Get/Delete smoke не выполнялся, поскольку production-доступ `codex-ro` строго read-only. Его заменять не следует: доказательствами являются уже развёрнутый runtime, ранее пройденные тесты и owner transport check; ручной deploy не запускался.

Миграция завершена. Остаются только внешние действия владельца из checklist: отдельный runtime-пользователь Read+Write только для `prohelper-storage`; точный HTTPS-origin CORS; lifecycle (abort incomplete multipart через 1 день, noncurrent versions через 30 дней, без expiration текущих объектов); ротация временного широкого ключа. Приватность бакета, versioning и отсутствие CDN/public domain подтверждены.

## Deploy, верификация и откат

### Перед штатным deploy

1. Проверить наличие всех `MOST_S3_*` значений в secret store, не печатая их.
2. Убедиться, что бакет остаётся `prohelper-storage`, приватным и без CDN/публичного домена.
3. Не запускать ручной deploy, миграции или DB-команды из этого runbook.

### После merge и штатного deploy

Контроллер релиза выполняет read-only проверку release SHA, health endpoint, S3/queue/scheduler журналов и прикладной Put/Head/Get/Delete smoke в `org-{id}/temporary/smoke/user-system/{object_uuid}`. Smoke выполняется только через авторизованный прикладной API или безопасную release-команду, использует одноразовый ключ и не выводит секреты. Он не должен заменять пользовательский объект или затрагивать рабочие namespaces.

### Откат

После destructive reset допустим только откат на Timeweb-совместимый release МОСТ с `caf3a815d1cf706b0ed3ea86b0bb7d56716726eb` (hotfix PR #254) или более новый, с текущими `MOST_S3_*` и тем же `prohelper-storage`. Запрещены rollback миграции, восстановление старых файловых или signature-записей, Yandex fallback, второй бакет и публичный доступ.

## Риски и контрольные точки

| Риск | Контроль |
| --- | --- |
| Временный широкий ключ | Создать additional user с Read+Write только на `prohelper-storage`, переключить secret store, проверить и отозвать старый ключ. |
| Неверный CORS | Использовать только точные HTTPS-origin, не `*`; проверить preflight, `ETag` и отказ неразрешённого origin. |
| Рост незавершённых multipart и старых версий | Применить/прочитать lifecycle: abort через 1 день, noncurrent expiry через 30 дней, без expiry текущих объектов. |
| Случайная публикация данных | Сохранять бакет приватным, не включать public domain/CDN/static website и проверить bucket policy. |
| Ошибка после deploy | Проверить SHA, health, S3/queue/scheduler и изолированный smoke; откатывать только релиз МОСТ, без возврата в Yandex. |
| Потеря исторических файлов и legacy подписей | Не выполнять миграцию объектов: по согласованному решению уничтожены legacy файлы, записи и бумажные юридические подписи с legacy signature state; решение необратимо, будущие подписи не затронуты. |

## Официальные источники Timeweb

- [Объектное хранилище S3](https://timeweb.cloud/docs/s3-storage)
- [Поддерживаемые возможности S3](https://timeweb.cloud/docs/s3-storage/supported-features)
- [Дополнительные пользователи](https://timeweb.cloud/docs/s3-storage/manage-storage/additional-users)
- [Управление бакетами](https://timeweb.cloud/docs/s3-storage/manage-storage/manage-buckets)
- [Presigned URL](https://timeweb.cloud/docs/s3-storage/supported-features/presigned-url)
- [Версионирование объектов](https://timeweb.cloud/docs/s3-storage/supported-features/s3-object-versioning)
- [Multipart upload](https://timeweb.cloud/docs/s3-storage/supported-features/multipart-upload)
- [CORS](https://timeweb.cloud/docs/s3-storage/supported-features/cors-setup)
- [Lifecycle](https://timeweb.cloud/docs/s3-storage/supported-features/object-lifecycle)
- [Bucket policies](https://timeweb.cloud/docs/s3-storage/supported-features/bucket-policies)
