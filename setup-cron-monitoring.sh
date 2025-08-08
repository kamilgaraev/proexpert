#!/bin/bash

echo "⏰ Настройка автоматической проверки здоровья мониторинга..."

# Проверяем права администратора
if [[ $EUID -ne 0 ]]; then
   echo "❌ Этот скрипт должен запускаться от имени root (sudo)"
   exit 1
fi

PROJECT_DIR="/var/www/prohelper"
HEALTH_CHECK_SCRIPT="$PROJECT_DIR/monitoring-health-check.sh"

# Делаем скрипт исполняемым
chmod +x "$HEALTH_CHECK_SCRIPT"

# Создаем cron задачи
echo "📋 Создание cron задач..."

# Создаем временный файл с cron задачами
TEMP_CRON=$(mktemp)

# Получаем существующие cron задачи
crontab -l 2>/dev/null > "$TEMP_CRON"

# Добавляем наши задачи (если их еще нет)
if ! crontab -l 2>/dev/null | grep -q "monitoring-health-check.sh"; then
    cat >> "$TEMP_CRON" << EOF

# Мониторинг здоровья стека каждые 5 минут
*/5 * * * * $HEALTH_CHECK_SCRIPT >/dev/null 2>&1

# Ротация логов мониторинга каждый день в 2:00
0 2 * * * find /var/log -name "monitoring-health.log*" -mtime +7 -delete >/dev/null 2>&1

# Очистка Docker логов каждую неделю
0 3 * * 0 docker system prune -f --volumes --filter "until=168h" >/dev/null 2>&1

# Проверка обновлений Docker образов каждый понедельник в 4:00
0 4 * * 1 cd $PROJECT_DIR && (command -v docker-compose >/dev/null && docker-compose pull || docker compose pull) >/dev/null 2>&1

# Бэкап конфигурации мониторинга каждый день в 1:00
0 1 * * * tar -czf /var/backups/monitoring-config-\$(date +\%Y\%m\%d).tar.gz -C $PROJECT_DIR monitoring/ docker-compose.yml 2>/dev/null && find /var/backups -name "monitoring-config-*.tar.gz" -mtime +30 -delete >/dev/null 2>&1
EOF

    # Устанавливаем обновленные cron задачи
    crontab "$TEMP_CRON"
    echo "✅ Cron задачи добавлены"
else
    echo "ℹ️  Cron задачи уже существуют"
fi

# Удаляем временный файл
rm "$TEMP_CRON"

# Создаем директорию для логов
mkdir -p /var/log
touch /var/log/monitoring-health.log
chmod 644 /var/log/monitoring-health.log

# Создаем директорию для бэкапов
mkdir -p /var/backups

# Настраиваем logrotate для наших логов
cat > /etc/logrotate.d/monitoring-health << EOF
/var/log/monitoring-health.log {
    daily
    rotate 7
    missingok
    notifempty
    compress
    copytruncate
    maxsize 10M
}
EOF

# Проверяем cron сервис
if systemctl is-active --quiet cron; then
    echo "✅ Cron сервис активен"
else
    echo "🔄 Запуск cron сервиса..."
    systemctl start cron
    systemctl enable cron
fi

echo ""
echo "🎉 Автоматический мониторинг настроен!"
echo ""
echo "📋 Настроенные задачи:"
echo "   ⏰ Проверка здоровья: каждые 5 минут"
echo "   🗂️  Ротация логов: ежедневно в 2:00"
echo "   🧹 Очистка Docker: еженедельно в 3:00"
echo "   📦 Проверка обновлений: понедельник в 4:00"
echo "   💾 Бэкап конфигурации: ежедневно в 1:00"
echo ""
echo "📜 Просмотр логов:"
echo "   tail -f /var/log/monitoring-health.log"
echo ""
echo "📋 Управление cron:"
echo "   crontab -l                    # Просмотр задач"
echo "   systemctl status cron         # Статус cron"
echo "   journalctl -u cron -f         # Логи cron"