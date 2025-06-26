#!/bin/bash

# Скрипт настройки SSL для API сервера с поддоменами
# Использование: sudo ./ssl-setup-api.sh

echo "🔒 Настройка SSL сертификатов для API сервера с поддоменами"

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

# Получение сертификатов для API поддоменов
echo "🌟 Получение SSL сертификатов для API поддоменов..."
echo "⚠️ ВНИМАНИЕ: Вам нужно будет добавить TXT записи в DNS!"
echo "📋 Для каждого домена добавьте TXT запись в панели reg.ru"

certbot certonly --manual --preferred-challenges=dns \
    --email admin@prohelper.pro \
    --agree-tos \
    --no-eff-email \
    -d api.prohelper.pro \
    -d *.prohelper.pro

# Настройка автообновления
echo "🔄 Настройка автообновления сертификатов..."
cat > /etc/cron.d/certbot-prohelper-api << EOF
0 12 * * * root certbot renew --quiet --post-hook "systemctl reload nginx"
EOF

# Копирование конфигурации Nginx
echo "⚙️ Настройка Nginx..."
cp nginx-config-api.conf /etc/nginx/sites-available/prohelper-api

# Удаление старой конфигурации если есть
rm -f /etc/nginx/sites-enabled/default

# Включение новой конфигурации
ln -sf /etc/nginx/sites-available/prohelper-api /etc/nginx/sites-enabled/

# Проверка конфигурации Nginx
nginx -t

if [ $? -eq 0 ]; then
    echo "✅ Конфигурация Nginx корректна"
    systemctl start nginx
    systemctl reload nginx
    echo "🚀 SSL настроен! API сервер готов к работе."
else
    echo "❌ Ошибка в конфигурации Nginx"
    exit 1
fi

echo ""
echo "🎉 Готово! API сервер настроен:"
echo "   🔗 API: https://api.prohelper.pro"  
echo "   🏢 Холдинги: https://company1.prohelper.pro"
echo "   🏢 Холдинги: https://company2.prohelper.pro"
echo ""
echo "ℹ️  Не забудьте настроить SSL на других серверах:"
echo "   👤 ЛК: 89.111.152.112 (lk.prohelper.pro)"
echo "   👨‍💼 Админка: 89.104.68.13 (admin.prohelper.pro)" 