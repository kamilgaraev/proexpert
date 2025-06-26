# 🚀 ФИНАЛЬНАЯ ИНСТРУКЦИЯ: 3-серверная архитектура с поддоменами

## 🏗️ **АРХИТЕКТУРА СИСТЕМЫ**

### 🖥️ **Сервер 1: API + Холдинги** (89.111.153.146)
- `api.prohelper.pro` - Laravel REST API
- `company1.prohelper.pro` - интерфейс холдинга 1
- `company2.prohelper.pro` - интерфейс холдинга 2
- `*.prohelper.pro` - все остальные холдинги

### 🖥️ **Сервер 2: Личный кабинет** (89.111.152.112) 
- `lk.prohelper.pro` - личный кабинет организаций

### 🖥️ **Сервер 3: Админка** (89.104.68.13)
- `admin.prohelper.pro` - админка управления

## 🌐 **НАСТРОЙКА DNS В REG.RU**

### Зайдите в панель reg.ru:
- Откройте https://www.reg.ru/user/account
- Найдите домен `prohelper.pro`
- Перейдите в "DNS-серверы и управление зоной" → "Редактировать зону"

### Добавьте DNS записи:

**API сервер:**
```
Тип: A
Имя: api
Значение: 89.111.153.146
TTL: 300
```

**Личный кабинет:**
```
Тип: A
Имя: lk
Значение: 89.111.152.112
TTL: 300
```

**Админка:**
```
Тип: A
Имя: admin
Значение: 89.104.68.13
TTL: 300
```

**⭐ Wildcard для холдингов (на API сервер):**
```
Тип: A
Имя: *
Значение: 89.111.153.146
TTL: 300
```

**Основной домен (на API сервер):**
```
Тип: A
Имя: @
Значение: 89.111.153.146
TTL: 300
```

## 🔧 **НАСТРОЙКА СЕРВЕРОВ**

### 🖥️ **Сервер 1: API + Холдинги (89.111.153.146)**

#### Скопируйте файлы:
```bash
scp scripts/nginx-config-api.conf root@89.111.153.146:/tmp/
scp scripts/ssl-setup-api.sh root@89.111.153.146:/tmp/
```

#### Подключитесь и настройте:
```bash
ssh root@89.111.153.146
cd /tmp
chmod +x ssl-setup-api.sh
sudo ./ssl-setup-api.sh
```

#### В .env добавьте:
```env
APP_DOMAIN=prohelper.pro
APP_URL=https://api.prohelper.pro
```

### 🖥️ **Сервер 2: ЛК (89.111.152.112)**
```bash
# SSL для личного кабинета
sudo certbot certonly --manual --preferred-challenges=dns \
  --email admin@prohelper.pro \
  --agree-tos \
  -d lk.prohelper.pro
```

### 🖥️ **Сервер 3: Админка (89.104.68.13)**
```bash
# SSL для админки  
sudo certbot certonly --manual --preferred-challenges=dns \
  --email admin@prohelper.pro \
  --agree-tos \
  -d admin.prohelper.pro
```

## 🔗 **ИНТЕГРАЦИЯ МЕЖДУ СЕРВЕРАМИ**

### Из ЛК (89.111.152.112) в API (89.111.153.146):
```javascript
// Создание холдинга из ЛК
const response = await fetch('https://api.prohelper.pro/api/v1/landing/multi-organization/create-holding', {
    method: 'POST',
    headers: {
        'Authorization': `Bearer ${userToken}`,
        'Content-Type': 'application/json'
    },
    body: JSON.stringify({
        name: 'Новый холдинг',
        description: 'Описание холдинга'
    })
});

if (response.ok) {
    const data = await response.json();
    const holdingSlug = data.data.slug;
    
    // Переход в холдинг
    window.location.href = `https://${holdingSlug}.prohelper.pro/dashboard`;
}
```

### Из ЛК в админку:
```javascript
// Переход в админку (с токеном если нужно)
window.location.href = 'https://admin.prohelper.pro';
```

### Из холдинга обратно в ЛК:
```javascript
// Кнопка "Вернуться в ЛК"
window.location.href = 'https://lk.prohelper.pro';
```

## 🎯 **РЕЗУЛЬТАТ ПОСЛЕ НАСТРОЙКИ**

### ✅ **API Сервер** (89.111.153.146):
- `https://api.prohelper.pro` - REST API для всех сервисов
- `https://company1.prohelper.pro` - холдинг 1
- `https://company2.prohelper.pro` - холдинг 2

### ✅ **ЛК Сервер** (89.111.152.112):
- `https://lk.prohelper.pro` - личный кабинет

### ✅ **Админка Сервер** (89.104.68.13):
- `https://admin.prohelper.pro` - админка

## 🔒 **SSL СЕРТИФИКАТЫ**

При запуске скриптов SSL, Certbot попросит добавить TXT записи:

### Для API сервера:
```
Тип: TXT
Имя: _acme-challenge.api
Значение: (от Certbot)

Тип: TXT  
Имя: _acme-challenge
Значение: (от Certbot для wildcard)
```

### Для ЛК:
```
Тип: TXT
Имя: _acme-challenge.lk
Значение: (от Certbot)
```

### Для админки:
```
Тип: TXT
Имя: _acme-challenge.admin  
Значение: (от Certbot)
```

## 🧪 **ТЕСТИРОВАНИЕ**

### Проверьте DNS:
```bash
nslookup api.prohelper.pro      # -> 89.111.153.146
nslookup lk.prohelper.pro       # -> 89.111.152.112
nslookup admin.prohelper.pro    # -> 89.104.68.13
nslookup test.prohelper.pro     # -> 89.111.153.146
```

### Проверьте доступность:
- `https://api.prohelper.pro` ✅
- `https://lk.prohelper.pro` ✅  
- `https://admin.prohelper.pro` ✅

## 🚀 **ПРЕИМУЩЕСТВА ТАКОЙ АРХИТЕКТУРЫ**

1. **Масштабируемость** - каждый сервис на своем сервере
2. **Отказоустойчивость** - падение одного не влияет на другие
3. **Производительность** - нагрузка распределена
4. **Безопасность** - изоляция сервисов
5. **Гибкость** - можно обновлять сервисы независимо

## ⏱️ **ВРЕМЯ НАСТРОЙКИ: 45 минут**

- DNS: 10 минут
- API сервер: 15 минут  
- ЛК сервер: 10 минут
- Админка сервер: 10 минут

## 📞 **ГОТОВО К РАБОТЕ!**

После настройки у вас будет профессиональная микросервисная архитектура с автоматическими SSL сертификатами! 