# 🔧 Настройка экспортеров мониторинга

## 📋 Добавленные экспортеры:
- **PostgreSQL Exporter** (порт 9187)
- **Redis Exporter** (порт 9121) 
- **Nginx Exporter** (порт 9113)
- **Docker Metrics** (порт 9323)

## ⚙️ Команды для настройки на сервере:

### 1. PostgreSQL Exporter
```bash
# Отредактируйте docker-compose.yml с вашими данными PostgreSQL
nano docker-compose.yml

# Найдите секцию postgres-exporter и замените:
# DATA_SOURCE_NAME=postgresql://ваш_пользователь:ваш_пароль@host.docker.internal:5432/ваша_база?sslmode=disable

# Пример:
# DATA_SOURCE_NAME=postgresql://laravel:mypassword@host.docker.internal:5432/laravel_db?sslmode=disable
```

### 2. Настройка Nginx для метрик
```bash
# Добавьте в конфигурацию Nginx блок для статистики
sudo nano /etc/nginx/sites-available/default

# Добавьте внутри server блока:
location /nginx_status {
    stub_status on;
    access_log off;
    allow 127.0.0.1;
    allow 172.17.0.0/16;  # Docker сеть
    deny all;
}

# Перезапустите Nginx
sudo nginx -t
sudo systemctl reload nginx
```

### 3. Настройка Docker для метрик
```bash
# Создайте или отредактируйте /etc/docker/daemon.json
sudo nano /etc/docker/daemon.json

# Добавьте содержимое (или объедините с существующим):
{
  "metrics-addr": "0.0.0.0:9323",
  "experimental": true
}

# Перезапустите Docker
sudo systemctl restart docker

# Подождите 30 секунд для полной перезагрузки
sleep 30
```

### 4. Запуск с новыми экспортерами
```bash
# Остановите текущий мониторинг
docker compose down

# Запустите с новыми экспортерами
docker compose up -d

# Проверьте статус всех контейнеров
docker compose ps

# Подождите 1-2 минуты для полного запуска
sleep 120
```

### 5. Проверка работы экспортеров
```bash
# PostgreSQL
curl http://localhost:9187/metrics | head -10

# Redis (если Redis запущен)
curl http://localhost:9121/metrics | head -10

# Nginx
curl http://localhost:9113/metrics | head -10

# Docker
curl http://localhost:9323/metrics | head -10

# Проверьте targets в Prometheus
curl -s http://localhost:9090/api/v1/targets | grep -E '"health":"up"'
```

## 🛠️ Если экспортеры не работают:

### PostgreSQL не подключается:
```bash
# Проверьте что PostgreSQL доступен
psql -h localhost -U ваш_пользователь -d ваша_база -c "SELECT version();"

# Проверьте логи экспортера
docker logs postgres-exporter
```

### Redis не найден:
```bash
# Если Redis не установлен, временно отключите экспортер
docker compose stop redis-exporter

# Или установите Redis
sudo apt install redis-server
sudo systemctl enable redis-server
sudo systemctl start redis-server
```

### Nginx статус не работает:
```bash
# Проверьте что статус доступен
curl http://localhost/nginx_status

# Если 404, добавьте location в конфигурацию Nginx
```

### Docker метрики недоступны:
```bash
# Проверьте что daemon.json корректный
sudo docker info | grep -i experimental

# Перезапустите Docker если нужно
sudo systemctl restart docker
docker compose up -d
```

## 🎯 Быстрая настройка (минимум):

Если хотите быстро запустить только с PostgreSQL:

```bash
# 1. Укажите данные PostgreSQL в docker-compose.yml
nano docker-compose.yml
# Замените: DATA_SOURCE_NAME=postgresql://user:pass@host.docker.internal:5432/db?sslmode=disable

# 2. Запустите
docker compose down
docker compose up -d

# 3. Проверьте
sleep 60
curl -s http://localhost:9090/api/v1/targets | grep postgres
```

## 📊 После настройки:

1. Откройте Grafana: http://ваш-сервер:3000
2. Проверьте что дашборды показывают данные
3. В случае проблем проверьте логи: `docker logs название-экспортера`

---
**Замените данные подключения к PostgreSQL в docker-compose.yml перед запуском!** 