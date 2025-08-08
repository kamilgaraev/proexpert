# Интеграция мониторинга в CI/CD

Данное руководство описывает полную интеграцию системы мониторинга Laravel приложения в процессы CI/CD с использованием GitHub Actions.

## 📋 Обзор решения

### Что было реализовано:

1. **Автоматическая настройка мониторинга при деплое** (`deploy.yml`)
2. **Периодическая проверка здоровья мониторинга** (`monitoring-health.yml`)
3. **Автоматическое обновление конфигурации** (`monitoring-config-update.yml`)
4. **Установка как системный сервис** (`install-monitoring-service.sh`)

## 🚀 Workflow файлы

### 1. Deploy Workflow (`.github/workflows/deploy.yml`)

**Что делает:**
- Деплоит приложение на продакшн
- Автоматически настраивает и запускает мониторинг
- Проверяет работоспособность всех сервисов
- Отправляет уведомления о статусе деплоя

**Новые шаги мониторинга:**
```yaml
- name: Setup monitoring directories
- name: Start monitoring stack
- name: Wait for services to start
- name: Health check application
- name: Health check monitoring services
- name: Send deployment notification
```

### 2. Monitoring Health Workflow (`.github/workflows/monitoring-health.yml`)

**Что делает:**
- Запускается каждые 15 минут
- Проверяет статус Docker контейнеров
- Тестирует доступность HTTP эндпоинтов
- Мониторит использование ресурсов
- Автоматически перезапускает сервисы при сбоях
- Создает бэкапы и очищает старые логи

**Проверяемые сервисы:**
- Prometheus (http://localhost:9090)
- Grafana (http://localhost:3000)
- Loki (http://localhost:3100)
- Node Exporter (http://localhost:9100)

### 3. Config Update Workflow (`.github/workflows/monitoring-config-update.yml`)

**Что делает:**
- Срабатывает при изменении файлов мониторинга
- Создает бэкап текущей конфигурации
- Валидирует новые конфигурации
- Плавно обновляет мониторинг
- Откатывается при ошибках

**Отслеживаемые файлы:**
- `monitoring/**`
- `docker-compose.yml`
- `start-monitoring.sh`
- `stop-monitoring.sh`

## 🔧 Установка как системный сервис

### Скрипт установки (`install-monitoring-service.sh`)

**Возможности:**
- Создает systemd сервис для автозапуска
- Настраивает автоматическую проверку здоровья
- Создает cron задачи для обслуживания
- Настраивает ротацию логов
- Создает бэкапы конфигурации

**Использование:**
```bash
sudo ./install-monitoring-service.sh
```

**Управление сервисом:**
```bash
# Статус
systemctl status monitoring

# Запуск/остановка
systemctl start monitoring
systemctl stop monitoring
systemctl restart monitoring

# Логи
journalctl -u monitoring -f

# Проверка здоровья
/var/www/prohelper/monitoring-health-check.sh
tail -f /var/log/monitoring-health.log
```

## 📊 Автоматические проверки

### Health Check Script (`monitoring-health-check.sh`)

**Функции:**
- Проверка статуса Docker контейнеров
- Тестирование HTTP эндпоинтов
- Мониторинг использования диска и памяти
- Автоматический перезапуск при сбоях
- Отправка алертов в Slack/Telegram

**Cron задачи:**
```bash
# Проверка здоровья каждые 5 минут
*/5 * * * * /var/www/prohelper/monitoring-health-check.sh

# Ротация логов ежедневно в 2:00
0 2 * * * find /var/www/prohelper/storage/logs -name "*.log" -mtime +7 -delete

# Очистка Docker каждое воскресенье в 3:00
0 3 * * 0 docker system prune -f --volumes

# Бэкап конфигурации ежедневно в 1:00
0 1 * * * tar -czf /tmp/monitoring-backup-$(date +%Y%m%d).tar.gz -C /var/www/prohelper monitoring/ docker-compose.yml
```

## 🔔 Уведомления и алерты

### Настройка Webhook для уведомлений

**Slack:**
```bash
# В monitoring-health-check.sh
ALERT_WEBHOOK_URL="https://hooks.slack.com/services/YOUR/SLACK/WEBHOOK"
```

**Telegram:**
```bash
# В monitoring-health-check.sh
ALERT_WEBHOOK_URL="https://api.telegram.org/botYOUR_BOT_TOKEN/sendMessage?chat_id=YOUR_CHAT_ID&text="
```

### GitHub Secrets для CI/CD

Добавьте в настройки репозитория:
```
SERVER_HOST=your-server.com
SERVER_USER=deploy
SERVER_SSH_KEY=your-private-key
SLACK_WEBHOOK_URL=your-slack-webhook
```

## 🛡️ Безопасность

### Настройки systemd сервиса
- `PrivateTmp=true` - изолированная временная директория
- `ProtectSystem=strict` - защита системных файлов
- `NoNewPrivileges=true` - запрет повышения привилегий
- `ReadWritePaths` - ограниченный доступ к файлам

### Firewall настройки
```bash
# Открыть порты для мониторинга
sudo ufw allow 3000  # Grafana
sudo ufw allow 9090  # Prometheus
sudo ufw allow 3100  # Loki
sudo ufw allow 9100  # Node Exporter
```

## 📈 Мониторинг производительности

### Метрики ресурсов
- **Диск:** Алерт при заполнении >90%, предупреждение >80%
- **Память:** Алерт при использовании >90%
- **Docker:** Автоматическая очистка неиспользуемых образов

### Ротация логов
- Laravel логи: удаление файлов старше 7 дней
- Docker логи: ротация ежедневно, хранение 7 дней
- Мониторинг логи: автоматическая ротация

## 🔄 Процесс обновления

### Автоматическое обновление конфигурации
1. Изменение файлов мониторинга в репозитории
2. Push в ветку `main`
3. Автоматический запуск workflow
4. Бэкап текущей конфигурации
5. Валидация новых настроек
6. Плавное обновление сервисов
7. Проверка работоспособности
8. Откат при ошибках

### Ручное обновление
```bash
# Обновление конфигурации
cd /var/www/prohelper
git pull origin main
systemctl restart monitoring

# Проверка статуса
./monitoring-health-check.sh
```

## 🚨 Troubleshooting

### Частые проблемы

**Сервисы не запускаются:**
```bash
# Проверка логов
journalctl -u monitoring -f
docker-compose logs

# Перезапуск
systemctl restart monitoring
```

**Недостаточно места на диске:**
```bash
# Очистка Docker
docker system prune -af --volumes

# Очистка логов
find /var/www/prohelper/storage/logs -name "*.log" -mtime +3 -delete
```

**Проблемы с правами доступа:**
```bash
# Исправление прав
sudo chown -R www-data:www-data /var/www/prohelper/storage
sudo chmod -R 775 /var/www/prohelper/storage
```

### Полезные команды

```bash
# Статус всех сервисов
docker ps
systemctl status monitoring

# Логи сервисов
docker logs prometheus -f
docker logs grafana -f
docker logs loki -f

# Проверка портов
netstat -tlnp | grep -E '(3000|9090|3100|9100)'

# Тест эндпоинтов
curl -s http://localhost:9090/-/healthy
curl -s http://localhost:3000/api/health
curl -s http://localhost:3100/ready
```

## 📚 Дополнительные ресурсы

- [Prometheus Configuration](https://prometheus.io/docs/prometheus/latest/configuration/configuration/)
- [Grafana Provisioning](https://grafana.com/docs/grafana/latest/administration/provisioning/)
- [Loki Configuration](https://grafana.com/docs/loki/latest/configuration/)
- [GitHub Actions Documentation](https://docs.github.com/en/actions)
- [Systemd Service Documentation](https://www.freedesktop.org/software/systemd/man/systemd.service.html)

---

## 🎯 Заключение

Данная интеграция обеспечивает:
- ✅ Автоматическую настройку мониторинга при деплое
- ✅ Непрерывную проверку здоровья сервисов
- ✅ Автоматическое восстановление при сбоях
- ✅ Уведомления о проблемах
- ✅ Автоматическое обслуживание и очистку
- ✅ Безопасную работу как системный сервис
- ✅ Простое управление и мониторинг

Теперь ваш мониторинг полностью интегрирован в CI/CD процессы и работает автономно!