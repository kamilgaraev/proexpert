# Task 3 — граница комплектации при отсутствии закреплённой нормы

## Исходное состояние

- Рабочий репозиторий: `prohelper`.
- Исходный HEAD: `0a1c166bad1fbff384a3d5d31675af15cee61e52`.
- Контракты, миграции, session 58 и estimate 414 не изменялись.

## TDD: RED

До production-правок добавлены три сценария:

1. `QuantityCoverageWarningTest` проверяет допустимость `normative_candidate_missing`; существующий тест покрытия переводов автоматически проверяет русское сообщение для каждого допустимого значения.
2. `EstimateCompletenessProfileTest` проверяет, что предупреждение по кровле формирует `confirmed_scope_only` и единственный структурированный gap.
3. `PipelineStageFunctionalTest` изолированно запускает `MatchNormativesStage` с modern pin, у которого `candidate_ids_by_work_item['roof-covering']` — пустой список. Сценарий проверяет удаление позиции, переиндексацию, единственное предупреждение и отсутствие `normative_not_found` или review-строки.

Команда RED:

```powershell
php vendor/bin/phpunit tests\Unit\EstimateGeneration\Quantities\QuantityCoverageWarningTest.php tests\Unit\EstimateGeneration\Quality\EstimateCompletenessProfileTest.php tests\Unit\EstimateGeneration\Pipeline\PipelineStageFunctionalTest.php
```

Результат: exit code `1`, 3 ожидаемых failures:

- `normative_candidate_missing` не принимался `QuantityCoverageWarning`;
- `gaps` отсутствовал в профиле полноты;
- работа `roof-covering` оставалась в `work_items`.

После self-review добавлен отдельный RED для семантической дедупликации уже представленного предупреждения с `message`:

```powershell
php vendor/bin/phpunit --filter it_excludes_a_work_item_and_records_a_scope_boundary_when_its_modern_pin_has_no_candidate tests\Unit\EstimateGeneration\Pipeline\PipelineStageFunctionalTest.php
```

Результат: exit code `1`, ожидалась одна запись предупреждения, фактически было две. Это подтвердило необходимость дедупликации по `quantity_key`, `reason`, `package_key`, а не по полному массиву.

## TDD: GREEN и проверки

Команда GREEN:

```powershell
php vendor/bin/phpunit tests\Unit\EstimateGeneration\Quantities\QuantityCoverageWarningTest.php tests\Unit\EstimateGeneration\Quality\EstimateCompletenessProfileTest.php tests\Unit\EstimateGeneration\Pipeline\PipelineStageFunctionalTest.php
```

Результат: `OK (8 tests, 215 assertions)`.

Дополнительно выполнено:

```powershell
php -l app\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\MatchNormativesStage.php
php -l app\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityCoverageWarning.php
php -l app\BusinessModules\Addons\EstimateGeneration\Services\Quality\EstimateCompletenessProfile.php
php -l lang\ru\estimate_generation.php
php -l tests\Unit\EstimateGeneration\Pipeline\PipelineStageFunctionalTest.php
php -l tests\Unit\EstimateGeneration\Quality\EstimateCompletenessProfileTest.php
php -l tests\Unit\EstimateGeneration\Quantities\QuantityCoverageWarningTest.php
php vendor/bin/phpstan analyse app\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\MatchNormativesStage.php app\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityCoverageWarning.php app\BusinessModules\Addons\EstimateGeneration\Services\Quality\EstimateCompletenessProfile.php --memory-limit=1G --no-progress
```

Результат: синтаксис всех семи файлов корректен; PHPStan: `No errors`.

## Затронутые файлы

- `app/BusinessModules/Addons/EstimateGeneration/Pipeline/Stages/MatchNormativesStage.php`
- `app/BusinessModules/Addons/EstimateGeneration/Quantities/QuantityCoverageWarning.php`
- `app/BusinessModules/Addons/EstimateGeneration/Services/Quality/EstimateCompletenessProfile.php`
- `lang/ru/estimate_generation.php`
- `tests/Unit/EstimateGeneration/Pipeline/PipelineStageFunctionalTest.php`
- `tests/Unit/EstimateGeneration/Quality/EstimateCompletenessProfileTest.php`
- `tests/Unit/EstimateGeneration/Quantities/QuantityCoverageWarningTest.php`
- `.superpowers/sdd/task-3-evidence-bound-report.md`

## Self-review

- Новая граница применяется только когда существует `candidate_ids_by_work_item`; legacy pin с `null` продолжает существующий глобальный подбор и путь `normative_not_found`.
- Для modern pin пустой набор кандидатов добавляет предупреждение в соответствующую локальную смету, удаляет только текущую работу и переиндексирует затронутую секцию.
- Дедупликация использует контрактные поля предупреждения и не создаёт вторую запись при наличии presentation-поля `message`.
- `EstimateCompletenessProfile` возвращает `gaps` для каждой области, переносит только валидные package-scoped предупреждения, дедуплицирует пары и добавляет `document_takeoff_missing` лишь при отсутствии warning-gap.
- Покрытием теперь считается только `priced_work` с `pricing_status === 'calculated'`; review, исключённые и нерассчитанные позиции не закрывают обязательную работу.
- Не создаются количества, нормы, ресурсы, ручные строки или изменения договорной логики.

## Коммит

- `def08b192c4b17d60a005c204865cf19fd1713c8` — `feat[lk]: добавлена граница отсутствующей нормативной расценки`.

## Concerns

- Во время одного отброшенного запуска через `php artisan test` временная смена базового тестового класса загрузила Laravel и попыталась выполнить тестовые SQLite-миграции; команда завершилась ошибкой SQLite `BTRIM`. Изменение базового класса сразу отменено. Далее использовались только изолированные `vendor/bin/phpunit`, PHP lint и PHPStan, без bootstrap Laravel и без DB-команд. Production-файлы и production-данные не затронуты.
