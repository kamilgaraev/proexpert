#!/bin/bash

# Скрипт настройки SSL для API сервера с поддоменами
# Использование: sudo ./ssl-setup-api.sh

echo "🔒 Настройка SSL сертификатов для API сервера с поддоменами"

# Проверка что скрипт запущен с sudo
if [ "$EUID" -ne 0 ]; then
    echo "❌ Пожалуйста, запустите скрипт с sudo"
    exit 1
fi

# Проверка существования конфигурации nginx
if [ ! -f "/etc/nginx/sites-available/prohelper-api" ]; then
    echo "❌ Конфигурация nginx не найдена. Сначала скопируйте nginx-config-api.conf в /etc/nginx/sites-available/prohelper-api"
    exit 1
fi

# Установка Certbot
echo "📦 Установка Certbot..."
apt update
apt install -y certbot

# Остановка Nginx для получения сертификатов
echo "⏸️ Остановка Nginx..."
systemctl stop nginx

# Получение wildcard сертификата
echo "🌟 Получение wildcard SSL сертификата..."
echo "⚠️ ВНИМАНИЕ: Вам потребуется добавить TXT записи в DNS панели reg.ru!"
echo "📋 Certbot покажет вам TXT записи, которые нужно добавить"
echo ""
echo "Нажмите Enter когда будете готовы продолжить..."
read -p ""

# Получаем только wildcard сертификат (он покроет все поддомены включая api)
certbot certonly \
    --manual \
    --preferred-challenges dns \
    --email your-email@example.com \
    --agree-tos \
    --no-eff-email \
    -d "*.1мост.рф" \
    -d "1мост.рф"

# Проверяем что сертификат был получен
if [ ! -f "/etc/letsencrypt/live/1мост.рф/fullchain.pem" ]; then
    echo "❌ Сертификат не был получен. Проверьте DNS записи и попробуйте снова."
    echo "🔄 Запускаем Nginx без SSL..."
    systemctl start nginx
    exit 1
fi

echo "✅ SSL сертификат успешно получен!"

# Настройка автообновления
echo "🔄 Настройка автообновления сертификатов..."
cat > /etc/systemd/system/certbot-renew.service << 'EOF'
[Unit]
Description=Certbot Renewal
After=syslog.target

[Service]
Type=oneshot
ExecStart=/usr/bin/certbot renew --quiet --no-self-upgrade --post-hook "systemctl reload nginx"
EOF

cat > /etc/systemd/system/certbot-renew.timer << 'EOF'
[Unit]
Description=Timer for Certbot Renewal

[Timer]
OnBootSec=300
OnUnitActiveSec=1d

[Install]
WantedBy=multi-user.target
EOF

systemctl enable certbot-renew.timer
systemctl start certbot-renew.timer

# Обновляем конфигурацию nginx для использования правильного пути к сертификату
echo "⚙️ Обновление конфигурации Nginx..."
sed -i 's|/etc/letsencrypt/live/api.1мост.рф/|/etc/letsencrypt/live/1мост.рф/|g' /etc/nginx/sites-available/prohelper-api

# Тестируем конфигурацию nginx
echo "🧪 Тестирование конфигурации Nginx..."
nginx -t

if [ $? -eq 0 ]; then
    echo "✅ Конфигурация Nginx корректна"
    echo "🚀 Запуск Nginx..."
    systemctl start nginx
    systemctl enable nginx

    echo ""
    echo "🎉 SSL успешно настроен!"
    echo "📋 Ваши домены теперь доступны по HTTPS:"
    echo "   • https://1мост.рф"
    echo "   • https://api.1мост.рф"
    echo "   • https://любой-холдинг.1мост.рф"
    echo ""
    echo "🔄 Автообновление настроено и будет выполняться ежедневно"
    echo "📊 Проверить статус: systemctl status certbot-renew.timer"
else
    echo "❌ Ошибка в конфигурации Nginx"
    echo "🔍 Проверьте конфигурацию: nginx -t"
    echo "📝 Лог ошибок: tail -f /var/log/nginx/error.log"
    exit 1
fi