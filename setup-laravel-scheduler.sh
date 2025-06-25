#!/bin/bash

echo "⏰ Настройка Laravel Scheduler..."

# Проверяем права администратора
if [[ $EUID -ne 0 ]]; then
   echo "❌ Этот скрипт должен запускаться от имени root (sudo)"
   exit 1
fi

PROJECT_DIR="/var/www/prohelper"
PHP_BINARY="/usr/bin/php"

# Проверяем что проект существует
if [ ! -d "$PROJECT_DIR" ]; then
    echo "❌ Проект не найден в $PROJECT_DIR"
    exit 1
fi

# Проверяем что artisan существует
if [ ! -f "$PROJECT_DIR/artisan" ]; then
    echo "❌ Файл artisan не найден в $PROJECT_DIR"
    exit 1
fi

echo "📋 Создание cron задачи для Laravel Scheduler..."

# Создаем временный файл с cron задачами
TEMP_CRON=$(mktemp)

# Получаем существующие cron задачи
crontab -l 2>/dev/null > "$TEMP_CRON"

# Добавляем Laravel scheduler если его еще нет
if ! crontab -l 2>/dev/null | grep -q "artisan schedule:run"; then
    cat >> "$TEMP_CRON" << EOF

# Laravel Scheduler - запуск каждую минуту
* * * * * cd $PROJECT_DIR && $PHP_BINARY artisan schedule:run >> /dev/null 2>&1
EOF

    # Устанавливаем обновленные cron задачи
    crontab "$TEMP_CRON"
    echo "✅ Laravel Scheduler добавлен в cron"
else
    echo "ℹ️  Laravel Scheduler уже настроен в cron"
fi

# Удаляем временный файл
rm "$TEMP_CRON"

# Проверяем cron сервис
if systemctl is-active --quiet cron; then
    echo "✅ Cron сервис активен"
else
    echo "🔄 Запуск cron сервиса..."
    systemctl start cron
    systemctl enable cron
fi

# Проверяем права на директории Laravel
echo "🔒 Проверка прав доступа..."
chown -R www-data:www-data "$PROJECT_DIR/storage"
chown -R www-data:www-data "$PROJECT_DIR/bootstrap/cache"
chmod -R 775 "$PROJECT_DIR/storage"
chmod -R 775 "$PROJECT_DIR/bootstrap/cache"

echo ""
echo "🎉 Laravel Scheduler настроен!"
echo ""
echo "📋 Настроенные задачи:"
echo "   ⏰ Laravel Schedule: каждую минуту"
echo "   📁 Очистка файлов: ежедневно в 03:00"
echo "   💳 Обработка подписок: ежедневно в 02:00"
echo ""
echo "📜 Проверка:"
echo "   crontab -l                           # Просмотр задач"
echo "   systemctl status cron                # Статус cron"
echo "   tail -f $PROJECT_DIR/storage/logs/laravel.log  # Логи Laravel"
echo "   cd $PROJECT_DIR && php artisan schedule:list   # Список задач scheduler"
echo ""
echo "🔧 Тестирование:"
echo "   cd $PROJECT_DIR && php artisan schedule:run    # Ручной запуск" 