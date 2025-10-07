# План реализации: Унифицированная система уведомлений

## Обзор

Поэтапная реализация мощной системы уведомлений с поддержкой Email, Telegram, In-App Push, шаблонов, аналитики и гибких настроек для SaaS платформы. Включает развертку WebSocket сервера (Laravel Reverb) и интеграцию с существующими событиями.

## Технический стек

### Backend
- **Framework**: Laravel 11.x (Notifications, Events, Queues)
- **Database**: PostgreSQL (notifications, templates, preferences, analytics)
- **Queue**: Redis (асинхронная обработка)
- **WebSocket**: Laravel Reverb (real-time In-App)
- **Email**: Resend SDK (уже настроен)
- **Telegram**: Telegram Bot API (существующий TelegramService)

### Frontend (UI для настроек и истории)
- Vue.js/React (интеграция с существующим SPA)
- WebSocket client (Laravel Echo)
- Toast notifications library

### DevOps
- **Мониторинг**: Laravel Horizon для очередей
- **Логирование**: существующий LoggingService
- **Метрики**: Prometheus/Grafana (уже настроены)

### Инфраструктура
- **WebSocket Server**: Laravel Reverb на отдельном порту (6001)
- **Process Manager**: Supervisor для queue workers и Reverb
- **Cache**: Redis для настроек и шаблонов

## Архитектурные решения

### Компонент 1: Core Notification System

**Ответственность**: Централизованное управление уведомлениями, routing по каналам, приоритизация

**Технологии**: 
- Laravel Notifications (расширение базовой функциональности)
- Custom Channel drivers
- Queue system

**Интерфейсы**:
```php
// Фасад для простоты использования
Notify::send(User $user, string $type, array $data, ?array $channels = null)
Notify::sendBulk(Collection $users, string $type, array $data)

// Event-driven
event(new ContractCreated($contract)); // автоматически генерирует уведомления

// Service API
NotificationService::create(NotificationDTO $dto)
NotificationService::dispatch(Notification $notification)
```

### Компонент 2: Channel Drivers

**Ответственность**: Адаптеры для различных каналов доставки

**Технологии**:
- Laravel Custom Notification Channels
- Resend SDK (Email)
- Telegram Bot API
- Laravel Broadcasting (In-App)
- Web Push API (опционально)

**Интерфейсы**:
```php
interface NotificationChannel {
    public function send($notifiable, Notification $notification): bool;
    public function track(Notification $notification, string $status): void;
}
```

### Компонент 3: Template Engine

**Ответственность**: Рендеринг шаблонов с переменными, мультиязычность, white-label

**Технологии**:
- Blade (Email HTML)
- Markdown (Telegram, In-App)
- Variable interpolation engine
- Laravel Localization

**Интерфейсы**:
```php
TemplateRenderer::render(NotificationTemplate $template, array $variables): string
TemplateRenderer::preview(NotificationTemplate $template, array $sampleData): string
```

### Компонент 4: Preference Manager

**Ответственность**: Управление пользовательскими настройками, quiet hours, rate limiting

**Технологии**:
- Database models с кэшированием
- Policy pattern для гибких правил
- Redis для rate limiting

**Интерфейсы**:
```php
PreferenceManager::getChannels(User $user, string $notificationType): array
PreferenceManager::canSend(User $user, string $type, Carbon $now): bool
PreferenceManager::updatePreferences(User $user, array $preferences): void
```

### Компонент 5: Analytics Tracker

**Ответственность**: Отслеживание delivery, opens, clicks, статистика

**Технологии**:
- Database events tracking
- Email tracking pixels
- URL shortener с click tracking
- Background jobs для агрегации

**Интерфейсы**:
```php
AnalyticsService::trackDelivery(Notification $notification, string $channel)
AnalyticsService::trackOpen(string $trackingId)
AnalyticsService::trackClick(string $linkId)
AnalyticsService::getStats(array $filters): Collection
```

### Компонент 6: WebSocket Server (Laravel Reverb)

**Ответственность**: Real-time доставка In-App уведомлений

**Технологии**:
- Laravel Reverb (встроенный WebSocket сервер Laravel 11)
- Redis для broadcasting
- Private channels (аутентификация)

**Интерфейсы**:
```javascript
// Frontend (Laravel Echo)
Echo.private(`user.${userId}`)
    .notification((notification) => {
        showToast(notification);
    });
```

## Этапы реализации

### Этап 1: Подготовка инфраструктуры (2-3 дня)

**Задачи**:
1. Создание структуры модуля `app/BusinessModules/Features/Notifications/`
2. Миграции для таблиц: notifications, notification_templates, notification_preferences, notification_analytics
3. Базовые модели с отношениями
4. Seeders для тестовых данных
5. Конфигурационный файл `config/notifications.php`

**Оценка**: 2-3 дня  
**Зависимости**: нет

**Критерии завершения**:
- [ ] Все таблицы созданы и связаны
- [ ] Модели покрыты базовыми тестами
- [ ] Seeders создают тестовые шаблоны

### Этап 2: Email канал (2 дня)

**Задачи**:
1. Рефакторинг существующих Mail классов в новую систему
2. EmailChannel driver с Resend интеграцией
3. Email шаблоны (Blade) для типовых уведомлений
4. Tracking pixels для opens
5. Link tracking для clicks
6. Тестирование отправки

**Оценка**: 2 дня  
**Зависимости**: Этап 1

**Критерии завершения**:
- [ ] Email отправляются через новую систему
- [ ] Tracking работает корректно
- [ ] 3+ готовых шаблона (invitation, alert, transaction)

### Этап 3: Telegram канал (1-2 дня)

**Задачи**:
1. Рефакторинг TelegramService в TelegramChannel
2. Markdown шаблоны для Telegram
3. Форматирование сообщений с кнопками (inline keyboard)
4. Обработка ошибок доставки
5. Интеграция с существующими уведомлениями

**Оценка**: 1-2 дня  
**Зависимости**: Этап 1

**Критерии завершения**:
- [ ] Telegram уведомления работают
- [ ] Поддержка inline кнопок
- [ ] Fallback при недоступности бота

### Этап 4: In-App канал (Database) (1 день)

**Задачи**:
1. InAppChannel driver (запись в БД)
2. API для получения уведомлений фронтендом
3. Пометка как прочитанное/непрочитанное
4. Фильтрация и пагинация
5. UI компонент для истории (базовый)

**Оценка**: 1 день  
**Зависимости**: Этап 1

**Критерии завершения**:
- [ ] Уведомления сохраняются в БД
- [ ] API возвращает список уведомлений
- [ ] Можно пометить как прочитанное

### Этап 5: Laravel Reverb (WebSocket) развертка (2-3 дня)

**Задачи**:
1. Установка и конфигурация Laravel Reverb
2. Настройка Broadcasting config (`config/broadcasting.php`)
3. Настройка Redis adapter для broadcasting
4. Создание Private channels для пользователей
5. Broadcasting middleware (аутентификация WebSocket)
6. Supervisor конфиг для Reverb процесса
7. Nginx/proxy конфиг для WebSocket (reverse proxy на порт 6001)
8. Frontend: установка Laravel Echo + Socket.io client
9. Real-time компонент для уведомлений
10. Тестирование real-time доставки

**Оценка**: 2-3 дня  
**Зависимости**: Этап 4

**Критерии завершения**:
- [ ] Reverb сервер запущен и стабилен
- [ ] WebSocket соединения работают
- [ ] Уведомления приходят в реальном времени
- [ ] Переподключение при разрыве соединения

**Конфигурация**:
```env
BROADCAST_DRIVER=reverb
REVERB_APP_ID=your-app-id
REVERB_APP_KEY=your-app-key
REVERB_APP_SECRET=your-app-secret
REVERB_HOST=localhost
REVERB_PORT=6001
REVERB_SCHEME=http
```

### Этап 6: Система настроек пользователя (2 дня)

**Задачи**:
1. NotificationPreference модель и логика
2. PreferenceManager сервис
3. Правила: обязательные/опциональные типы
4. Quiet hours функциональность
5. Rate limiting (группировка похожих уведомлений)
6. API для управления настройками
7. UI компонент настроек

**Оценка**: 2 дня  
**Зависимости**: Этапы 2-4

**Критерии завершения**:
- [ ] Пользователь может выбрать каналы
- [ ] Обязательные уведомления игнорируют настройки
- [ ] Quiet hours работают
- [ ] Rate limiting предотвращает спам

### Этап 7: Система шаблонов (3 дня)

**Задачи**:
1. NotificationTemplate модель с версионированием
2. TemplateRenderer сервис
3. Variable interpolation ({{user.name}}, {{project.name}})
4. Мультиязычность (Laravel Localization)
5. White-label (кастомизация на уровне организации)
6. Preview функциональность
7. CRUD API для шаблонов
8. UI для управления шаблонами (админ панель)

**Оценка**: 3 дня  
**Зависимости**: Этапы 2-3

**Критерии завершения**:
- [ ] Админ может создавать шаблоны
- [ ] Переменные корректно подставляются
- [ ] Preview работает для всех каналов
- [ ] Организация может кастомизировать шаблоны

### Этап 8: Очереди и приоритизация (2 дня)

**Задачи**:
1. SendNotificationJob с приоритетами
2. Конфигурация Redis queues (critical, high, normal, low)
3. Retry механизм (3 попытки, exponential backoff)
4. Dead Letter Queue для failed jobs
5. Отложенная отправка (scheduled delivery)
6. Laravel Horizon установка и конфигурация
7. Мониторинг очередей

**Оценка**: 2 дня  
**Зависимости**: Этапы 2-4

**Критерии завершения**:
- [ ] Уведомления обрабатываются асинхронно
- [ ] Приоритеты работают
- [ ] Retry при сбоях
- [ ] Horizon dashboard доступен

### Этап 9: Аналитика (3 дня)

**Задачи**:
1. NotificationAnalytics модель
2. AnalyticsService для tracking
3. Email opens tracking (tracking pixel)
4. Link clicks tracking (URL shortener)
5. Telegram delivery status (через webhook)
6. Aggregation jobs для статистики
7. Dashboard API с метриками
8. UI компонент с графиками (Chart.js)
9. Экспорт отчетов в CSV

**Оценка**: 3 дня  
**Зависимости**: Этапы 2-4

**Критерии завершения**:
- [ ] Opens/clicks отслеживаются
- [ ] Dashboard показывает статистику
- [ ] Можно экспортировать отчеты
- [ ] Данные обновляются в реальном времени

### Этап 10: Интеграция с существующими событиями (2-3 дня)

**Задачи**:
1. Рефакторинг ContractorInvitationNotification
2. Интеграция DashboardAlerts -> NotificationSystem
3. Интеграция Contract events (ContractStatusChanged, ContractLimitWarning)
4. Интеграция PersonnelRequest events
5. Интеграция Subscription events (trial ending, payment failed)
6. Миграция существующих уведомлений в новую систему
7. Обратная совместимость (graceful migration)

**Оценка**: 2-3 дня  
**Зависимости**: Все предыдущие этапы

**Критерии завершения**:
- [ ] Все события генерируют уведомления через новую систему
- [ ] Старый функционал не сломан
- [ ] Существующие подписчики получают уведомления

### Этап 11: API документация и тестирование (2 дня)

**Задачи**:
1. OpenAPI спецификация для всех endpoints
2. Примеры использования API
3. Unit тесты для сервисов
4. Feature тесты для API
5. Integration тесты для каналов
6. Load testing (5000 notifications/min)
7. Документация для разработчиков

**Оценка**: 2 дня  
**Зависимости**: Все предыдущие этапы

**Критерии завершения**:
- [ ] OpenAPI docs сгенерированы
- [ ] Test coverage > 80%
- [ ] Load test пройден успешно
- [ ] Developer guide написан

### Этап 12: Оптимизация и Production (2 дня)

**Задачи**:
1. Кэширование настроек (Redis cache)
2. Database индексы для быстрых запросов
3. Архивация старых уведомлений (> 90 дней)
4. Monitoring алертов (Grafana dashboards)
5. Логирование (интеграция с LoggingService)
6. Rate limiting на API
7. Production deployment checklist
8. Rollback plan

**Оценка**: 2 дня  
**Зависимости**: Все предыдущие этапы

**Критерии завершения**:
- [ ] Performance benchmarks пройдены
- [ ] Мониторинг настроен
- [ ] Deployment успешен
- [ ] Zero downtime migration

## Риски и mitigation

| Риск | Вероятность | Влияние | Стратегия mitigation |
|------|-------------|---------|---------------------|
| WebSocket сервер нестабилен на production | Средняя | Высокое | Тщательное тестирование, мониторинг, fallback на polling если WebSocket недоступен |
| Email попадают в спам | Средняя | Среднее | Правильная настройка SPF/DKIM, использование Resend (хорошая репутация), opt-out механизм |
| Переполнение Redis queue при пиковой нагрузке | Низкая | Высокое | Мониторинг размера очереди, автомасштабирование workers, rate limiting |
| Конфликты с существующими уведомлениями | Средняя | Среднее | Поэтапная миграция, обратная совместимость, feature flags |
| Сложность настройки для пользователей | Средняя | Среднее | Умные дефолты, progressive disclosure UI, подсказки |
| GDPR/privacy concerns по tracking | Низкая | Высокое | Opt-in для tracking, явное уведомление в privacy policy, anonymous tracking |

## Зависимости от пакетов

**Новые зависимости**:
```bash
composer require laravel/reverb  # WebSocket сервер
composer require laravel/horizon  # Мониторинг очередей
npm install laravel-echo pusher-js  # WebSocket клиент
```

**Уже установлены**:
- resend/resend-php (Email)
- predis/predis (Redis)

## Мониторинг и метрики

### Ключевые метрики

- **Throughput**: notifications/minute
- **Delivery Rate**: успешно доставленных / отправленных
- **Latency**: время от события до доставки (p50, p95, p99)
- **Queue Size**: текущий размер очередей
- **Error Rate**: процент failed уведомлений
- **Open Rate**: процент открытых Email
- **Click Rate**: процент кликов по ссылкам

### Grafana Dashboards

1. **Notifications Overview**: общая статистика
2. **Channel Performance**: метрики по каналам
3. **Queue Health**: состояние очередей
4. **WebSocket Connections**: активные соединения

### Алерты

- Queue size > 10000 (critical)
- Delivery rate < 95% (warning)
- WebSocket server down (critical)
- Error rate > 5% (warning)

## Production Deployment

### Pre-deployment checklist

- [ ] Миграции протестированы на staging
- [ ] Reverb конфигурация проверена
- [ ] Supervisor configs готовы
- [ ] Nginx reverse proxy настроен
- [ ] Redis память достаточна
- [ ] Мониторинг настроен
- [ ] Rollback plan готов

### Deployment steps

1. Включить maintenance mode
2. Запустить миграции
3. Deploy новый код
4. Запустить seeders для дефолтных шаблонов
5. Запустить Reverb через Supervisor
6. Перезапустить queue workers
7. Smoke tests
8. Выключить maintenance mode
9. Мониторинг в течение 1 часа

### Rollback plan

1. Откатить миграции (down)
2. Deploy предыдущую версию кода
3. Остановить Reverb
4. Перезапустить старые workers
5. Проверить функциональность

## Метрики успеха

### Технические

- **Performance**: < 2s для обработки из очереди
- **Reliability**: > 99.9% uptime для критичных уведомлений
- **Scalability**: 10,000 notifications/min без деградации
- **Test Coverage**: > 80%

### Бизнес

- **Engagement**: > 40% open rate для Email
- **Adoption**: > 80% пользователей настраивают каналы
- **Satisfaction**: < 5% полностью отключают уведомления
- **White-label**: > 50% организаций кастомизируют шаблоны

## Дальнейшее развитие (Post-MVP)

### Phase 2 (опционально)

1. **Browser Push Notifications** - уведомления даже когда сайт закрыт
2. **SMS канал** - для критичных уведомлений
3. **Slack/Discord интеграция** - для командных уведомлений
4. **Webhooks** - для интеграции с внешними системами
5. **A/B тестирование** - для оптимизации шаблонов
6. **ML предсказания** - best time to send
7. **Rich notifications** - изображения, файлы, interactive elements
8. **Notification center** - централизованная лента всех уведомлений

## Команда и ресурсы

- **Backend Developer**: реализация сервисов, каналов, API
- **Frontend Developer**: UI компоненты, WebSocket интеграция
- **DevOps**: настройка Reverb, мониторинг, deployment
- **QA**: тестирование, load testing

**Общая оценка**: 25-30 рабочих дней для полной реализации MVP (Phases 1-4 из спецификации)

