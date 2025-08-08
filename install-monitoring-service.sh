#!/bin/bash

# Скрипт для установки мониторинга как системного сервиса
# Обеспечивает автоматический запуск при загрузке системы

echo "🔧 Установка мониторинга как системного сервиса..."

# Проверка прав root
if [ "$EUID" -ne 0 ]; then
    echo "❌ Запустите скрипт с правами root: sudo $0"
    exit 1
fi

# Определение пути к проекту
PROJECT_PATH="/var/www/prohelper"
if [ ! -d "$PROJECT_PATH" ]; then
    echo "❌ Проект не найден в $PROJECT_PATH"
    echo "Укажите правильный путь или создайте симлинк:"
    echo "sudo ln -s $(pwd) $PROJECT_PATH"
    exit 1
fi

echo "📁 Проект найден в: $PROJECT_PATH"

# Создание systemd сервиса
echo "📝 Создание systemd сервиса..."
cat > /etc/systemd/system/monitoring.service << EOF
[Unit]
Description=Laravel Monitoring Stack (Prometheus, Grafana, Loki)
Requires=docker.service
After=docker.service
Wants=network-online.target
After=network-online.target

[Service]
Type=forking
User=root
Group=root
WorkingDirectory=$PROJECT_PATH
ExecStartPre=/bin/bash -c 'mkdir -p storage/logs/{api,telemetry} && chmod -R 777 storage/logs/'
ExecStart=/bin/bash $PROJECT_PATH/start-monitoring.sh
ExecStop=/bin/bash $PROJECT_PATH/stop-monitoring.sh
ExecReload=/bin/bash -c 'cd $PROJECT_PATH && docker-compose restart'
Restart=always
RestartSec=10
TimeoutStartSec=300
TimeoutStopSec=120

# Переменные окружения
Environment=COMPOSE_PROJECT_NAME=monitoring
Environment=DOCKER_BUILDKIT=1

# Логирование
StandardOutput=journal
StandardError=journal
SyslogIdentifier=monitoring

# Безопасность
PrivateTmp=true
ProtectSystem=strict
ReadWritePaths=$PROJECT_PATH
NoNewPrivileges=true

[Install]
WantedBy=multi-user.target
EOF

# Создание скрипта проверки здоровья
echo "🏥 Создание скрипта проверки здоровья..."
cat > $PROJECT_PATH/monitoring-health-check.sh << 'EOF'
#!/bin/bash

# Скрипт проверки здоровья мониторинга
# Запускается через cron каждые 5 минут

LOG_FILE="/var/log/monitoring-health.log"
PROJECT_PATH="/var/www/prohelper"
ALERT_WEBHOOK_URL=""  # Добавьте URL для уведомлений (Slack/Telegram)

# Функция логирования
log_message() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

# Функция отправки алертов
send_alert() {
    local message="$1"
    log_message "ALERT: $message"
    
    if [ -n "$ALERT_WEBHOOK_URL" ]; then
        curl -s -X POST "$ALERT_WEBHOOK_URL" \
            -H "Content-Type: application/json" \
            -d "{\"text\":\"🚨 Monitoring Alert: $message\"}" || true
    fi
}

# Проверка Docker контейнеров
check_containers() {
    cd "$PROJECT_PATH" || exit 1
    
    local containers=("prometheus" "grafana" "loki" "node-exporter")
    local failed_containers=()
    
    for container in "${containers[@]}"; do
        if ! docker ps --format "table {{.Names}}" | grep -q "^$container$"; then
            failed_containers+=("$container")
        fi
    done
    
    if [ ${#failed_containers[@]} -gt 0 ]; then
        send_alert "Контейнеры не запущены: ${failed_containers[*]}"
        log_message "Перезапуск мониторинга..."
        ./start-monitoring.sh
        sleep 60
        return 1
    fi
    
    return 0
}

# Проверка HTTP эндпоинтов
check_endpoints() {
    local endpoints=(
        "http://localhost:9090/-/healthy:Prometheus"
        "http://localhost:3000/api/health:Grafana"
        "http://localhost:3100/ready:Loki"
        "http://localhost:9100/metrics:Node-Exporter"
    )
    
    local failed_endpoints=()
    
    for endpoint_info in "${endpoints[@]}"; do
        local url="${endpoint_info%:*}"
        local name="${endpoint_info#*:}"
        
        if ! curl -sf "$url" >/dev/null 2>&1; then
            failed_endpoints+=("$name")
        fi
    done
    
    if [ ${#failed_endpoints[@]} -gt 0 ]; then
        send_alert "Сервисы недоступны: ${failed_endpoints[*]}"
        return 1
    fi
    
    return 0
}

# Проверка использования ресурсов
check_resources() {
    # Проверка использования диска
    local disk_usage=$(df / | tail -1 | awk '{print $5}' | sed 's/%//')
    if [ "$disk_usage" -gt 90 ]; then
        send_alert "Критически мало места на диске: ${disk_usage}%"
    elif [ "$disk_usage" -gt 80 ]; then
        log_message "WARN: Мало места на диске: ${disk_usage}%"
    fi
    
    # Проверка использования памяти
    local mem_usage=$(free | grep Mem | awk '{printf "%.0f", $3/$2 * 100.0}')
    if [ "$mem_usage" -gt 90 ]; then
        send_alert "Критически высокое использование памяти: ${mem_usage}%"
    fi
}

# Основная логика
main() {
    log_message "Начало проверки здоровья мониторинга"
    
    local issues=0
    
    if ! check_containers; then
        issues=$((issues + 1))
    fi
    
    if ! check_endpoints; then
        issues=$((issues + 1))
    fi
    
    check_resources
    
    if [ $issues -eq 0 ]; then
        log_message "Все сервисы мониторинга работают нормально"
    else
        log_message "Обнаружено проблем: $issues"
    fi
    
    log_message "Проверка завершена"
}

# Запуск
main "$@"
EOF

chmod +x $PROJECT_PATH/monitoring-health-check.sh

# Создание cron задач
echo "⏰ Настройка cron задач..."
cat > /tmp/monitoring-cron << EOF
# Проверка здоровья мониторинга каждые 5 минут
*/5 * * * * $PROJECT_PATH/monitoring-health-check.sh

# Ротация логов ежедневно в 2:00
0 2 * * * find $PROJECT_PATH/storage/logs -name "*.log" -mtime +7 -delete

# Очистка Docker каждое воскресенье в 3:00
0 3 * * 0 docker system prune -f --volumes

# Бэкап конфигурации мониторинга ежедневно в 1:00
0 1 * * * tar -czf /tmp/monitoring-backup-\$(date +\%Y\%m\%d).tar.gz -C $PROJECT_PATH monitoring/ docker-compose.yml

# Удаление старых бэкапов (старше 30 дней)
0 4 * * * find /tmp -name "monitoring-backup-*.tar.gz" -mtime +30 -delete
EOF

crontab /tmp/monitoring-cron
rm /tmp/monitoring-cron

# Создание директории для логов
mkdir -p /var/log
touch /var/log/monitoring-health.log
chmod 644 /var/log/monitoring-health.log

# Перезагрузка systemd
echo "🔄 Перезагрузка systemd..."
systemctl daemon-reload

# Включение автозапуска
echo "✅ Включение автозапуска..."
systemctl enable monitoring

# Запуск сервиса
echo "🚀 Запуск сервиса мониторинга..."
systemctl start monitoring

# Проверка статуса
echo "📊 Проверка статуса..."
sleep 10
systemctl status monitoring --no-pager

echo ""
echo "🎉 Установка завершена!"
echo ""
echo "📋 Управление сервисом:"
echo "   systemctl status monitoring    # Статус"
echo "   systemctl start monitoring     # Запуск"
echo "   systemctl stop monitoring      # Остановка"
echo "   systemctl restart monitoring   # Перезапуск"
echo "   journalctl -u monitoring -f    # Логи"
echo ""
echo "🔍 Проверка здоровья:"
echo "   $PROJECT_PATH/monitoring-health-check.sh"
echo "   tail -f /var/log/monitoring-health.log"
echo ""
echo "📊 Доступные сервисы:"
echo "   Grafana:    http://localhost:3000 (admin/admin123)"
echo "   Prometheus: http://localhost:9090"
echo "   Loki:       http://localhost:3100"
echo ""
echo "⚠️  Не забудьте:"
echo "   1. Настроить firewall для портов 3000, 3100, 9090, 9100"
echo "   2. Изменить пароли по умолчанию в Grafana"
echo "   3. Настроить ALERT_WEBHOOK_URL в monitoring-health-check.sh"
echo "   4. Проверить права доступа к storage/logs/"