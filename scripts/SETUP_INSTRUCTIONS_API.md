# 🚀 ИНСТРУКЦИЯ: Настройка API сервера с поддоменами

## 🏗️ **АРХИТЕКТУРА СИСТЕМЫ**

- `lk.prohelper.pro` - Личный кабинет (существующий сервер)
- `api.prohelper.pro` - Laravel API (ваш сервер 89.111.153.146)
- `admin.prohelper.pro` - Админка Laravel (тот же сервер)
- `company1.prohelper.pro` - Холдинг 1 (тот же сервер)
- `company2.prohelper.pro` - Холдинг 2 (тот же сервер)

## 🔧 **ЧТО НУЖНО СДЕЛАТЬ**

### 1️⃣ **Настройка DNS в reg.ru (15 минут)**

#### Зайдите в панель reg.ru:
- Откройте https://www.reg.ru/user/account
- Найдите домен `prohelper.pro`
- Перейдите в "DNS-серверы и управление зоной" → "Редактировать зону"

#### Добавьте DNS записи для API сервера:

**API поддомен:**
```
Тип: A
Имя: api
Значение: 89.111.153.146
TTL: 300
```

**Админка поддомен:**
```
Тип: A
Имя: admin  
Значение: 89.111.153.146
TTL: 300
```

**⭐ Wildcard для холдингов:**
```
Тип: A
Имя: *
Значение: 89.111.153.146
TTL: 300
```

#### ⚠️ ВАЖНО: Личный кабинет
Убедитесь что запись для `lk.prohelper.pro` указывает на ваш существующий сервер личного кабинета!

### 2️⃣ **Настройка SSL на API сервере (20 минут)**

#### Скопируйте файлы на сервер:
```bash
# Загрузите файлы на API сервер
scp scripts/nginx-config-api.conf root@89.111.153.146:/tmp/
scp scripts/ssl-setup-api.sh root@89.111.153.146:/tmp/
```

#### Запустите настройку SSL:
```bash
# Подключитесь к API серверу
ssh root@89.111.153.146

# Перейдите в папку с файлами
cd /tmp

# Сделайте скрипт исполняемым
chmod +x ssl-setup-api.sh

# Запустите настройку
sudo ./ssl-setup-api.sh
```

#### При запросе TXT записей Certbot'ом:
Certbot попросит добавить несколько TXT записей для каждого домена:

1. Для `api.prohelper.pro`:
   ```
   Тип: TXT
   Имя: _acme-challenge.api
   Значение: (значение от Certbot)
   ```

2. Для `admin.prohelper.pro`:
   ```
   Тип: TXT
   Имя: _acme-challenge.admin
   Значение: (значение от Certbot)
   ```

3. Для `*.prohelper.pro`:
   ```
   Тип: TXT
   Имя: _acme-challenge
   Значение: (значение от Certbot)
   ```

### 3️⃣ **Настройка Laravel**

Добавьте в файл `.env`:
```env
APP_DOMAIN=prohelper.pro
APP_URL=https://api.prohelper.pro
```

### 4️⃣ **Тестирование системы**

#### Проверьте DNS:
```bash
nslookup api.prohelper.pro      # -> 89.111.153.146
nslookup admin.prohelper.pro    # -> 89.111.153.146  
nslookup test.prohelper.pro     # -> 89.111.153.146
nslookup lk.prohelper.pro       # -> ваш ЛК сервер
```

#### Проверьте доступность:
- `https://api.prohelper.pro` - должен отвечать Laravel API
- `https://admin.prohelper.pro` - должна быть доступна админка
- `https://lk.prohelper.pro` - должен работать существующий ЛК

## 🎯 **РЕЗУЛЬТАТ**

После настройки получите полноценную микросервисную архитектуру:

### API Сервер (89.111.153.146):
- ✅ `api.prohelper.pro` - REST API для всех сервисов
- ✅ `admin.prohelper.pro` - админка управления
- ✅ `company1.prohelper.pro` - интерфейс холдинга 1  
- ✅ `company2.prohelper.pro` - интерфейс холдинга 2

### ЛК Сервер (существующий):
- ✅ `lk.prohelper.pro` - личный кабинет организаций

## 🔄 **Интеграция между сервисами**

### Из ЛК в API:
```javascript
// В личном кабинете для вызова API
const response = await fetch('https://api.prohelper.pro/api/v1/organizations', {
    headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json'
    }
});
```

### Переходы между сервисами:
- ЛК → Холдинг: `https://company1.prohelper.pro`
- ЛК → Админка: `https://admin.prohelper.pro`

## 🆘 **Troubleshooting**

**API не отвечает:**
- Проверьте что Laravel запущен: `php artisan serve --host=0.0.0.0 --port=8000`
- Проверьте логи Nginx: `sudo tail -f /var/log/nginx/api_prohelper_error.log`

**Холдинг не найден:**
- Создайте тестовый холдинг в админке со slug `test`
- Проверьте в БД таблицу `organization_groups`

**CORS ошибки:**
- Настройте CORS в Laravel для домена ЛК
- Добавьте `lk.prohelper.pro` в `config/cors.php`

## 📞 **ГОТОВО!**

После настройки у вас будет полноценная система с разделением ответственности между сервисами! 