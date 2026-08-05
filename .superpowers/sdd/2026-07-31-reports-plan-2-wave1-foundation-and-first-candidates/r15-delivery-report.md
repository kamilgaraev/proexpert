# R15 `procurement_cycle`: фактическая поставка

## Статус

Завершён и развернут 2026-08-05 по упрощённой архитектуре управленческой отчётности МОСТ. Отдельные publication workflows, signing, OIDC admission, release ledger и постепенное включение для организаций не используются.

## Backend

- PR: `proexpert#175`.
- Merge SHA: `5d34f3c19f98fb5eeee6b6480cfb595b849dfd95`.
- Production workflow: `30967536992`, результат `success`.
- Встроенное определение: `ProcurementCycleBuiltinPublishedReport`.
- Runtime binding: `ProcurementCyclePublishedRuntimeBindingRegistrar` → существующий `ProcurementCycleReportBindingFactory`.
- Реальные источники: версионированные procurement process events, policy/calendar versions и canonical source snapshot.
- Формула: `ProcurementCycleFormula`, версия `procurement-cycle.v1`.
- Source schema: `ProcurementCycleReportAdapter::SCHEMA_VERSION`.
- Scope: организация из server auth/context; `project_ids` ограничиваются разрешённым `ReportScope`, выход за scope отклоняется.
- Права: `procurement.dashboard.view`, `procurement.reports.export`, `procurement.audit.view`.
- Доставка: run, immutable source snapshot, rows, totals/quality, stage и audit drill-down, CSV/XLSX/PDF runtime export.

## Admin

- PR: `prohelper_admin#18`.
- Merge SHA: `0aada842cedb9dee275707cd32a58748dfecc474`.
- Production workflow: `30968262429`, результат `success`.
- Единый каталог без деления на базовые и расширенные отчёты.
- Организация и проект отображаются из рабочего контекста и не имеют пользовательских полей для подмены.
- Реализованы initial/loading, validation, running/progress, empty, ready и error состояния.
- Реализованы русские подписи, итоговая сводка, таблица, audit drill-down и Excel/CSV export.
- До merge удалён несвязанный Prettier-шум; итоговый admin diff содержит 495 добавлений и 7 удалений в 11 файлах.

## Проверки

- Backend: PHP syntax для 9 изменённых PHP-файлов; JSON parse трёх role definitions; точное совпадение SHA-256 formula/source с candidate contract; `git diff --check`; production image bootstrap и deploy прошли.
- Admin: `npx tsc --noEmit`; ESLint изменённых файлов; Vitest 11/11; `git diff --check`; CI build, asset verification и activation прошли.
- Локальные migrations, DB-команды, tinker, dev server и frontend build не запускались.

## Ограничение доказательства

Production deploy доказывает сборку и активацию точных SHA, но не заменяет авторизованный браузерный smoke с production-данными. Отчёт не использует демонстрационные данные; пустой результат является допустимым состоянием при отсутствии событий закупки в выбранном проекте и периоде.
