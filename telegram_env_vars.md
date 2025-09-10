# Переменные окружения для Telegram бота

Добавьте следующие переменные в ваш .env файл:

```env
# Telegram Bot Configuration
TELEGRAM_BOT_TOKEN=8153490735:AAHxVV8BQDa9rHVAZWuvEmlEW0pNGu484RE
TELEGRAM_CHAT_ID=

# Telegram Notifications
TELEGRAM_NOTIFY_CONTACT_FORMS=true
TELEGRAM_NOTIFY_SITE_REQUESTS=false

# Telegram API Settings  
TELEGRAM_API_TIMEOUT=30
```

## Настройка

1. `TELEGRAM_BOT_TOKEN` - токен вашего бота (уже настроен)
2. `TELEGRAM_CHAT_ID` - ID чата, куда будут приходить уведомления
3. `TELEGRAM_NOTIFY_CONTACT_FORMS` - включить уведомления о новых заявках обратной связи
4. `TELEGRAM_NOTIFY_SITE_REQUESTS` - включить уведомления о заявках с объекта
5. `TELEGRAM_API_TIMEOUT` - таймаут для API запросов к Telegram

## Получение CHAT_ID

Чтобы получить CHAT_ID:
1. Добавьте бота @prohelpersbot в группу или напишите ему в личные сообщения
2. Отправьте любое сообщение
3. Вызовите GET https://api.telegram.org/bot8153490735:AAHxVV8BQDa9rHVAZWuvEmlEW0pNGu484RE/getUpdates
4. Найдите в ответе chat.id
