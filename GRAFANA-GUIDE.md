# 📊 Полное руководство по Grafana и мониторингу

## 🔗 Основные ссылки и порты

### Веб-интерфейсы
| Сервис | URL | Порт | Логин | Пароль |
|--------|-----|------|-------|--------|
| **Grafana** | http://ваш-сервер:3000 | 3000 | admin | admin123 |
| **Prometheus** | http://ваш-сервер:9090 | 9090 | - | - |
| **Loki** | http://ваш-сервер:3100 | 3100 | - | - |
| **Node Exporter** | http://ваш-сервер:9100 | 9100 | - | - |

### API эндпоинты
| Сервис | API URL | Описание |
|--------|---------|----------|
| **Laravel Metrics** | http://ваш-сервер/metrics | Метрики приложения |
| **Prometheus API** | http://ваш-сервер:9090/api/v1/ | Prometheus API |
| **Loki API** | http://ваш-сервер:3100/loki/api/v1/ | Loki API |

## 📋 Доступные дашборды

### 1. **Executive KPI Dashboard** - Топ-менеджмент
- **URL**: http://ваш-сервер:3000/d/executive-kpi
- **Содержание**: 
  - Общая производительность системы
  - Статистика пользователей
  - Финансовые метрики
  - SLA и Uptime

### 2. **Infrastructure Dashboard** - DevOps команда
- **URL**: http://ваш-сервер:3000/d/infrastructure
- **Содержание**:
  - Загрузка CPU, RAM, Диска
  - Сетевая активность
  - Статус контейнеров
  - Системные ресурсы

### 3. **Database Monitoring** - DBA и разработчики
- **URL**: http://ваш-сервер:3000/d/database-monitoring
- **Содержание**:
  - Производительность MySQL/PostgreSQL
  - Медленные запросы
  - Подключения к БД
  - Размер таблиц

### 4. **Security Dashboard** - Security команда
- **URL**: http://ваш-сервер:3000/d/security
- **Содержание**:
  - Подозрительная активность
  - Неудачные попытки входа
  - Аномалии трафика
  - Security events

### 5. **Support Realtime Dashboard** - Техподдержка
- **URL**: http://ваш-сервер:3000/d/support-realtime
- **Содержание**:
  - Текущие ошибки в реальном времени
  - Активные пользователи
  - Статус сервисов
  - Очередь задач

## 🚀 Быстрый старт

### Первый вход в Grafana
1. Откройте http://ваш-сервер:3000
2. Войдите: admin / admin123
3. **Сразу смените пароль!** (Profile → Change Password)

### Настройка источников данных (уже настроено)
- **Prometheus**: http://prometheus:9090
- **Loki**: http://loki:3100

## 📈 Использование дашбордов

### Навигация
- **Главное меню**: Левая панель → Dashboards
- **Поиск**: Ctrl+K или значок поиска
- **Избранное**: Звездочка на дашборде
- **Теги**: Фильтрация по категориям

### Временные интервалы
| Период | Кнопка | Использование |
|--------|--------|---------------|
| **5m** | Last 5 minutes | Реального времени мониторинг |
| **15m** | Last 15 minutes | Быстрая диагностика |
| **1h** | Last 1 hour | Анализ текущих проблем |
| **6h** | Last 6 hours | Дневной мониторинг |
| **24h** | Last 24 hours | Суточный анализ |
| **7d** | Last 7 days | Недельные тренды |
| **30d** | Last 30 days | Месячная статистика |

### Обновление данных
- **Auto**: Автообновление каждые 5с-30с
- **Manual**: Кнопка обновления
- **Live**: Режим реального времени

## 🔍 Поиск и фильтрация

### Быстрый поиск по логам
1. Перейдите в **Explore** (левое меню)
2. Выберите **Loki** как источник данных
3. Используйте запросы:

```logql
# Все ошибки Laravel
{job="promtail"} |= "ERROR"

# Ошибки за последний час
{job="promtail"} |= "ERROR" [1h]

# Ошибки конкретного контроллера
{job="promtail"} |= "UserController" |= "ERROR"

# 404 ошибки
{job="promtail"} |= "404"

# Медленные запросы
{job="promtail"} |= "slow query"
```

### Поиск по метрикам Prometheus
```promql
# Загрузка CPU
100 - (avg(irate(node_cpu_seconds_total{mode="idle"}[5m])) * 100)

# Использование памяти
(1 - (node_memory_MemAvailable_bytes / node_memory_MemTotal_bytes)) * 100

# HTTP запросы в секунду
rate(laravel_http_requests_total[5m])

# Ошибки 5xx
rate(laravel_http_requests_total{status=~"5.."}[5m])
```

## 📊 Создание собственных дашбордов

### Новый дашборд
1. **+ Create** → **Dashboard**
2. **Add visualization**
3. Выберите источник данных (Prometheus/Loki)
4. Добавьте запрос
5. Настройте визуализацию
6. **Save** дашборд

### Типы панелей
- **Time series**: Графики временных рядов
- **Stat**: Единичные значения
- **Table**: Таблицы данных
- **Heatmap**: Тепловые карты
- **Logs**: Панели логов

## ⚠️ Алерты и уведомления

### Настройка алертов
1. Откройте панель → **Edit**
2. Вкладка **Alert**
3. **Create Alert Rule**
4. Настройте условие
5. Добавьте notification channels

### Каналы уведомлений
- **Email**: SMTP настройки
- **Slack**: Webhook URL
- **Telegram**: Bot API
- **Discord**: Webhook

## 🛠️ Управление мониторингом

### Команды Docker
```bash
# Перезапуск мониторинга
docker-compose restart

# Просмотр логов Grafana
docker logs grafana

# Просмотр логов Prometheus
docker logs prometheus

# Остановка мониторинга
docker-compose down

# Полный перезапуск с очисткой
docker-compose down -v && docker-compose up -d
```

### Проверка статуса
```bash
# Проверка всех сервисов
docker-compose ps

# Проверка health check
docker-compose exec grafana curl http://localhost:3000/api/health

# Проверка Prometheus targets
curl http://localhost:9090/api/v1/targets
```

## 📱 Мобильная версия

### Grafana Mobile App
1. Установите **Grafana Mobile** из App Store/Google Play
2. Добавьте сервер: http://ваш-сервер:3000
3. Войдите с вашими учетными данными
4. Получайте уведомления на телефон

## 🔒 Безопасность

### Рекомендации
1. **Смените пароль admin** немедленно
2. Создайте отдельных пользователей для команд
3. Настройте HTTPS для продакшена
4. Ограничьте доступ по IP (если нужно)
5. Включите аудит логирование

### Роли пользователей
- **Admin**: Полный доступ
- **Editor**: Создание/редактирование дашбордов
- **Viewer**: Только просмотр

## 🎯 Полезные ссылки

### Документация
- [Grafana Documentation](https://grafana.com/docs/)
- [Prometheus Queries](https://prometheus.io/docs/prometheus/latest/querying/)
- [LogQL Reference](https://grafana.com/docs/loki/latest/logql/)

### Мониторинг Laravel
- [Laravel Metrics Guide](https://laravel.com/docs/metrics)
- [PHP Monitoring Best Practices](https://github.com/php-metrics)

## 🚨 Экстренные ситуации

### Если мониторинг не работает
1. Проверьте статус контейнеров: `docker-compose ps`
2. Перезапустите: `docker-compose restart`
3. Проверьте логи: `docker logs grafana`
4. Проверьте диск: `df -h`

### Если дашборды пустые
1. Проверьте Prometheus targets: http://ваш-сервер:9090/targets
2. Убедитесь что Laravel отдает метрики: http://ваш-сервер/metrics
3. Проверьте время на сервере: `date`

### Контакты поддержки
- **DevOps команда**: devops@company.com
- **Системный администратор**: sysadmin@company.com
- **Документация проекта**: /docs

---

## 📝 Шпаргалка по горячим клавишам

| Клавиша | Действие |
|---------|----------|
| **Ctrl+K** | Быстрый поиск |
| **Ctrl+H** | Скрыть/показать меню |
| **Ctrl+S** | Сохранить дашборд |
| **Ctrl+Z** | Отменить изменение |
| **F** | Полноэкранный режим панели |
| **D** | Дублировать панель |
| **V** | Просмотр панели |
| **E** | Редактирование панели |

**Версия руководства**: 1.0  
**Последнее обновление**: $(date)  
**Поддержка**: 24/7 мониторинг включен 