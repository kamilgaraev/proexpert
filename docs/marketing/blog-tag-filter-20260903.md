# Точная фильтрация публичного блога по теме

Дата: 2026-09-03. Продукт: МОСТ.

## Граница изменения

Изолированный backend worktree `prohelper/.worktrees/marketing-blog-tag`, ветка `fix/marketing-blog-tag-filter-20260903` от `bd46c4d6d`. Основной checkout, схемы, модели, маршруты и frontend не изменялись. Публикация и подключение фильтра на сайте выполняются отдельным блоком после независимого ревью.

## HTTP-контракт

Существующий `GET /api/v1/blog/articles` принимает `tag_slug`, `page`, `per_page`, `category_id`, `search`.

- `tag_slug` — точный slug активной темы маркетингового блога. Частичное совпадение имени темы или вхождение слова в текст статьи не заменяет связь статьи с темой.
- Выборка включает только marketing-статьи из действующего scope published. Черновики, будущие публикации и статьи холдингов исключены. Опубликованная статья с noindex остаётся публичной.
- Существующая пустая активная тема: 200, пустой data.data, total 0, current_page 1, last_page 1.
- Неизвестная, неактивная или holding-тема: 404 через LandingResponse.
- Невалидные параметры: 422 через LandingResponse с errors; проверяются тип, длина и диапазон. page положительный integer до 2147483647, per_page 1–24 (по умолчанию 12), category_id положительный integer либо null, search строка до 200 символов либо null; slug непустой, до 255 символов, буквы/цифры/дефис/подчёркивание.
- Пагинация стандартная Laravel, сортировка published_at DESC, id DESC. Фильтры сохраняются в first/last/prev/next. Запрос страницы за пределами диапазона возвращает 200 с пустым data.data и честными current_page/last_page/total; сайт может на основании этого показать 404. Накопление предыдущих страниц не выполняется.
- category_id и search пересекаются с точной темой.

## Архитектура и файлы

- `app/Http/Requests/Blog/PublicBlogArticlesRequest.php`: HTTP-валидация и стандартизированные ошибки.
- `app/Http/Controllers/Api/V1/Blog/PublicBlogController.php`: validated-параметры в сервис, 404 темы, безопасный набор параметров в логах.
- `app/Services/Blog/BlogPublicService.php`: проверка доступности темы, whereHas по ID связи, явная страница и ссылки с фильтрами.
- `lang/ru/blog_cms.php`: русские сообщения и названия полей валидации.
- `tests/Feature/Api/V1/Blog/PublicBlogTagTest.php`: отдельный PHPUnit feature-класс с RefreshDatabase и 22 сценариями. Существующий PublicBlogSeoTest не изменён.

## Проверки

- `php -l` пяти PHP-файлов: PASS до замены тестовой оболочки Pest на PHPUnit; окончательная версия теста проверена Pint/PHPStan и запуском PHPUnit.
- `php vendor/bin/pint --test` пяти изменённых PHP-файлов: PASS. После него изменено только строковое имя второго тега в тестовом fixture, без изменения формата.
- `php vendor/bin/phpstan analyse --no-progress --memory-limit=1G` Controller, Service, Request и PublicBlogTagTest: PASS, no errors. После него изменено только имя тега в fixture.
- Канонический `tests/Runtime/run-postgres-tests.ps1 -TestPath tests/Feature/Api/V1/Blog/PublicBlogTagTest.php`: 22 теста, 117 assertions, 21 успешно, один fixture error (уникальность blog_tags.name). Второй тег получил собственное имя; выполняется узкий повтор только test_exact_tag_excludes_unpublished_and_foreign_articles. Результат ниже будет обновлён после завершения.
- Первоначальная Pest-оболочка теста не поддерживалась phpunit launcher; исправлена на штатный PHPUnit-класс, launcher не менялся.
- Gortex impact: MEDIUM, 1 прямой/6 транзитивных зависимостей; verify_change для FormRequest-сигнатуры не выявил нарушений. detect_changes подтвердил изменённые tracked-файлы и нашёл новый тест. get_test_targets не смог вывести HTTP-тесты из динамического маршрута. check_guards: правил нет.
- Gortex review tracked-файлов: 0 findings, итог REVIEW из-за MEDIUM blast radius и неполных тестовых рёбер. Новые Request/Test пока untracked и требуют явного чтения независимым reviewer, git diff их не включает.

## Окружение и ограничения

Зависимости установлены в самом worktree по composer.lock без scripts; vendor не копировался и не связывался. На Windows использованы ignore-platform-req для ext-pcntl/ext-posix, необходимых Horizon, но не этим тестам. Horizon не проверялся. Lock не изменялся. Composer сообщил существующие PSR-4 предупреждения посторонних тестов/stubs; их исправление вне блока.

DB-проверки используют только отдельный Docker Compose проект most-postgres-tests на 127.0.0.1:55433 и изолированные most_phpunit_*_testing базы, создаваемые bootstrap. Рабочие и production-базы не затрагивались. Ручные миграции не запускались.

Временный stale graph после committed форматной мутации исчез без повторной записи и без изменения регистрации Gortex. Production-код после проверки не менялся. Полный backend suite не запускался: проверяется ограниченный контракт публичной ленты.
