# 🔒 Пошаговая настройка SSL для поддоменов

## ⚠️ ВАЖНО: Следуйте инструкциям точно по порядку!

### 1. Подготовка сервера

```bash
# Остановите Nginx (если запущен)
sudo systemctl stop nginx

# Проверьте статус
sudo systemctl status nginx
```

### 2. Копирование конфигурации Nginx

```bash
# Перейдите в папку скриптов
cd /var/www/prohelper/scripts

# Скопируйте конфигурацию nginx
sudo cp nginx-config-api.conf /etc/nginx/sites-available/prohelper-api

# Удалите дефолтную конфигурацию
sudo rm -f /etc/nginx/sites-enabled/default

# Создайте симлинк
sudo ln -sf /etc/nginx/sites-available/prohelper-api /etc/nginx/sites-enabled/

# Проверьте синтаксис (ДОЛЖНО ВЫДАТЬ ОШИБКУ - это нормально, сертификата пока нет)
sudo nginx -t
```

### 3. Настройка DNS в reg.ru

**ПЕРЕД запуском SSL скрипта убедитесь что настроены DNS записи:**

```
Тип    Имя    Значение           TTL
A      @      89.111.153.146     3600
A      api    89.111.153.146     3600  
A      lk     89.111.152.112     3600
A      admin  89.104.68.13       3600
A      *      89.111.153.146     3600
```

**Проверьте DNS перед продолжением:**
```bash
nslookup prohelper.pro
nslookup api.prohelper.pro  
nslookup test.prohelper.pro
```

### 4. Запуск SSL скрипта

```bash
# Убедитесь что находитесь в папке скриптов
cd /var/www/prohelper/scripts

# Сделайте скрипт исполняемым
chmod +x ssl-setup-api.sh

# Запустите скрипт
sudo ./ssl-setup-api.sh
```

### 5. Что делать когда Certbot попросит TXT записи

Certbot покажет что-то вроде:

```
Please deploy a DNS TXT record under the name
_acme-challenge.prohelper.pro with the following value:

ABC123DEF456...

Before continuing, verify the record is deployed.
```

**Действия:**
1. Идите в панель reg.ru
2. Добавьте TXT запись:
   - Имя: `_acme-challenge`
   - Значение: `ABC123DEF456...` (точно как показал Certbot)
   - TTL: 300 (5 минут)
3. Подождите 2-3 минуты
4. Проверьте: `nslookup -type=TXT _acme-challenge.prohelper.pro`
5. Нажмите Enter в консоли

### 6. Проверка результата

После успешной установки проверьте:

```bash
# Статус Nginx
sudo systemctl status nginx

# Статус автообновления
sudo systemctl status certbot-renew.timer

# Проверка сертификата
sudo certbot certificates

# Тест конфигурации
sudo nginx -t
```

### 7. Проверка в браузере

Откройте:
- https://prohelper.pro
- https://api.prohelper.pro
- https://test.prohelper.pro (любой поддомен)

Все должны работать с валидным SSL сертификатом.

## 🆘 Если что-то пошло не так

### Ошибка "nginx: configuration file test failed"
```bash
# Посмотрите детальную ошибку
sudo nginx -t

# Проверьте существует ли сертификат
ls -la /etc/letsencrypt/live/prohelper.pro/

# Если сертификата нет, запустите Nginx без SSL
sudo systemctl start nginx
```

### Ошибка "Domain is redundant with wildcard"
Это означает что в запросе есть конфликт между `api.prohelper.pro` и `*.prohelper.pro`. 
Обновленный скрипт исправляет эту проблему.

### DNS записи не распространились
```bash
# Проверьте DNS
nslookup prohelper.pro 8.8.8.8
nslookup api.prohelper.pro 8.8.8.8

# Очистите DNS кэш (если нужно)
sudo systemd-resolve --flush-caches
```

### Ошибка в TXT записи
```bash
# Проверьте TXT запись
nslookup -type=TXT _acme-challenge.prohelper.pro

# Если нет результата, подождите еще 2-3 минуты
# TTL записи в reg.ru может быть 300-600 секунд
```

## 📊 Мониторинг

После установки:
```bash
# Проверка автообновления
sudo systemctl list-timers | grep certbot

# Логи Nginx
sudo tail -f /var/log/nginx/prohelper_error.log

# Логи Certbot
sudo tail -f /var/log/letsencrypt/letsencrypt.log
```

## 🔄 Ручное обновление сертификата (если нужно)

```bash
# Остановить Nginx
sudo systemctl stop nginx

# Обновить сертификат
sudo certbot renew --force-renewal

# Запустить Nginx
sudo systemctl start nginx
``` 