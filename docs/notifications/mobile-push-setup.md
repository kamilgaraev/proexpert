# Настройка RuStore push для Android

Сервер отправляет Android push через RuStore Push API.

## Переменные окружения

```dotenv
RUSTORE_PROJECT_ID=
RUSTORE_SERVICE_TOKEN=
```

Значения берутся в RuStore Console → приложение → Push-уведомления → Проекты. Сервер передаёт сервисный токен как Bearer credential. Не добавляйте его в исходный код, репозиторий или логи. После изменения env-конфигурации обновите Laravel config cache и queue workers.

## API регистрации устройства

`POST /api/v1/mobile/notifications/devices` сохраняет токен на пользователя и installation ID. Запрос:

```json
{
  "installation_id": "stable-installation-id",
  "platform": "android",
  "provider": "rustore",
  "token": "provider-device-token"
}
```

Сервер принимает только `provider=rustore` вместе с `platform=android`; запросы без provider и другие комбинации отклоняются. Ответ содержит `installation_id`, `platform` и `provider`. При смене пользователя на том же installation ID прежняя привязка удаляется. Токен шифруется при хранении, его SHA-256 используется для дедупликации.

Клиент может удалить регистрацию через `DELETE /api/v1/mobile/notifications/devices/{installation_id}`. `POST /api/v1/mobile/auth/logout` также принимает необязательный `installation_id`; после успешного выхода удаляется только регистрация этой установки.

Для уведомлений, адресованных мобильному интерфейсу пользователя с доступом к организации и проекту, сервер ставит канал `push` в очередь и не применяет к нему тихие часы. RuStore data payload содержит `notification_id`, `target_type`, `target_id`, `route`, а при наличии — `organization_id`, `project_id`, `journal_id` и `warehouse_id`. Идентификаторы передаются строками, чтобы клиент мог выбрать правильный контекст и открыть объект. Сервер сохраняет статус доставки, попытки, ID сообщения при наличии и безопасную краткую причину ошибки. Неизвестные ошибки транспорта не включают URL, raw token или response body.

RuStore token ошибки `NOT_FOUND`, `UNREGISTERED` и ошибки `INVALID_ARGUMENT`, явно называющие registration token, удаляют невалидную регистрацию. Остальные ошибки сохраняют регистрацию и оставляют доставку для повторной попытки.

Документация: [RuStore Push API](https://www.rustore.ru/help/sdk/push-notifications/send-push-notifications).
