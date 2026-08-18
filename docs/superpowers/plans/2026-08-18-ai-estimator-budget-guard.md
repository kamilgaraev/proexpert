# План исправления бюджетной защиты AI-сметчика

1. Добавить forward-only миграцию reservation-полей physical attempts.
2. Добавить RED PostgreSQL-контракты для document/session projection, границы, параллельных reservations и recovery документа 176.
3. Рассчитывать безопасную стоимость следующего vision-вызова по закреплённому price snapshot и реальным token bounds провайдера.
4. Перед `wire_started` атомарно авторизовать и сохранить reservation под session/document locks.
5. Учитывать usage вместо reservation после durable completion; legacy неизвестную попытку оценивать reservation-bound, не безусловной паузой.
6. Снимать ложную legacy-паузу явным действием без увеличения лимита; истинное достижение лимита сохраняет текущую confirmation-инкрементацию.
7. Добавить backend contract и admin MSW-тесты согласованного paused/resume read model.
8. Выполнить целевые тесты, PHPStan изменённых PHP-файлов, frontend typecheck/lint изменённых файлов и собственное ревью diff.
9. Push, PR, merge, штатный deploy и только read-only canary.
