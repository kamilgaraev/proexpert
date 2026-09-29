# Изолированная HTTP-приёмка BIM на устройстве

Контур запускается только каноническим PostgreSQL launcher и одним тестовым файлом. По умолчанию тест пропускается до `RefreshDatabase`, без запуска миграций. При включении fixture фиксируется в уникальной базе `most_phpunit_<random>_testing` на `127.0.0.1:55433`; стандартный bootstrap удаляет базу после завершения PHPUnit.

```powershell
$env:MOST_BIM_DEVICE_ACCEPTANCE = '1'
try {
    ./tests/Runtime/run-postgres-tests.ps1 -TestPath tests/Feature/DesignManagement/DesignBimDeviceAcceptanceHarnessTest.php
} finally {
    Remove-Item Env:MOST_BIM_DEVICE_ACCEPTANCE -ErrorAction SilentlyContinue
}
```

Тест поднимает один PHP HTTP процесс для native, четыре PHP HTTP обработчика admin за локальным Node HTTP шлюзом, Reverb на случайном loopback-порту и отдельный Redis Docker container с `--rm`, без persistence. Все пять HTTP обработчиков используют ту же уникальную PostgreSQL базу, Redis и guarded API. Максимальное ожидание устройства — 90 минут после `ready`; test-only JWT TTL — 100 минут. Descriptor, signed config/control/file URLs и child processes ограничены тем же сроком. PHPUnit проверяет все семь дочерних процессов: пять PHP HTTP обработчиков, Node шлюз и Reverb; останавливает их, удаляет Redis и свою временную директорию в `finally`. Код launcher, bootstrap, production-конфигурация, guards и API не меняются.

Шлюз принимает только admin API и health на loopback. Он направляет до четырёх одновременных запросов свободным PHP обработчикам, передаёт настоящие status, headers, cookies и body потоком, без повторного POST. Auth, middleware и API проходят обычный Laravel HTTP kernel. Ограниченные очередь и таймауты предотвращают бесконечное ожидание. Узкая проверка транспорта `node --test tests/Runtime/bim-device-acceptance/gateway.test.mjs` покрывает binary streaming, несколько Set-Cookie, четыре параллельных обработчика и отсутствие повтора POST; она не обращается к БД. Настоящая приёмка API выполняется каноническим тестом.

Все пять HTTP процессов используют установленную рядом с PHP библиотеку `ext/php_opcache.dll` через process-only `-d zend_extension`, `opcache.enable_cli=1`, `opcache.memory_consumption=256`, `opcache.max_accelerated_files=32531`. Перед запуском выполняется ограниченный probe загрузки и включения OPcache. Системный `php.ini` и Reverb процесс не меняются. Без библиотеки opt-in тест завершается с явной ошибкой. `http-timings.jsonl` в private TEMP содержит только метод, API path без query, статус, порт, наличие OPcache и длительности autoload/bootstrap/kernel/terminate; журнал ограничен 1 MiB. Токены, cookies, signed URL и тела запросов туда не записываются.

HTTP приложение получает настоящий captured request и использует штатный `HttpKernel::bootstrap()` перед настройкой тестового диска. Затем тот же request проходит полный HTTP kernel и обычные middleware. Console command discovery при HTTP запросах не выполняется; Reverb bootstrap и fixture application остаются отдельными.

В `bootstrap_profile` сохраняются длительности штатных HTTP bootstrappers, десяти самых медленных providers и суммарное время/количество запросов БД во время bootstrap. SQL, параметры и данные не записываются. Профиль позволяет отличить CPU-нагрузку подготовки моделей от блокировки provider или БД; он не меняет последовательность bootstrap и guards.

`ready` содержит только порты, IDs, длины токенов и пути к временным файлам. JWT, пароли пользователей, ключи и signed config URL доступны только в TEMP `descriptor.json` и `flutter-defines.json`; не печатайте их в логах. Flutter получает `BIM_API_CONFIG_URL` через `--dart-define-from-file` и читает JWT нативным Dart-кодом. JWT не передаются в WebView.

Перед запуском Android-приёмки настройте `adb reverse` для обоих HTTP портов и Reverb порта из `ready`. Сам harness не запускает ADB. Native API и signed assets используют `descriptor.base_url`/`http_port`; административные API, Vite env и browser helper — `descriptor.admin_base_url`/`admin_http_port` (`admin_endpoint` в `ready`). Четыре внутренние admin порта доступны в `admin_worker_ports`; для устройства достаточно gateway порта. Клиенты старого descriptor могут использовать `admin_base_url ?? base_url`. Админка использует только `descriptor.ui_origin`, равный `http://127.0.0.1:31391`; реальный `/auth/login` принимает `admin_email` и `admin_password` из private descriptor. Порты предыдущих контуров 2014/2016/2018/2019 и 11097/11103/11107/11108 исключены из нового распределения, чтобы старые браузерные вкладки и устройства не обращались к новой базе. Secure cookie остаётся стандартной: политика браузера на HTTP loopback требует отдельной проверки.

`descriptor.device_config` предоставляет `BIM_API_BASE_URL`, `BIM_API_ADMIN_BASE_URL`, `BIM_API_TOKEN`, `BIM_API_ADMIN_TOKEN`, `BIM_API_USER_ID`, `BIM_API_ADMIN_USER_ID`, `BIM_API_ORGANIZATION_ID`, `BIM_API_PROJECT_ID`, `BIM_API_VERSION_ID`, `BIM_API_SECOND_VERSION_ID`, `BIM_API_SET_ID`, `BIM_API_SET_REVISION`, `BIM_API_MODEL_SET_REVISION_ID`, `BIM_API_SESSION_ID`, `BIM_API_SESSION_NEXT_ID`, `BIM_API_EXPRESS_ID`, `BIM_API_WALL_EXPRESS_ID`, `BIM_API_STOP_FILE`, `BIM_API_CONTROL_URL`. ID представлены строками. Два пользователя имеют реальные роли, проектные назначения и оплаченный пакет доступа. Комплект содержит два самостоятельных артефакта «Корпус А/Б», по одной IFC-версии в каждом, с одинаковым corpus на отдельных storage paths/generations. Точная редакция набора включает обе версии; корпус Б смещён на `[25,0,0]` и повёрнут на 15 градусов. `descriptor.version_ids` перечисляет обе версии.

Подписанный `BIM_API_CONTROL_URL` служит только координации внешней приёмки. GET возвращает `{phases:{},commands:[]}`. POST `{actor:"mobile"|"admin",phase:"joined",...details}` сохраняет состояние фазы; POST `{commands:[{type:"leader_ready",...}]}` заменяет очередь команд. Тело ограничено 16 KiB, список — 100 командами; состояние хранится в отдельном TEMP-файле под файловой блокировкой. Endpoint не создаёт JWT, не вызывает доменные операции и не меняет production API. Действия зрителей выполняются настоящими клиентами через штатные endpoints.

После полного API-сценария устройство публикует `mobile.apiComplete`: `issue_id`, `client_operation_id` (настоящий `Idempotency-Key` двух create attempts), `snapshot_sha256`, `photo_sha256` и `checks` со значениями `actual_api_sync_and_admin_visibility`, `lost_create_ack_idempotency_no_duplicate`, `attachment_bytes_verified`, равными `true` после соответствующих assertions.

Перед новым сценарием пары после подтверждённого API допустим единственный signed control POST `{reset_pair:"<UUID>"}`. Ответ — raw state с `run_id`, `pair_started_at`, пустыми `phases.admin`, `commands` и сохранённым `phases.mobile.apiComplete`. Повтор того же UUID не очищает новые фазы. Другой UUID очищает только coordination state; HTTP auth, users, issues, files, session participants/view-state и БД не изменяются. Без ранее опубликованного `apiComplete` reset возвращает 409.

После смены сессии и штатного выхода из обеих сессий клиенты публикуют `mobile.complete` и `admin.complete`: `client_id`, исходный `session_id`, `second_session_id`, `model_set_revision_id`, `version_ids`, `left_session_ids`, `received_types` настоящих входящих realtime событий и `checks`. ID и массивы ID в этих reports — числа. Для mobile обязательны `native_auth_bridge`, `jwt_not_in_js`, `complete_full_follow`, `own_unfollow_preserved`, `model_version_selection_deduplicated`, `late_join_snapshot`, `reconnect_and_session_change`, `left_sessions_cleaned`. Для admin обязательны `real_auth`, `real_workspace`, `echo_presence`, `named_cursor`, `selection_during_camera_burst`, `full_follow`, `own_unfollow_preserved`, `model_version_selection_deduplicated`, `late_join_snapshot`, `reconnect`, `next_session_cleanup`, `participants_absent`, `view_states_absent`. Admin дополнительно передаёт `report` с `run_id`, всеми восемью успешно завершёнными `phase_names`, `evidence_count`; подробные внешние отчёты сохраняются средствами приёмки. Эти фазы нельзя публиковать до завершения assertions и cleanup.

Геометрия и полный NDJSON получены одной командой текущего IFC converter из `tests/Fixtures/DesignManagement/thatopen-example.ifc`. Проверка `node tests/Runtime/bim-device-acceptance/verify-fixture.mjs` сверяет все 120 express ID через настоящий `SingleThreadedFragmentsModel`, GlobalId через `web-ifc` и геометрические индексы через FRAG API. Beam: 2863; wall: 12954. `fragment-identity.json` фиксирует SHA-256 исходного IFC, геометрии и NDJSON.

Хранилище — только временный локальный диск с `org-<id>/` prefix и подписанными whitelist URL. Настоящий `FileService` выполняет проверку bytes/SHA-256 через тестовый транспортный адаптер `LocalAcceptanceDisk` для `HeadObject/GetObject`. S3 provider/network не входят в эту приёмку; поведение issue/session/API и проверка файлов остаются настоящими.

После приёмки атомарно запишите JSON в `stop_file`:

```json
{
  "status": "complete",
  "issue_id": 1,
  "client_operation_id": "<настоящий Idempotency-Key создания>",
  "snapshot_sha256": "<sha256 реального PNG>",
  "photo_sha256": "<sha256 реально загруженного фото>",
  "mobile_client_id": "<мобильный client_id>",
  "admin_client_id": "<административный client_id>"
}
```

Тест проверяет ровно одно мобильное замечание и одну create idempotency row с тем же автором, ключом и resource ID; замечания административного пользователя для проверки полного вида сохраняются. Дополнительно проверяются точная IFC-версия и express ID, сохранённая камера, отсутствие дублей фото, SHA-256 и dimensions настоящих изображений, PNG snapshot, одинаковый issue/revision через mobile/admin HTTP. Финальная административная проверка получает JWT настоящим `/auth/login`; несколько предварительных browser logins могут штатно отозвать ранние сессии из-за лимита устройств. Signed reports должны подтвердить реальные viewer/realtime assertions до выхода. После выхода parent независимо проверяет отсутствие обоих клиентов в participants и их view-state в обеих сессиях. Harness сам не имитирует действия устройства или браузера. Для штатной отмены запишите `{"status":"cancelled","reason":"<причина частичного результата>"}`: PHPUnit завершится неуспешно и очистит контур.
