# R16 `supplier_award_competitiveness`: фактическая поставка

## Статус

Завершён и развернут 2026-08-05 по упрощённой архитектуре управленческой отчётности МОСТ. Не создавались отдельные workflow, admission/signing, release ledger, feature flags организаций или другие контуры постепенной публикации.

## Backend

- PR: `proexpert#177`.
- Merge SHA: `52167795a5439f738016efc5675ede763b26feec`.
- Production workflow: `30968914997`, результат `success`.
- Встроенное определение: `SupplierAwardBuiltinPublishedReport`.
- Runtime binding: `SupplierAwardPublishedRuntimeBindingRegistrar` → `SupplierAwardReportBindingFactory`.
- Реальные источники: версионированные решения выбора поставщика и зафиксированные сопоставимые версии предложений.
- Формула: `SupplierAwardFormula`, версия `supplier-award.v1`: разница с минимальной ценой, относительная разница, медианное отклонение и доля участия.
- Scope: организация и разрешённые проекты только из `ReportExecutionContext`; клиент не передаёт `organization_id`, `project_id`, actor, role или произвольный scope.
- Фильтры: обязательные `period_start` и `period_end` в строгом формате даты; неизвестные поля не входят в опубликованный контракт.
- Права: `procurement.supplier_proposals.view`, `procurement.reports.export`, sensitive `procurement.proposal_decisions.view`.
- Доставка: run, immutable snapshot, rows, `decision_count`, `premium_by_currency`, quality, sensitive decision drill-down и CSV/XLSX/PDF runtime export.

## Admin

- PR: `prohelper_admin#19`.
- Merge SHA: `26a5d679ff0476f6f6e5117947cdb2173e4e448d`.
- Production workflow: `30969468613`, результат `success`.
- Отчёт добавлен в единый каталог без деления на базовые/расширенные и без нерабочей карточки.
- Организация и проект отображаются из рабочего контекста; поля ручного ввода или отправки их идентификаторов отсутствуют.
- Реализованы loading, validation, running/progress, empty, ready и error состояния.
- Реализованы русские подписи и единицы, итоги по валютам, таблица, permission-aware детализация и Excel/CSV export.

## Проверки

- Backend: `php -l` всех 13 изменённых PHP-файлов; `git diff --check`; совпадение source fingerprint с материализатором и фильтрованной source universe; production image build и deploy прошли.
- Backend PHPUnit/Larastan локально не запускались: в рабочей копии отсутствовал `vendor`; это ограничение зафиксировано до PR, основной production workflow успешно собрал и активировал точный SHA.
- Admin: `npx tsc --noEmit`; ESLint изменённых файлов; Vitest 7/7; `git diff --check`; CI build, asset verification и activation прошли.
- Локальные migrations, DB-команды, tinker, dev server и frontend build не запускались.

## Операционная документация

В YouTrack Knowledge Base создана статья `180-64` «МОСТ: как работать с управленческими отчётами закупок». Она покрывает серверный контекст, права, запуск, формулы, итоги, детализацию, export, пустые и ошибочные состояния и ежедневный контроль.

## Ограничение доказательства

Production deploy доказывает сборку и активацию точных SHA, но не заменяет авторизованный браузерный smoke с production-данными. Отчёт не создаёт демонстрационные строки; отсутствие завершённых решений в текущем проекте и периоде отображается честным пустым состоянием.
