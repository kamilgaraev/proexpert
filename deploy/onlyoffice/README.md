# OnlyOffice для МОСТ

Эта папка поднимает OnlyOffice Docs отдельным Docker Compose-стеком на том же сервере, что и МОСТ. Порт редактора доступен только локально; внешний HTTPS-доступ даёт Nginx.

## Подготовка сервера

1. Создайте DNS-запись `office.<ваш-домен>` на IP production-сервера.
2. Скопируйте `.env.example` в `.env` и задайте домен и новый JWT-секрет длиной не менее 32 символов. Файл `.env` не коммитить.
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
```

Затем выполните обычный backend-deploy из `main`. Не передавайте JWT в браузерные логи, git или переписку.

## Проверка

В МОСТ откройте карточку договора без текущей версии и нажмите «Создать в редакторе». Должен открыться DOCX-черновик. После сохранения закройте редактор, обновите карточку и убедитесь, что версия стала актуальной.
