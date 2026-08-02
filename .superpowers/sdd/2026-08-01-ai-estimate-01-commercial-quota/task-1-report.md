# Отчёт Task 1: каталог квоты AI-смет

## Затронутые файлы

- `config/commercial_limits.php` — цена дополнительной AI-сметы изменена с `5000` до `50000` копеек, при сохранении единицы `estimate` и шага продажи `10`.
- `lang/ru/billing.php` — пользовательское название ресурса: «Дополнительная AI-смета».
- `tests/Unit/Billing/CommercialQuotaServiceTest.php` — добавлена проверка цены, единицы, шага и требования модуля `ai-estimates`; обновлена ожидаемая сумма расчёта.

## Коммит

`fix[admin]: обновлена цена дополнительной AI-сметы`.

## Проверки

- `git diff --check` — успешно.
- `php -l config/commercial_limits.php` — успешно.
- `php -l lang/ru/billing.php` — успешно.
- `php -l tests/Unit/Billing/CommercialQuotaServiceTest.php` — успешно.
- `vendor/bin/phpunit tests/Unit/Billing/CommercialQuotaServiceTest.php` — не запущен: в выделенном worktree отсутствует каталог `vendor` и исполняемый файл PHPUnit.
- `npx tsc --noEmit` в админке — не выполнялся: UI-подзадача намеренно вынесена в отдельный frontend worktree и должна быть интегрирована после backend-коммита.

## Риски

- Изменение цены влияет на новые расчёты и последующие ежемесячные списания дополнительных AI-смет. Суммы уже созданных заказов не изменяются, так как они хранят состав и сумму отдельно.
- Отображение «500 ₽/месяц за единицу» требует отдельной frontend-интеграции в `prohelper_admin`; текущая задача ограничена backend worktree по согласованию.
