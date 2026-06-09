# ЛК: база знаний и changelog

## Цель

Заполнить раздел «База знаний» в личном кабинете управляемым контентом: руководства, лучшие практики, советы и история обновлений. Контент редактируется в системной панели, а в ЛК доступен через отдельный read-only API.

## Контентная модель

### Категория

- `title` — человекочитаемое название.
- `slug` — стабильный адрес категории для фильтрации.
- `description` — краткое описание.
- `icon`, `color` — опциональные параметры отображения.
- `sort_order`, `is_active` — управление порядком и видимостью.

### Материал

- `kind` — `article`, `guide`, `best_practice`, `tip`, `changelog`.
- `status` — `draft`, `published`, `archived`.
- `title`, `slug`, `excerpt`, `content` — основное содержимое.
- `category_id` — категория для материалов базы знаний.
- `tags` — список коротких тематических меток.
- `release_version`, `release_date` — поля для changelog.
- `published_at`, `reading_time`, `is_featured`, `sort_order` — публикация и сортировка.

## Правила публикации

- ЛК получает только `status=published`.
- Если `published_at` заполнен, материал доступен только после наступления этой даты.
- Changelog хранится в той же таблице, но отдается отдельными endpoint-ами.
- Черновики и архивные записи не попадают в overview, поиск, списки и detail.

## API

- `GET /api/v1/landing/knowledge-hub/overview`
- `GET /api/v1/landing/knowledge-hub/articles`
- `GET /api/v1/landing/knowledge-hub/articles/{slug}`
- `GET /api/v1/landing/knowledge-hub/changelog`
- `GET /api/v1/landing/knowledge-hub/changelog/{slug}`

Все endpoint-ы используют JWT-гард ЛК и `organization.context`, чтобы контур оставался внутри защищенного кабинета.

## Редакционный контур

Системная панель получает два ресурса:

- «База знаний → Материалы»
- «База знаний → Категории»

Роль `content_manager` получает права:

- `system_admin.knowledge_hub.articles.view`
- `system_admin.knowledge_hub.articles.create`
- `system_admin.knowledge_hub.articles.update`
- `system_admin.knowledge_hub.articles.delete`
- `system_admin.knowledge_hub.categories.manage`
