# План исправления production-инцидента PDF

1. Добавить RED-тесты для раздельных token budgets, typed truncation и запрета идентичного terminal retry.
2. Добавить RED-тесты compact targeted schema и сохранения primary при targeted truncation.
3. Добавить RED-тест canonical stored hydration и заменить неверную external hydration boundary.
4. Добавить PostgreSQL regression семи физических usage attempts с пустым object context и идемпотентным replay.
5. Добавить backend outcome regression, разделяющий execution progress, readiness и page counts.
6. Реализовать минимальные backend-изменения, прогнать PHPUnit/PostgreSQL, php-l, Pint и узкий Larastan.
7. Обновить admin types/normalizers/UI и Vitest/MSW для processing, review, terminal failure, breaker и partial success; прогнать tsc, ESLint, Prettier.
8. Провести одно независимое ревью завершённого backend+admin блока, исправить findings и повторить только затронутые проверки.
9. Выполнить push/PR/merge и штатный deploy backend, затем admin; проверить `/ready`, `release.json`, защищённые маршруты и read-only production logs.
