# OnlyOffice для МОСТ

Эта папка поднимает OnlyOffice Docs отдельным Docker Compose-стеком на том же сервере, что и МОСТ. Порт редактора доступен только локально; внешний HTTPS-доступ даёт Nginx.

## Подготовка сервера

1. Создайте DNS-запись `office.<ваш-домен>` на IP production-сервера.
2. Скопируйте `.env.example` в `.env` и задайте домен и новый JWT-секрет длиной не менее 32 символов. Файл `.env` не коммитить.
   Укажите `ONLYOFFICE_CALLBACK_HOST=api.<ваш-домен>`: контейнер редактора будет обращаться к API через Docker host gateway, сохраняя HTTPS-имя API и не требуя внешнего порта.
3. Замените `office.example.ru` в `nginx.conf.example` на реальный домен, установите конфигурацию Nginx и выпустите TLS-сертификат для этого домена.
4. В этой папке выполните `docker compose up -d`.
5. Убедитесь, что `curl -fsS https://office.<ваш-домен>/healthcheck` возвращает `true`.

## Связь с МОСТ

В production-окружении backend добавьте:

```env
LEGAL_DOCUMENT_EDITOR_ENABLED=true
LEGAL_DOCUMENT_EDITOR_DRIVER=onlyoffice
LEGAL_DOCUMENT_EDITOR_URL=https://office.<ваш-домен>
LEGAL_DOCUMENT_EDITOR_JWT_SECRET=<тот же ONLYOFFICE_JWT_SECRET>
LEGAL_DOCUMENT_EDITOR_CALLBACK_BASE_URL=https://api.<ваш-домен>
LEGAL_DOCUMENT_EDITOR_DOWNLOAD_ALLOWED_HOSTS=office.<ваш-домен>
```

Затем выполните обычный backend-deploy из `main`. Не передавайте JWT в браузерные логи, git или переписку.

## Проверка

### Скачивание исходного файла из S3

`storage-auth.json` подключён только для чтения как `/etc/onlyoffice/documentserver/local-production-linux.json`. Для процессов редактора с `NODE_ENV=production-linux` этот файл дополняет `local.json`, сохраняя JWT-секрет и настройки входящих и исходящих запросов.

Исключение `services.CoAuthoring.token.outbox.urlExclusionRegex` относится только к адресам файлов в `https://s3.twcstorage.ru/prohelper-storage/org-`. Ссылки уже подписаны S3; дополнительный заголовок `Authorization: Bearer ...` вызывает ответ хранилища `400 InvalidArgument`. Запросы к API МОСТ должны по-прежнему передавать JWT.

В исходниках ONLYOFFICE 8.3 параметр `urlExclusionRegex` обрабатывается через `escape-string-regexp`: значение ищется как буквальная подстрока URL. Не добавляйте `^`, экранирование точек или другие конструкции регулярных выражений. Это не список разрешённых сетевых адресов: ссылки на файлы и адрес обратного вызова формирует backend МОСТ. При смене хранилища или версии редактора повторно проверьте адрес исключения и фактическое поведение.

После доставки этих файлов на сервер примените изменение из `deploy/onlyoffice`:

```sh
docker compose -p onlyoffice -f docker-compose.yml config --quiet
docker compose -p onlyoffice -f docker-compose.yml up -d --no-deps onlyoffice
```

Пересоздаётся только редактор; его тома сохраняются. Перед применением закройте сеансы редактирования. Не используйте `down -v`. Проверьте состояние контейнера, затем откройте документ в МОСТ и подтвердите загрузку, редактирование и сохранение новой версии. Один успешный `/healthcheck` не доказывает исправление скачивания из S3.

В МОСТ откройте карточку договора без текущей версии и нажмите «Создать в редакторе». Должен открыться DOCX-черновик. После сохранения закройте редактор, обновите карточку и убедитесь, что версия стала актуальной.
