<?php

/*
|-----
| Schedule Management Routes
|-----
|
| ❗ ВАЖНО: Маршруты интегрированы в routes/api/v1/admin/project-based.php
| Этот файл НЕ используется, т.к. все project-based routes централизованы
|
| Для добавления новых маршрутов редактируйте:
| routes/api/v1/admin/project-based.php -> секция "PROJECT EVENTS CALENDAR"
|
| Маршруты календаря событий:
| GET    /api/v1/admin/projects/{project}/events/calendar    - Календарное представление
| GET    /api/v1/admin/projects/{project}/events/upcoming    - Ближайшие события
| GET    /api/v1/admin/projects/{project}/events/today       - События сегодня
| GET    /api/v1/admin/projects/{project}/events/statistics  - Статистика
| GET    /api/v1/admin/projects/{project}/events             - Список (pagination)
| POST   /api/v1/admin/projects/{project}/events             - Создать
| GET    /api/v1/admin/projects/{project}/events/{event}     - Показать
| PUT    /api/v1/admin/projects/{project}/events/{event}     - Обновить
| DELETE /api/v1/admin/projects/{project}/events/{event}     - Удалить
| GET    /api/v1/admin/projects/{project}/events/{event}/conflicts - Конфликты
|
*/
