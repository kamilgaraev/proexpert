# Настройка отправки писем через Resend

## Проблема
На продакшене используется `MAIL_MAILER=log`, поэтому письма не отправляются, а только записываются в лог-файл.

## Решение

### 1. Измените настройки в `.env` файле на продакшене:

```env
# Изменить MAIL_MAILER с log на resend
MAIL_MAILER=resend

# Добавить API ключ Resend
RESEND_API_KEY=re_auAVKQjP_EQhKu9CDcjtSmt5mKtKuFjgg

# Изменить адрес отправителя на валидный
# ВАЖНО: Используйте email с домена, верифицированного в Resend
MAIL_FROM_ADDRESS="noreply@prohelper.pro"
MAIL_FROM_NAME="ProHelper"
```

### 2. Проверьте верификацию домена в Resend

Зайдите в панель Resend: https://resend.com/domains

**Если домен prohelper.pro НЕ верифицирован:**
- Используйте тестовый адрес: `MAIL_FROM_ADDRESS="onboarding@resend.dev"`
- Или добавьте домен prohelper.pro в Resend и верифицируйте его

**Для верификации домена в Resend нужно добавить DNS записи:**
- SPF запись
- DKIM запись  
- DMARC запись (опционально)

### 3. После изменения .env выполните на проде:

```bash
# Очистить кеш конфигурации
php artisan config:clear
php artisan config:cache

# Перезапустить воркеры очередей (если используются)
php artisan queue:restart

# Опционально: перезапустить Octane/FrankenPHP
php artisan octane:reload
```

### 4. Проверка отправки

После настройки попробуйте:
1. Отправить приглашение пользователю
2. Проверить логи: `tail -f storage/logs/laravel.log`
3. Проверить папку "Спам" в почте получателя

### 5. Лимиты Resend

**Бесплатный план:**
- 100 писем в день
- 3,000 писем в месяц

**Если нужно больше** - обновите план в Resend.

## Текущие настройки

- **Пакет установлен:** ✅ resend/resend-php v0.15.1
- **Конфигурация:** ✅ config/mail.php настроен
- **API ключ:** ✅ Предоставлен

## Дополнительная информация

### Структура писем

Приглашения используют mailable класс: `App\Mail\UserInvitationMail`
Шаблон письма: `resources/views/emails/user_invitation.blade.php`

### Отладка

Если письма не отправляются, проверьте:

```bash
# Логи Laravel
tail -f storage/logs/laravel.log

# Логи системы
journalctl -u prohelper -f
```

Типичные ошибки:
- `Domain not found` - домен не добавлен в Resend
- `Invalid API key` - неверный RESEND_API_KEY
- `From address not verified` - используйте onboarding@resend.dev

## Альтернативный вариант - SMTP Resend

Если API не работает, можно использовать SMTP:

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.resend.com
MAIL_PORT=465
MAIL_USERNAME=resend
MAIL_PASSWORD=re_auAVKQjP_EQhKu9CDcjtSmt5mKtKuFjgg
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="noreply@prohelper.pro"
MAIL_FROM_NAME="ProHelper"
```

