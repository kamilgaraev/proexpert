# 🚀 Быстрый доступ к мониторингу

## 🔗 Основные ссылки

### 📊 Grafana (Главный интерфейс)
```
URL: http://ваш-сервер:3000
Логин: admin
Пароль: admin123
```

### 📈 Прямые ссылки на дашборды
```
🏢 Руководство:     http://ваш-сервер:3000/d/executive-kpi
🖥️  Инфраструктура: http://ваш-сервер:3000/d/infrastructure  
🗄️  База данных:    http://ваш-сервер:3000/d/database-monitoring
🔒 Безопасность:    http://ваш-сервер:3000/d/security
🆘 Техподдержка:    http://ваш-сервер:3000/d/support-realtime
```

### 🔧 Мониторинг систем
```
🎯 Prometheus:     http://ваш-сервер:9090
📋 Loki:          http://ваш-сервер:3100  
⚙️  Node Exporter: http://ваш-сервер:9100
📊 Laravel:       http://ваш-сервер/metrics
```

## ⚡ Быстрые команды

### Управление
```bash
# Запуск мониторинга
docker-compose up -d

# Перезапуск
docker-compose restart

# Остановка  
docker-compose down

# Статус
docker-compose ps
```

### Проверка работы
```bash
# Проверить Grafana
curl http://localhost:3000/api/health

# Проверить Prometheus
curl http://localhost:9090/-/healthy

# Проверить Laravel метрики
curl http://localhost/metrics
```

## 🚨 Экстренный доступ

### Если Grafana не открывается:
1. `docker logs grafana`
2. `docker-compose restart grafana`
3. Проверить порт: `netstat -tulpn | grep 3000`

### Если нет данных в дашбордах:
1. Проверить targets: http://ваш-сервер:9090/targets
2. Убедиться что Laravel отдает метрики: `curl http://localhost/metrics`
3. Перезапустить Prometheus: `docker-compose restart prometheus`

## 📱 Мобильное приложение

**Grafana Mobile** - скачать из App Store/Google Play
- Сервер: `http://ваш-ip:3000`
- Логин: `admin`
- Пароль: `admin123`

---
**Первым делом смените пароль администратора!** 