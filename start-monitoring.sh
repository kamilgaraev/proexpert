#!/bin/bash

echo "🚀 Запуск стека мониторинга для Laravel на Ubuntu..."

# Проверяем наличие Docker
if ! command -v docker &> /dev/null; then
    echo "❌ Docker не установлен. Установите Docker и повторите попытку."
    exit 1
fi

# Проверяем наличие Docker Compose (v1 или v2)
if ! command -v docker-compose &> /dev/null && ! docker compose version &> /dev/null; then
    echo "❌ Docker Compose не установлен. Установите Docker Compose и повторите попытку."
    exit 1
fi

# Определяем команду Docker Compose
if command -v docker-compose &> /dev/null; then
    DOCKER_COMPOSE="docker-compose"
else
    DOCKER_COMPOSE="docker compose"
fi

echo "🔧 Используется: $DOCKER_COMPOSE"

# Создаем необходимые директории
echo "📁 Создание директорий..."
mkdir -p monitoring/{loki,promtail,prometheus,grafana/{provisioning/{datasources,dashboards},dashboards}}
mkdir -p storage/logs/{api,telemetry}

# Устанавливаем права на директории
echo "🔐 Настройка прав доступа..."
chmod -R 755 monitoring/
chmod -R 777 storage/logs/

# Проверяем доступность портов
echo "🔍 Проверка доступности портов..."
ports=(3000 3100 9090 9100)
for port in "${ports[@]}"; do
    if lsof -Pi :$port -sTCP:LISTEN -t >/dev/null ; then
        echo "⚠️  Порт $port уже занят. Остановите службу или измените порт в docker-compose.yml"
        read -p "Продолжить? (y/n): " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            exit 1
        fi
    fi
done

# Устанавливаем зависимости Composer (если нужно)
if [ ! -d "vendor" ]; then
    echo "📦 Установка зависимостей Composer..."
    composer install --no-dev --optimize-autoloader
fi

# Запускаем стек мониторинга
echo "🐳 Запуск Docker контейнеров..."
$DOCKER_COMPOSE up -d

# Ждем запуска сервисов
echo "⏳ Ожидание запуска сервисов..."
sleep 30

# Проверяем статус сервисов
echo "🔍 Проверка статуса сервисов..."
services=("loki:3100" "prometheus:9090" "grafana:3000" "node-exporter:9100")
for service in "${services[@]}"; do
    name=${service%:*}
    port=${service#*:}
    if curl -s "http://localhost:$port" > /dev/null; then
        echo "✅ $name работает на порту $port"
    else
        echo "❌ $name не отвечает на порту $port"
    fi
done

# Проверяем метрики Laravel
echo "🔍 Проверка метрик Laravel..."
if curl -s "http://localhost/metrics" > /dev/null; then
    echo "✅ Laravel метрики доступны через веб-сервер"
elif curl -s "https://localhost/metrics" > /dev/null; then
    echo "✅ Laravel метрики доступны через HTTPS"
else
    echo "⚠️  Laravel метрики недоступны. Убедитесь что:"
    echo "    1. Laravel запущен через веб-сервер (Nginx/Apache)"
    echo "    2. Маршрут /metrics доступен"
    echo "    3. PrometheusMiddleware подключен"
fi

echo ""
echo "🎉 Стек мониторинга запущен!"
echo ""
echo "📊 Доступные сервисы:"
echo "   Grafana:    http://localhost:3000 (admin/admin123)"
echo "   Prometheus: http://localhost:9090"
echo "   Loki:       http://localhost:3100"
echo ""
echo "📈 Метрики Laravel:"
echo "   http://localhost:9091/metrics"
echo ""
echo "⚠️  Не забудьте:"
echo "   1. Настроить Laravel на порту 9091 для метрик"
echo "   2. Проверить права доступа к логам в storage/logs/"
echo "   3. Настроить firewall для портов 3000, 3100, 9090, 9100"
echo ""
echo "📜 Просмотр логов: docker-compose logs -f [service_name]"
echo "🛑 Остановка:     docker-compose down"