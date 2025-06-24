#!/bin/bash

# Скрипт проверки здоровья стека мониторинга
# Рекомендуется запускать каждые 5 минут через cron:
# */5 * * * * /var/www/prohelper/monitoring-health-check.sh

LOG_FILE="/var/log/monitoring-health.log"
ALERT_WEBHOOK_URL=""  # Webhook для алертов (Slack/Discord/Telegram)

# Функция логирования
log_message() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

# Функция отправки алерта
send_alert() {
    local service="$1"
    local status="$2"
    local message="🚨 ALERT: $service is $status"
    
    log_message "$message"
    
    # Отправка в webhook (раскомментируйте и настройте)
    # if [ -n "$ALERT_WEBHOOK_URL" ]; then
    #     curl -X POST -H 'Content-type: application/json' \
    #         --data "{\"text\":\"$message\"}" \
    #         "$ALERT_WEBHOOK_URL"
    # fi
    
    # Отправка на email (требует настройки postfix/sendmail)
    # echo "$message" | mail -s "Monitoring Alert" admin@yourdomain.com
}

# Функция проверки сервиса
check_service() {
    local service_name="$1"
    local port="$2"
    local endpoint="$3"
    
    if curl -f -s --max-time 10 "http://localhost:$port$endpoint" > /dev/null 2>&1; then
        log_message "✅ $service_name (port $port) is healthy"
        return 0
    else
        log_message "❌ $service_name (port $port) is unhealthy"
        send_alert "$service_name" "DOWN"
        return 1
    fi
}

# Функция проверки Docker контейнера
check_container() {
    local container_name="$1"
    
    if docker ps --format "table {{.Names}}\t{{.Status}}" | grep -q "$container_name.*Up"; then
        log_message "✅ Container $container_name is running"
        return 0
    else
        log_message "❌ Container $container_name is not running"
        send_alert "Container $container_name" "NOT_RUNNING"
        
        # Попытка перезапуска
        log_message "🔄 Attempting to restart $container_name..."
        docker restart "$container_name"
        sleep 30
        
        # Повторная проверка
        if docker ps --format "table {{.Names}}\t{{.Status}}" | grep -q "$container_name.*Up"; then
            log_message "✅ Container $container_name restarted successfully"
            send_alert "Container $container_name" "RECOVERED"
        else
            log_message "❌ Failed to restart $container_name"
            send_alert "Container $container_name" "RESTART_FAILED"
        fi
        return 1
    fi
}

# Функция проверки дискового пространства
check_disk_space() {
    local usage=$(df / | awk 'NR==2 {print $5}' | sed 's/%//')
    
    if [ "$usage" -gt 90 ]; then
        log_message "❌ Disk usage is ${usage}% (critical)"
        send_alert "Disk Space" "CRITICAL (${usage}%)"
    elif [ "$usage" -gt 80 ]; then
        log_message "⚠️  Disk usage is ${usage}% (warning)"
        send_alert "Disk Space" "WARNING (${usage}%)"
    else
        log_message "✅ Disk usage is ${usage}% (normal)"
    fi
}

# Функция проверки памяти
check_memory() {
    local mem_usage=$(free | awk 'NR==2{printf "%.0f", $3*100/$2}')
    
    if [ "$mem_usage" -gt 90 ]; then
        log_message "❌ Memory usage is ${mem_usage}% (critical)"
        send_alert "Memory Usage" "CRITICAL (${mem_usage}%)"
    elif [ "$mem_usage" -gt 80 ]; then
        log_message "⚠️  Memory usage is ${mem_usage}% (warning)"
        send_alert "Memory Usage" "WARNING (${mem_usage}%)"
    else
        log_message "✅ Memory usage is ${mem_usage}% (normal)"
    fi
}

# Функция проверки Laravel метрик
check_laravel_metrics() {
    # Проверяем метрики Laravel через стандартный веб-сервер
    if curl -f -s --max-time 10 "http://localhost/metrics" > /dev/null 2>&1; then
        log_message "✅ Laravel metrics endpoint is healthy (HTTP)"
        return 0
    elif curl -f -s --max-time 10 "https://localhost/metrics" > /dev/null 2>&1; then
        log_message "✅ Laravel metrics endpoint is healthy (HTTPS)"
        return 0
    else
        log_message "❌ Laravel metrics endpoint is unhealthy"
        send_alert "Laravel Metrics" "DOWN"
        return 1
    fi
}

# Начало проверки
log_message "🔍 Starting monitoring health check..."

# Проверка systemd сервиса
if systemctl is-active --quiet monitoring; then
    log_message "✅ Monitoring systemd service is active"
else
    log_message "❌ Monitoring systemd service is not active"
    send_alert "Monitoring Service" "INACTIVE"
    
    # Попытка перезапуска
    log_message "🔄 Attempting to restart monitoring service..."
    systemctl restart monitoring
    sleep 60
fi

# Проверка контейнеров
check_container "grafana"
check_container "prometheus"
check_container "loki"
check_container "promtail"
check_container "node-exporter"

# Проверка сервисов
check_service "Grafana" "3000" "/api/health"
check_service "Prometheus" "9090" "/-/healthy"
check_service "Loki" "3100" "/ready"
check_service "Node Exporter" "9100" "/metrics"

# Проверка Laravel
check_laravel_metrics

# Проверка системных ресурсов
check_disk_space
check_memory

log_message "✅ Health check completed"

# Очистка старых логов (оставляем последние 7 дней)
find "$(dirname "$LOG_FILE")" -name "monitoring-health.log*" -mtime +7 -delete 2>/dev/null 