# Интеграция блога в админку лендинга

## 1. Общая информация

Блог-модуль для админки лендинга предоставляет полнофункциональную систему управления контентом с SEO-оптимизацией.

**Base URL:** `/api/v1/landing/blog`  
**Авторизация:** Bearer Token (landing_admin guard)

## 2. Структура навигации

```
└─ Блог
   ├─ Дашборд                    ← /blog/dashboard
   │   ├─ Обзор                  ← /blog/dashboard/overview
   │   ├─ Аналитика              ← /blog/dashboard/analytics
   │   └─ Быстрая статистика     ← /blog/dashboard/quick-stats
   ├─ Статьи                     ← /blog/articles
   │   ├─ Все статьи             ← GET /blog/articles
   │   ├─ Создать статью         ← POST /blog/articles
   │   ├─ Черновики              ← GET /blog/articles-drafts
   │   ├─ Запланированные        ← GET /blog/articles-scheduled
   │   └─ Карточка статьи        ← /blog/articles/{id}
   │       ├─ Редактирование     ← PUT /blog/articles/{id}
   │       ├─ Публикация         ← POST /blog/articles/{id}/publish
   │       ├─ Планирование       ← POST /blog/articles/{id}/schedule
   │       └─ Архивирование      ← POST /blog/articles/{id}/archive
   ├─ Категории                  ← /blog/categories
   │   ├─ Список                 ← GET /blog/categories
   │   ├─ Создание               ← POST /blog/categories
   │   └─ Изменение порядка      ← POST /blog/categories/reorder
   ├─ Комментарии                ← /blog/comments
   │   ├─ Все комментарии        ← GET /blog/comments
   │   ├─ Ожидающие модерации    ← GET /blog/comments-pending
   │   ├─ Последние              ← GET /blog/comments-recent
   │   └─ Статистика             ← GET /blog/comments/stats
   └─ SEO                        ← /blog/seo
       ├─ Настройки              ← GET/PUT /blog/seo/settings
       ├─ Sitemap                ← GET /blog/seo/sitemap
       ├─ RSS                    ← GET /blog/seo/rss
       └─ Robots.txt             ← GET /blog/seo/robots
```

## 3. Ключевые компоненты и их интеграция

### 3.1. Дашборд блога

**Эндпоинт:** `GET /blog/dashboard/overview`

**Пример ответа:**
```json
{
  "success": true,
  "data": {
    "articles": {
      "total": 45,
      "published": 32,
      "drafts": 8,
      "scheduled": 5
    },
    "categories": {
      "total": 6,
      "active": 5
    },
    "comments": {
      "total": 156,
      "pending": 12,
      "approved": 130,
      "rejected": 8,
      "spam": 6
    },
    "popular_articles": [...],
    "recent_articles": [...],
    "recent_comments": [...]
  }
}
```

**UI рекомендации:**
- Отображать карточки со статистикой (всего, опубликовано, черновики)
- Индикатор ожидающих модерации комментариев (badge с числом)
- Таблица популярных статей с колонками: заголовок, просмотры, лайки, комментарии
- Список последних действий (новые статьи, комментарии)

### 3.2. Управление статьями

#### Список статей
**Эндпоинт:** `GET /blog/articles`

**Параметры фильтрации:**
- `status` - статус статьи (draft, published, scheduled, archived)
- `category_id` - фильтр по категории
- `author_id` - фильтр по автору
- `search` - поиск по заголовку и содержимому
- `per_page` - количество на странице (по умолчанию 15)

**Пример запроса:**
```
GET /blog/articles?status=published&category_id=1&search=SEO&per_page=20
```

**UI таблица статей:**
| Колонка | Описание | Сортировка |
|---------|----------|------------|
| Заголовок | Кликабельная ссылка на статью | ✓ |
| Категория | Цветной badge | ✓ |
| Автор | Имя автора | ✓ |
| Статус | Colored badge (draft/published/scheduled/archived) | ✓ |
| Дата публикации | Дата в формате ДД.ММ.ГГГГ | ✓ |
| Просмотры | Счетчик | ✓ |
| Действия | Dropdown меню | - |

**Цвета статусов:**
- `draft` - серый (#6c757d)
- `published` - зеленый (#28a745)
- `scheduled` - синий (#007bff)
- `archived` - оранжевый (#fd7e14)

#### Создание/редактирование статьи

**Создание:** `POST /blog/articles`  
**Обновление:** `PUT /blog/articles/{id}`

**Обязательные поля:**
- `title` - заголовок статьи
- `category_id` - ID категории
- `content` - содержимое
- `status` - статус статьи

**Пример запроса создания:**
```json
{
  "title": "Как создать SEO-оптимизированный блог",
  "slug": "kak-sozdat-seo-optimizirovannyj-blog",
  "category_id": 1,
  "excerpt": "Краткое описание статьи",
  "content": "<p>Полное содержимое статьи в HTML</p>",
  "featured_image": "/storage/blog/images/article-1.jpg",
  "meta_title": "Как создать SEO-оптимизированный блог | ProHelper",
  "meta_description": "Подробное руководство по созданию блога с учетом SEO",
  "status": "draft",
  "tags": ["seo", "блог", "контент-маркетинг"],
  "is_featured": false,
  "allow_comments": true
}
```

**Структура формы:**

1. **Основная информация**
   - Заголовок (обязательно)
   - Slug (автогенерация при вводе заголовка)
   - Категория (select из списка)
   - Краткое описание (textarea, до 500 символов)

2. **Содержимое**
   - Контент (rich text editor: TinyMCE/CKEditor)
   - Главное изображение (file upload)
   - Галерея изображений (multiple file upload)

3. **SEO настройки** (expandable секция)
   - Meta title (до 60 символов, счетчик)
   - Meta description (до 160 символов, счетчик)
   - Meta keywords (tags input)
   - Open Graph title (до 60 символов)
   - Open Graph description (до 200 символов)
   - Open Graph image

4. **Настройки публикации**
   - Статус (select: draft/published/scheduled/archived)
   - Дата публикации (datetime picker, если status=published)
   - Дата планирования (datetime picker, если status=scheduled)
   - Рекомендуемая статья (checkbox)
   - Разрешить комментарии (checkbox)
   - Включить в RSS (checkbox)
   - Запретить индексацию (checkbox)

5. **Теги**
   - Теги (tags input с автодополнением)

**Кнопки действий:**
- Сохранить как черновик
- Опубликовать
- Запланировать публикацию
- Предпросмотр

### 3.3. Управление категориями

**Список:** `GET /blog/categories`  
**Создание:** `POST /blog/categories`  
**Обновление:** `PUT /blog/categories/{id}`  
**Удаление:** `DELETE /blog/categories/{id}`  
**Изменение порядка:** `POST /blog/categories/reorder`

**Пример создания категории:**
```json
{
  "name": "SEO и Маркетинг",
  "slug": "seo-i-marketing",
  "description": "Статьи о поисковой оптимизации",
  "meta_title": "SEO и Маркетинг | ProHelper Blog",
  "meta_description": "Советы по SEO-оптимизации",
  "color": "#007bff",
  "image": "/storage/blog/categories/seo.jpg",
  "sort_order": 0,
  "is_active": true
}
```

**UI таблица категорий:**
| Колонка | Описание |
|---------|----------|
| Порядок | Drag&drop handles для сортировки |
| Название | С цветным индикатором |
| Статей | Количество опубликованных статей |
| Статус | Активна/Неактивна |
| Действия | Редактировать/Удалить |

**Drag & Drop сортировка:**
После изменения порядка отправлять:
```json
{
  "category_ids": [3, 1, 5, 2, 4]
}
```

### 3.4. Модерация комментариев

**Список:** `GET /blog/comments`  
**Изменение статуса:** `PUT /blog/comments/{id}/status`  
**Массовые операции:** `POST /blog/comments/bulk-status`

**Параметры фильтрации:**
- `status` - статус комментария
- `article_id` - фильтр по статье
- `per_page` - количество на странице

**UI таблица комментариев:**
| Колонка | Описание |
|---------|----------|
| Checkbox | Для массовых операций |
| Автор | Имя + email |
| Комментарий | Первые 100 символов + "..." |
| Статья | Заголовок статьи (ссылка) |
| Статус | Badge со статусом |
| Дата | Относительная дата |
| Действия | Одобрить/Отклонить/Спам/Удалить |

**Цвета статусов комментариев:**
- `pending` - желтый (#ffc107)
- `approved` - зеленый (#28a745)
- `rejected` - красный (#dc3545)
- `spam` - темно-красный (#721c24)

**Массовые операции:**
```json
{
  "status": "approved",
  "comment_ids": [1, 2, 3, 4]
}
```

### 3.5. SEO управление

**Получение настроек:** `GET /blog/seo/settings`  
**Обновление настроек:** `PUT /blog/seo/settings`

**Форма SEO настроек:**

1. **Основные настройки**
   - Название сайта
   - Описание сайта
   - Ключевые слова по умолчанию

2. **Meta настройки**
   - Автогенерация meta description (checkbox)
   - Максимальная длина meta description (number input)
   - Изображение по умолчанию для Open Graph

3. **Функции**
   - Включить хлебные крошки (checkbox)
   - Включить структурированные данные (checkbox)
   - Включить sitemap (checkbox)
   - Включить RSS-ленту (checkbox)

4. **Robots.txt**
   - Содержимое robots.txt (textarea)

5. **Социальные сети**
   - Facebook URL
   - Twitter URL
   - LinkedIn URL

6. **Аналитика**
   - Google Analytics ID
   - Yandex Metrica ID
   - Google Search Console код
   - Yandex Webmaster код

**Предпросмотр файлов:**
- `GET /blog/seo/preview/sitemap` - предпросмотр sitemap
- `GET /blog/seo/preview/rss` - предпросмотр RSS
- `GET /blog/seo/preview/robots` - предпросмотр robots.txt

## 4. Рекомендации по UX

### 4.1. Уведомления и статусы

**Toast уведомления для действий:**
- ✅ Статья создана
- ✅ Статья опубликована
- ✅ Статья запланирована на [дата]
- ✅ Комментарий одобрен
- ✅ Настройки SEO обновлены

**Подтверждения для критических действий:**
- Удаление статьи
- Удаление категории (если есть статьи)
- Массовое отклонение комментариев

### 4.2. Индикаторы загрузки

- Skeleton loader для таблиц
- Spinner для кнопок действий
- Progress bar для загрузки изображений

### 4.3. Валидация форм

**Клиентская валидация:**
- Заголовок статьи: обязательно, до 255 символов
- Meta title: до 60 символов (желтый при >50, красный при >60)
- Meta description: до 160 символов (аналогично)
- Email автора комментария: валидный email

### 4.4. Адаптивность

**Мобильные устройства:**
- Скрытие части колонок в таблицах
- Замена dropdown меню на bottom sheet
- Упрощение форм (сворачивание SEO секций)

## 5. Примеры запросов и ответов

### Создание статьи с полными данными

**Запрос:**
```http
POST /api/v1/landing/blog/articles
Authorization: Bearer YOUR_TOKEN
Content-Type: application/json

{
  "title": "10 способов улучшить SEO вашего сайта",
  "category_id": 1,
  "excerpt": "Практические советы по оптимизации сайта для поисковых систем",
  "content": "<h2>Введение</h2><p>SEO-оптимизация...</p>",
  "featured_image": "/storage/blog/images/seo-tips.jpg",
  "meta_title": "10 способов улучшить SEO | ProHelper",
  "meta_description": "Узнайте 10 проверенных способов улучшить SEO вашего сайта и привлечь больше органического трафика",
  "meta_keywords": ["seo", "оптимизация", "поисковые системы"],
  "status": "published",
  "tags": ["seo", "веб-разработка", "маркетинг"],
  "is_featured": true,
  "allow_comments": true
}
```

**Ответ:**
```json
{
  "success": true,
  "message": "Статья успешно создана",
  "data": {
    "id": 15,
    "title": "10 способов улучшить SEO вашего сайта",
    "slug": "10-sposobov-uluchshit-seo-vashego-sajta",
    "status": "published",
    "published_at": "2025-01-19T15:30:00.000000Z",
    "url": "/blog/10-sposobov-uluchshit-seo-vashego-sajta",
    "category": {
      "id": 1,
      "name": "SEO и Маркетинг"
    },
    "author": {
      "id": 1,
      "name": "Иван Петров"
    },
    "tags": [
      { "id": 1, "name": "seo" },
      { "id": 5, "name": "веб-разработка" },
      { "id": 8, "name": "маркетинг" }
    ]
  }
}
```

### Массовое одобрение комментариев

**Запрос:**
```http
POST /api/v1/landing/blog/comments/bulk-status
Authorization: Bearer YOUR_TOKEN
Content-Type: application/json

{
  "status": "approved",
  "comment_ids": [15, 16, 17, 18]
}
```

**Ответ:**
```json
{
  "success": true,
  "message": "Обновлено комментариев: 4",
  "data": {
    "updated_count": 4
  }
}
```

## 6. Обработка ошибок

**Стандартные коды ошибок:**
- `400` - Неверные данные запроса
- `401` - Не авторизован
- `403` - Доступ запрещен
- `404` - Ресурс не найден
- `422` - Ошибка валидации
- `500` - Внутренняя ошибка сервера

**Пример ошибки валидации:**
```json
{
  "success": false,
  "message": "Ошибка валидации",
  "errors": {
    "title": ["Заголовок статьи обязателен"],
    "category_id": ["Выбранная категория не существует"],
    "meta_title": ["Meta заголовок не должен превышать 60 символов"]
  }
}
```

## 7. Дополнительные функции

### Автосохранение черновиков
Рекомендуется реализовать автосохранение каждые 30 секунд при редактировании статьи.

### Предпросмотр статьи
Кнопка "Предпросмотр" должна открывать статью в новой вкладке с версткой фронтенда.

### Статистика в реальном времени
Использовать WebSocket или polling для обновления счетчиков просмотров и новых комментариев.

### Экспорт данных
- Экспорт статей в CSV/Excel
- Экспорт комментариев для анализа
- Бэкап всего контента блога

Этот гайд содержит всю необходимую информацию для полной интеграции блога в админку. Следуйте примерам запросов и рекомендациям по UX для создания удобного интерфейса управления. 