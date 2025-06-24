#!/bin/bash

echo "🔧 Установка мониторинга как системного сервиса Ubuntu..."

# Проверяем права администратора
if [[ $EUID -ne 0 ]]; then
   echo "❌ Этот скрипт должен запускаться от имени root (sudo)"
   exit 1
fi

# Определяем пути
WORK_DIR=$(pwd)
SERVICE_NAME="monitoring"
SERVICE_FILE="/etc/systemd/system/${SERVICE_NAME}.service"
PROJECT_DIR="/var/www/prohelper"

echo "📁 Рабочая директория: $WORK_DIR"
echo "📁 Целевая директория: $PROJECT_DIR"

# Создаем целевую директорию если не существует
if [ ! -d "$PROJECT_DIR" ]; then
    echo "📁 Создание директории $PROJECT_DIR..."
    mkdir -p "$PROJECT_DIR"
fi

# Копируем файлы проекта в системную директорию
echo "📋 Копирование файлов мониторинга..."
cp docker-compose.yml "$PROJECT_DIR/"
cp -r monitoring/ "$PROJECT_DIR/"

# Создаем пользователя для мониторинга (если не существует)
if ! id "monitoring" &>/dev/null; then
    echo "👤 Создание пользователя monitoring..."
    useradd -r -s /bin/false -M monitoring
    usermod -aG docker monitoring
fi

# Устанавливаем права на директории
echo "🔐 Настройка прав доступа..."
chown -R monitoring:monitoring "$PROJECT_DIR"
chmod -R 755 "$PROJECT_DIR"

# Создаем systemd unit файл
echo "📝 Создание systemd сервиса..."
cat > "$SERVICE_FILE" << EOF
[Unit]
Description=Laravel Monitoring Stack (Loki, Prometheus, Grafana)
Requires=docker.service
After=docker.service
Wants=network-online.target
After=network-online.target

[Service]
Type=oneshot
RemainAfterExit=yes
WorkingDirectory=$PROJECT_DIR
ExecStart=/usr/bin/docker-compose up -d
ExecStop=/usr/bin/docker-compose down
ExecReload=/usr/bin/docker-compose restart
TimeoutStartSec=300
TimeoutStopSec=60
Restart=on-failure
RestartSec=10

# Безопасность
User=monitoring
Group=monitoring

# Логирование
StandardOutput=journal
StandardError=journal
SyslogIdentifier=monitoring-stack

[Install]
WantedBy=multi-user.target
EOF

# Перезагружаем systemd
echo "🔄 Перезагрузка systemd..."
systemctl daemon-reload

# Включаем автозапуск
echo "🚀 Включение автозапуска сервиса..."
systemctl enable "$SERVICE_NAME"

# Запускаем сервис
echo "▶️  Запуск сервиса мониторинга..."
systemctl start "$SERVICE_NAME"

# Ждем запуска
sleep 10

# Проверяем статус
echo "🔍 Проверка статуса сервиса..."
systemctl status "$SERVICE_NAME" --no-pager

# Проверяем доступность сервисов
echo "🌐 Проверка доступности сервисов..."
services=("3000" "3100" "9090" "9100")
for port in "${services[@]}"; do
    if curl -s "http://localhost:$port" > /dev/null; then
        echo "✅ Порт $port доступен"
    else
        echo "❌ Порт $port недоступен"
    fi
done

# Настраиваем логротацию для Docker
echo "📜 Настройка ротации логов Docker..."
cat > /etc/logrotate.d/docker-monitoring << EOF
/var/lib/docker/containers/*/*.log {
    daily
    rotate 7
    missingok
    notifempty
    compress
    copytruncate
    maxsize 10M
}
EOF

# Создаем алиасы для удобства
echo "🔗 Создание алиасов команд..."
cat >> /etc/bash.bashrc << 'EOF'

# Мониторинг Laravel
alias monitoring-status='systemctl status monitoring'
alias monitoring-logs='journalctl -u monitoring -f'
alias monitoring-restart='systemctl restart monitoring'
alias monitoring-stop='systemctl stop monitoring'
alias monitoring-start='systemctl start monitoring'
alias grafana-logs='docker logs grafana -f'
alias prometheus-logs='docker logs prometheus -f'
alias loki-logs='docker logs loki -f'
EOF

echo ""
echo "🎉 Мониторинг успешно установлен как системный сервис!"
echo ""
echo "📊 Управление сервисом:"
echo "   systemctl status monitoring     # Статус"
echo "   systemctl restart monitoring    # Перезапуск"
echo "   systemctl stop monitoring       # Остановка"
echo "   systemctl start monitoring      # Запуск"
echo "   journalctl -u monitoring -f     # Логи сервиса"
echo ""
echo "📊 Доступные сервисы:"
echo "   Grafana:    http://localhost:3000 (admin/admin123)"
echo "   Prometheus: http://localhost:9090"
echo "   Loki:       http://localhost:3100"
echo ""
echo "🔄 Автозапуск: ВКЛЮЧЕН (будет запускаться при загрузке системы)"
echo ""
echo "⚠️  Не забудьте:"
echo "   1. Настроить firewall: ufw allow 3000,3100,9090,9100"
echo "   2. Настроить Laravel на порту 9091"
echo "   3. Проверить права доступа к логам Laravel" 