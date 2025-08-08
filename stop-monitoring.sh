#!/bin/bash

echo "🛑 Остановка стека мониторинга..."

# Определяем команду Docker Compose
if command -v docker-compose &> /dev/null; then
    DOCKER_COMPOSE="docker-compose"
else
    DOCKER_COMPOSE="docker compose"
fi

echo "🔧 Используется: $DOCKER_COMPOSE"

# Останавливаем контейнеры
$DOCKER_COMPOSE down

# Опционально удаляем volumes (раскомментируйте если нужно)
# echo "🗑️  Удаление данных мониторинга..."
# $DOCKER_COMPOSE down -v

echo "✅ Стек мониторинга остановлен"