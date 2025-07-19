# Блог-модуль: Быстрый старт для фронтенда

## 🚀 Минимальная интеграция (MVP)

### 1. Обязательные эндпоинты для запуска

```javascript
// Базовые CRUD операции
const blogAPI = {
  // Дашборд
  getDashboard: () => GET('/blog/dashboard/overview'),
  
  // Статьи
  getArticles: (params) => GET('/blog/articles', params),
  createArticle: (data) => POST('/blog/articles', data),
  updateArticle: (id, data) => PUT(`/blog/articles/${id}`, data),
  deleteArticle: (id) => DELETE(`/blog/articles/${id}`),
  
  // Категории
  getCategories: () => GET('/blog/categories'),
  createCategory: (data) => POST('/blog/categories', data),
  
  // Комментарии (модерация)
  getComments: (params) => GET('/blog/comments', params),
  updateCommentStatus: (id, status) => PUT(`/blog/comments/${id}/status`, {status}),
  
  // SEO
  getSeoSettings: () => GET('/blog/seo/settings'),
  updateSeoSettings: (data) => PUT('/blog/seo/settings', data)
}
```

### 2. Минимальные компоненты

**Структура страниц:**
```
/admin/blog/
├── dashboard/           ← Главная страница блога
├── articles/           ← Список статей + создание
├── articles/:id/edit   ← Редактирование статьи
├── categories/         ← Управление категориями
├── comments/           ← Модерация комментариев
└── seo/               ← SEO настройки
```

### 3. Ключевые формы

#### Форма статьи (минимум)
```json
{
  "title": "string (required)",
  "category_id": "number (required)", 
  "content": "string (required)",
  "status": "draft|published (required)",
  "excerpt": "string (optional)",
  "featured_image": "string (optional)",
  "tags": ["array of strings (optional)"]
}
```

#### Форма категории
```json
{
  "name": "string (required)",
  "description": "string (optional)",
  "color": "#hex (optional)",
  "is_active": "boolean (optional)"
}
```

### 4. Статусы и цвета

```css
/* Статусы статей */
.status-draft { color: #6c757d; }
.status-published { color: #28a745; }
.status-scheduled { color: #007bff; }
.status-archived { color: #fd7e14; }

/* Статусы комментариев */
.status-pending { color: #ffc107; }
.status-approved { color: #28a745; }
.status-rejected { color: #dc3545; }
.status-spam { color: #721c24; }
```

## ⚡ Быстрое подключение

### 1. Добавить в роутер
```javascript
// Vue Router
{
  path: '/admin/blog',
  component: BlogLayout,
  children: [
    { path: 'dashboard', component: BlogDashboard },
    { path: 'articles', component: ArticlesList },
    { path: 'articles/create', component: ArticleForm },
    { path: 'articles/:id/edit', component: ArticleForm },
    { path: 'categories', component: CategoriesList },
    { path: 'comments', component: CommentsList },
    { path: 'seo', component: SeoSettings }
  ]
}
```

### 2. Базовые HTTP запросы
```javascript
// Axios instance с Bearer token
const api = axios.create({
  baseURL: '/api/v1/landing',
  headers: {
    'Authorization': `Bearer ${token}`,
    'Content-Type': 'application/json'
  }
});
```

### 3. Обязательные библиотеки
- **Rich Text Editor**: TinyMCE или CKEditor для контента
- **Date Picker**: для планирования публикации
- **Tags Input**: для ввода тегов
- **File Upload**: для изображений

## 📱 Пример компонента

### ArticlesList.vue
```vue
<template>
  <div>
    <!-- Фильтры -->
    <div class="filters">
      <select v-model="filters.status">
        <option value="">Все статусы</option>
        <option value="draft">Черновики</option>
        <option value="published">Опубликованные</option>
      </select>
      <input v-model="filters.search" placeholder="Поиск...">
    </div>

    <!-- Таблица статей -->
    <table>
      <thead>
        <tr>
          <th>Заголовок</th>
          <th>Категория</th>
          <th>Статус</th>
          <th>Дата</th>
          <th>Действия</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="article in articles" :key="article.id">
          <td>{{ article.title }}</td>
          <td>
            <span class="badge" :style="{backgroundColor: article.category.color}">
              {{ article.category.name }}
            </span>
          </td>
          <td>
            <span :class="`status-${article.status}`">
              {{ statusLabels[article.status] }}
            </span>
          </td>
          <td>{{ formatDate(article.published_at) }}</td>
          <td>
            <button @click="editArticle(article.id)">Редактировать</button>
            <button @click="deleteArticle(article.id)">Удалить</button>
          </td>
        </tr>
      </tbody>
    </table>

    <!-- Пагинация -->
    <pagination v-model="currentPage" :total="totalPages" />
  </div>
</template>

<script>
export default {
  data() {
    return {
      articles: [],
      filters: { status: '', search: '' },
      currentPage: 1,
      totalPages: 1,
      statusLabels: {
        draft: 'Черновик',
        published: 'Опубликовано',
        scheduled: 'Запланировано',
        archived: 'Архивировано'
      }
    }
  },
  
  watch: {
    filters: {
      handler() { this.loadArticles() },
      deep: true
    },
    currentPage() { this.loadArticles() }
  },
  
  mounted() {
    this.loadArticles()
  },
  
  methods: {
    async loadArticles() {
      const params = {
        page: this.currentPage,
        ...this.filters
      }
      const response = await this.$api.get('/blog/articles', { params })
      this.articles = response.data.data
      this.totalPages = response.data.meta.last_page
    },
    
    editArticle(id) {
      this.$router.push(`/admin/blog/articles/${id}/edit`)
    },
    
    async deleteArticle(id) {
      if (confirm('Удалить статью?')) {
        await this.$api.delete(`/blog/articles/${id}`)
        this.loadArticles()
      }
    },
    
    formatDate(date) {
      return new Date(date).toLocaleDateString('ru-RU')
    }
  }
}
</script>
```

## 🔥 Готовые запросы cURL для тестирования

```bash
# Получить дашборд
curl -H "Authorization: Bearer YOUR_TOKEN" \
  http://localhost:8000/api/v1/landing/blog/dashboard/overview

# Создать статью
curl -X POST \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Тестовая статья",
    "category_id": 1,
    "content": "<p>Контент статьи</p>",
    "status": "draft"
  }' \
  http://localhost:8000/api/v1/landing/blog/articles

# Получить список статей
curl -H "Authorization: Bearer YOUR_TOKEN" \
  "http://localhost:8000/api/v1/landing/blog/articles?status=published&per_page=10"
```

## 🎯 Приоритеты разработки

### Фаза 1 (MVP - 1-2 дня)
1. ✅ Дашборд с базовой статистикой
2. ✅ CRUD статей (без SEO полей)
3. ✅ Управление категориями
4. ✅ Простая модерация комментариев

### Фаза 2 (Полная функциональность - 3-5 дней)
1. ✅ SEO поля в статьях
2. ✅ Планирование публикации
3. ✅ Массовые операции с комментариями
4. ✅ Детальная аналитика

### Фаза 3 (Улучшения UX - 1-2 дня)
1. ✅ Автосохранение черновиков
2. ✅ Предпросмотр статей
3. ✅ Drag&drop для категорий
4. ✅ Уведомления и подтверждения

---

**Важно:** Начинайте с MVP функций, затем добавляйте расширенные возможности. Все API эндпоинты уже готовы и задокументированы! 