# Task 1 — единый серверный каталог коммерческих пакетов

## Статус

DONE_WITH_CONCERNS

Реализован единый серверный каталог из десяти коммерческих пакетов МОСТ. Старые двенадцать JSON-пакетов удалены, публичные варианты `base`, `pro`, `enterprise` заменены единственным внутренним вариантом `standard`.

## Уточнения требований

- В brief была указана сумма 102 900 ₽, но сумма десяти обязательных индивидуальных цен составляет 103 000 ₽. Владелец задачи подтвердил приоритет табличных цен и ожидаемую сумму 103 000 ₽.
- Точный состав модулей был подтверждён владельцем задачи до реализации JSON-каталога.

## TDD evidence

### RED

Команда:

```powershell
$env:APP_ENV='testing'
$env:LOG_CHANNEL='stderr'
php artisan test tests/Feature/Billing/PackageConfigurationIntegrityTest.php tests/Unit/Billing/PackageCatalogValidatorTest.php
```

Результат до реализации: exit code 1, 5 failed / 5 passed / 58 assertions.

Наблюдаемые ожидаемые причины падения:

- каталог возвращал 12 старых slug вместо десяти обязательных;
- каталог содержал старые JSON-файлы;
- отсутствовал вариант `standard`;
- validator contract ожидал 10 пакетов, а получал 12;
- `commercial-proposals` и `tenders` не имели классификации.

### GREEN

Команда:

```powershell
$env:APP_ENV='testing'
$env:LOG_CHANNEL='stderr'
php artisan test tests/Feature/Billing/PackageConfigurationIntegrityTest.php tests/Unit/Billing/PackageCatalogValidatorTest.php --display-warnings
```

Результат после реализации: exit code 0, 7 passed / 373 assertions, 3 warnings.

Все три предупреждения имеют одну существующую инфраструктурную причину: в изолированном worktree отсутствует файл `.env`, поэтому `vlucas/phpdotenv` сообщает о неудачном чтении файла. Предупреждения возникают при загрузке Laravel `TestCase`, не связаны с каталогом и не исправлялись вне scope задачи.

## Проверки

### PHP syntax

Проверены команды `php -l` для:

- `app/Services/Modules/PackageCatalogService.php`;
- `tests/Feature/Billing/PackageConfigurationIntegrityTest.php`;
- `tests/Unit/Billing/PackageCatalogValidatorTest.php`;
- `config/module_packages.php`.

Результат: синтаксических ошибок нет.

### PHPStan

Команда:

```powershell
vendor/bin/phpstan analyse app/Services/Modules/PackageCatalogService.php --memory-limit=1G
```

Результат: exit code 0, `[OK] No errors`.

### Целостность файлов

- `config/Packages` содержит ровно 10 ожидаемых JSON-файлов;
- все JSON-файлы успешно разобраны;
- `git diff --check` не обнаружил ошибок пробелов;
- месячные цены совпадают с подтверждённой таблицей, сумма — 103 000 ₽;
- каждый пакет содержит только реальные module slug;
- коммерческие модули не дублируют foundation-модули;
- зависимости коммерческих модулей замкнуты пакетом и общим foundation;
- старые package slug и варианты `base`, `pro`, `enterprise` отсутствуют в новом каталоге.

## Изменённые файлы

- `app/Services/Modules/PackageCatalogService.php`;
- `config/module_packages.php`;
- `config/Packages/*.json`: удалены 12 старых файлов, добавлены/заменены 10 файлов нового каталога;
- `tests/Feature/Billing/PackageConfigurationIntegrityTest.php`;
- `tests/Unit/Billing/PackageCatalogValidatorTest.php`;
- `.superpowers/sdd/commercial-packages-task-1-report.md`.

## Self-review

- Сервис остаётся единственным загрузчиком серверного каталога и сортирует пакеты по `sort_order`.
- Нормализация ограничена вариантом `standard`, поэтому старые tier-ключи не публикуются даже при ошибочном JSON.
- Foundation сохранён общим в `module_packages.php`; в продаваемых `modules` foundation-slug отсутствуют.
- Матрица модулей соответствует подтверждённому составу и dependency closure.
- Подписки, checkout, entitlement, маршруты, миграции и frontend не изменялись.
- Несвязанный рефакторинг не выполнялся.

## Review fix

### Замечания

- Исходные tier-ключи нормализовались по allowlist и могли молча потерять `base`, `pro`, `enterprise` или неизвестный ключ.
- Integrity test проверял существование и dependency closure модулей, но не фиксировал точную согласованную матрицу `package => modules`.

### RED

После добавления регрессионных unit tests focused suite завершился с exit code 1: 3 failed, 7 passed, 3 environment warnings. Падения подтвердили, что сервис принимал `standard+base`, наборы `pro`/`enterprise`/`unknown` и отсутствие `standard`, а validator не возвращал ошибку.

### GREEN

- `PackageCatalogService` проверяет исходный массив tiers до нормализации и выбрасывает `RuntimeException`, если набор ключей не равен ровно `['standard']` или значение `standard` не является массивом.
- `PackageCatalogValidator` использует тот же контракт и возвращает integrity error для переданных ненормализованных пакетов.
- Unit regression tests покрывают `standard+base`, отдельные `pro`, `enterprise`, `unknown` и пустой набор без `standard`.
- `PackageConfigurationIntegrityTest` сравнивает `assertSame` точную согласованную матрицу модулей всех десяти пакетов.
- Targeted regression run: 3 passed, 20 assertions, exit code 0.
- Focused suite после review fix: 10 passed, 403 assertions, exit code 0; сохранились только три ранее описанных предупреждения отсутствующего `.env`.
- PHPStan для `PackageCatalogService.php` и `PackageCatalogValidator.php`: `[OK] No errors`, exit code 0.
- `php -l` для двух сервисов и двух изменённых тестов: синтаксических ошибок нет; `git diff --check` чистый.

## Concerns

- Focused tests завершаются успешно, но показывают три предупреждения из-за отсутствующего `.env` в worktree. Это существующее состояние тестового окружения вне scope.
- Исходная сумма в brief была арифметически неверна; реализация использует подтверждённую сумму 103 000 ₽ без изменения индивидуальных цен.
