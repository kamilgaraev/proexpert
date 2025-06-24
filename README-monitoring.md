# 📊 Мониторинг Laravel с Loki, Prometheus и Grafana

Полная настройка стека мониторинга для Laravel приложения на Ubuntu с **24/7 работой**.

## 🏗️ Архитектура

```
┌─────────────┐    ┌─────────────┐    ┌─────────────┐
│   Laravel   │───▶│    Loki     │───▶│   Grafana   │
│ (Host:9091) │    │ (Docker)    │    │  (Docker)   │
└─────────────┘    └─────────────┘    └─────────────┘
       │                                      ▲
       ▼                                      │
┌─────────────┐    ┌─────────────┐           │
│  Laravel    │───▶│ Prometheus  │───────────┘
│  Metrics    │    │ (Docker)    │
└─────────────┘    └─────────────┘
       │
       ▼
┌─────────────┐    ┌─────────────┐
│  SystemD    │───▶│ Health      │
│  Service    │    │ Monitor     │
└─────────────┘    └─────────────┘
```

## 🚀 Установка 24/7 мониторинга

### 1. **Быстрая установка (автоматический запуск при загрузке системы):**

```bash
# 1. Установите как системный сервис
sudo ./install-monitoring-service.sh

# 2. Настройте автоматическую проверку здоровья
sudo ./setup-cron-monitoring.sh

# 3. Убедитесь что Laravel доступен через веб-сервер
curl http://localhost/metrics
```

### 2. **Ручная установка (для тестирования):**

```bash
# Установите зависимости
composer install

# Запустите мониторинг
chmod +x start-monitoring.sh
./start-monitoring.sh
```

### 3. **Настройка для продакшн сервера:**

В продакшене Laravel должен работать через **Nginx/Apache**, а не через `php artisan serve`.

#### **Для Nginx добавьте в конфигурацию:**
```nginx
# /etc/nginx/sites-available/your-site
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/prohelper/public;
    
    # ... обычная конфигурация Laravel ...
    
    # Эндпоинт метрик
    location /metrics {
        try_files $uri $uri/ /index.php?$query_string;
    }
}
```

#### **Для Apache добавьте в .htaccess или VirtualHost:**
```apache
# В public/.htaccess уже должно быть:
RewriteRule ^(.*)$ index.php [QSA,L]
```

#### **Проверка настройки:**
```bash
# Проверьте что метрики доступны
curl http://your-domain.com/metrics

# Или если локально
curl http://localhost/metrics
```

## 📋 Компоненты стека

### 🔍 **Loki (Логи)**
- **Порт:** 3100
- **Назначение:** Сбор и хранение логов Laravel
- **Источники:** API логи, системные логи, телеметрия
- **Автозапуск:** ✅ systemd + healthcheck + autoheal

### 📊 **Prometheus (Метрики)**
- **Порт:** 9090
- **Назначение:** Сбор метрик производительности
- **Источники:** Laravel метрики, системные метрики Ubuntu
- **Автозапуск:** ✅ systemd + healthcheck + autoheal

### 📈 **Grafana (Визуализация)**
- **Порт:** 3000
- **Логин:** admin / admin123
- **Дашборды:** Laravel Application, Ubuntu System
- **Автозапуск:** ✅ systemd + healthcheck + autoheal

### 🖥️ **Node Exporter (Системные метрики)**
- **Порт:** 9100
- **Назначение:** Метрики Ubuntu сервера
- **Автозапуск:** ✅ systemd + healthcheck + autoheal

### 🔄 **Watchdog (Автохил)**
- **Назначение:** Автоматический перезапуск упавших контейнеров
- **Интервал проверки:** 5 секунд

## 🕰️ Автоматизация 24/7

### **SystemD сервис:**
- ✅ Автозапуск при загрузке системы
- ✅ Автоперезапуск при сбоях
- ✅ Логирование в systemd journal
- ✅ Управление через systemctl

### **Cron задачи:**
- ⏰ **Проверка здоровья:** каждые 5 минут
- 🗂️ **Ротация логов:** ежедневно в 2:00
- 🧹 **Очистка Docker:** еженедельно в 3:00
- 📦 **Проверка обновлений:** понедельник в 4:00
- 💾 **Бэкап конфигурации:** ежедневно в 1:00

### **Health checks:**
- 🏥 HTTP проверки сервисов каждые 30 секунд
- 🔄 Автоматический перезапуск при сбоях
- 📧 Алерты при проблемах (настраиваемые)

## 🔧 Конфигурация Laravel

### 1. **Middleware для метрик**

`PrometheusMiddleware` автоматически собирает:
- Количество HTTP запросов
- Время ответа
- Статусы ответов
- Маршруты

### 2. **Доступные метрики**

- `laravel_http_requests_total` - Общее количество запросов
- `laravel_http_request_duration_seconds` - Время выполнения запросов
- `laravel_memory_usage_bytes` - Использование памяти
- `laravel_database_connections_active` - Активные соединения с БД
- `laravel_exceptions_total` - Количество исключений
- `laravel_queue_size` - Размер очереди

### 3. **Эндпоинт метрик**

```
GET /metrics
Content-Type: text/plain; charset=utf-8
```

## 📁 Структура логов

```
storage/logs/
├── api/          # API логи (JSON формат)
├── telemetry/    # Телеметрия производительности
└── laravel.log   # Основные логи Laravel
```

## 🔥 Алерты

Настроенные алерты в Prometheus:

### **Laravel алерты:**
- **HighErrorRate** - Частота ошибок > 10%
- **SlowResponseTime** - 95-й процентиль > 2s
- **HighMemoryUsage** - Использование памяти > 512MB
- **HighDatabaseConnections** - Активных соединений > 80

### **Системные алерты:**
- **HighCpuUsage** - CPU > 80%
- **LowMemoryAvailable** - Свободной памяти < 10%
- **LowDiskSpace** - Свободного места < 10%
- **ServiceDown** - Сервис недоступен

## 🎛️ Управление мониторингом

### **SystemD команды:**
```bash
# Статус мониторинга
systemctl status monitoring

# Перезапуск
systemctl restart monitoring

# Остановка
systemctl stop monitoring

# Запуск
systemctl start monitoring

# Логи сервиса
journalctl -u monitoring -f

# Отключить автозапуск
systemctl disable monitoring
```

### **Docker команды:**
```bash
# Статус контейнеров
docker ps

# Логи конкретного сервиса
docker logs grafana -f
docker logs prometheus -f
docker logs loki -f

# Перезапуск сервиса
docker restart grafana

# Статистика ресурсов
docker stats
```

### **Мониторинг команды:**
```bash
# Просмотр логов здоровья
tail -f /var/log/monitoring-health.log

# Проверка cron задач
crontab -l

# Статус cron
systemctl status cron

# Ручная проверка здоровья
/var/www/prohelper/monitoring-health-check.sh
```

## 🐛 Troubleshooting

### **Laravel метрики недоступны**

1. Проверьте запуск Laravel:
   ```bash
   curl http://localhost:9091/metrics
   ```

2. Проверьте middleware в `app/Http/Kernel.php`

3. Проверьте права на директории логов:
   ```bash
   chmod -R 777 storage/logs/
   ```

### **Prometheus не собирает метрики**

1. Проверьте доступность Laravel с контейнера:
   ```bash
   docker exec prometheus wget -qO- http://host.docker.internal:9091/metrics
   ```

2. Проверьте конфигурацию в `monitoring/prometheus/prometheus.yml`

### **Loki не получает логи**

1. Проверьте права доступа к логам:
   ```bash
   ls -la storage/logs/
   ```

2. Проверьте конфигурацию Promtail:
   ```bash
   docker logs promtail
   ```

### **Grafana не показывает данные**

1. Проверьте подключение к источникам данных в настройках Grafana
2. Проверьте доступность Prometheus и Loki из Grafana

### **Мониторинг не запускается автоматически**

1. Проверьте статус systemd сервиса:
   ```bash
   systemctl status monitoring
   ```

2. Проверьте логи systemd:
   ```bash
   journalctl -u monitoring -n 50
   ```

3. Проверьте права доступа:
   ```bash
   ls -la /var/www/prohelper/
   ```

## 🛠️ Расширенная настройка

### **Добавление алертов в Telegram**

1. Создайте бота в Telegram
2. Получите токен бота и chat_id
3. Отредактируйте `monitoring-health-check.sh`:
   ```bash
   ALERT_WEBHOOK_URL="https://api.telegram.org/bot<TOKEN>/sendMessage?chat_id=<CHAT_ID>&text="
   ```

### **Добавление алертов в Slack**

1. Создайте Slack App и получите webhook URL
2. Отредактируйте `monitoring-health-check.sh`:
   ```bash
   ALERT_WEBHOOK_URL="https://hooks.slack.com/services/YOUR/SLACK/WEBHOOK"
   ```

### **Добавление метрик БД**

1. Установите mysql_exporter или postgres_exporter
2. Добавьте в prometheus.yml
3. Создайте дашборд БД

### **Настройка Nginx метрик**

1. Включите nginx_stub_status
2. Установите nginx_exporter
3. Добавьте в мониторинг

## 📝 Полезные команды

```bash
# Системное управление
systemctl status monitoring
journalctl -u monitoring -f
systemctl restart monitoring

# Docker управление
docker-compose logs -f
docker-compose restart prometheus
docker stats

# Проверка здоровья
tail -f /var/log/monitoring-health.log
curl http://localhost:9091/metrics | head -20

# Бэкапы и очистка
tar -czf monitoring-backup.tar.gz monitoring/ docker-compose.yml
docker system prune -f --volumes
```

## 🔐 Безопасность

1. **Смените пароли по умолчанию** в Grafana
2. **Настройте HTTPS** для продакшена
3. **Ограничьте доступ** к портам мониторинга через firewall:
   ```bash
   ufw allow from 10.0.0.0/8 to any port 3000,3100,9090,9100
   ```
4. **Используйте authentication** для Grafana
5. **Регулярно обновляйте** образы Docker
6. **Мониторьте логи доступа** к сервисам мониторинга

## 📊 Производительность

### **Настройки для высоконагруженных систем:**

1. **Увеличьте retention в Prometheus:**
   ```yaml
   # В docker-compose.yml
   - '--storage.tsdb.retention.time=30d'
   ```

2. **Настройте лимиты ресурсов:**
   ```yaml
   deploy:
     resources:
       limits:
         memory: 1G
         cpus: '0.5'
   ```

3. **Настройте сжатие логов:**
   ```yaml
   # В конфигурации Loki
   chunk_store_config:
     chunk_cache_config:
       enable_fifocache: true
   ```

## 🎯 Мониторинг мониторинга

Стек мониторинга сам себя мониторит:

- ✅ **Health checks** каждые 30 секунд
- ✅ **Автохил контейнеров** каждые 5 секунд  
- ✅ **Системный мониторинг** каждые 5 минут
- ✅ **Алерты при сбоях** в реальном времени
- ✅ **Автоматические бэкапы** ежедневно
- ✅ **Ротация логов** автоматически 