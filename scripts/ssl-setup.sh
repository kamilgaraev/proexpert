#!/bin/bash

# Скрипт настройки SSL для поддоменов холдингов
# Использование: sudo ./ssl-setup.sh

echo "🔒 Настройка SSL сертификатов для поддоменов холдингов"

# Проверка что скрипт запущен с sudo
if [ "$EUID" -ne 0 ]; then
    echo "❌ Запустите скрипт с sudo"
    exit 1
fi

# Установка Certbot если не установлен
if ! command -v certbot &> /dev/null; then
    echo "📦 Установка Certbot..."
    apt update
    apt install -y certbot
fi

# Остановка Nginx временно
echo "⏸️ Остановка Nginx..."
systemctl stop nginx

# Получение wildcard сертификата
echo "🌟 Получение wildcard SSL сертификата..."
echo "⚠️ ВНИМАНИЕ: Вам нужно будет добавить TXT запись в DNS!"
echo "📋 Скопируйте TXT запись которую покажет Certbot и добавьте в панели reg.ru"

certbot certonly --manual --preferred-challenges=dns \
    --email admin@1мост.рф \
    --agree-tos \
    --no-eff-email \
    -d 1мост.рф \
    -d *.1мост.рф

# Настройка автообновления
echo "🔄 Настройка автообновления сертификатов..."
cat > /etc/cron.d/certbot-prohelper << EOF
0 12 * * * root certbot renew --quiet --post-hook "systemctl reload nginx"
EOF

# Копирование конфигурации Nginx
echo "⚙️ Настройка Nginx..."
cp nginx-config.conf /etc/nginx/sites-available/prohelper-holdings
ln -sf /etc/nginx/sites-available/prohelper-holdings /etc/nginx/sites-enabled/

# Проверка конфигурации Nginx
nginx -t

if [ $? -eq 0 ]; then
    echo "✅ Конфигурация Nginx корректна"
    systemctl start nginx
    systemctl reload nginx
    echo "🚀 SSL настроен! Поддомены готовы к работе."
else
    echo "❌ Ошибка в конфигурации Nginx"
    exit 1
fi

echo ""
echo "🎉 Готово! Теперь можно создавать поддомены:"
echo "   test.1мост.рф"
echo "   company.1мост.рф"
echo "   и т.д."