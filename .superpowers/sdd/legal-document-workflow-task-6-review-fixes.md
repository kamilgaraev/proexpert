# Task 6 — исправления замечаний согласования документов

## Результат

Закрыты все восемь замечаний независимого review backend-контура согласования юридических документов МОСТ.

1. Удалено объединённое право `legal_archive.workflow.decide`. Submit, approve, reject, return, reassign и cancel используют единый exact permission mapping; добавлены отдельные view/manage/template permissions, русские labels, RoleDefinitions с минимальными полномочиями и проверки переводов.
2. `WorkflowSummary` теперь содержит вложенные `current_steps`, `problem_flags` и пошаговые `available_action_details`. Каждый активный параллельный шаг имеет собственные action key, target step, CAS-версии, assignee/due/overdue и blockers; просрочка одного шага не блокирует соседний.
3. Submit replay проверяется по отдельному canonical `client_request_hash` до document lock, expected lock, current version и template-head resolution. Разрешён точный replay после изменения mutable state; другой client payload с тем же ключом конфликтует.
4. При resolve/snapshot пересчитывается canonical definition hash шаблона. Snapshot schema v2 содержит exact template identity и canonical override, instance хранит definition hash, а PostgreSQL FK связывает exact template id/tenant/version/hash. Recovery проверяет self hash, persisted template integrity и exact identity.
5. Проверка существования назначения отделена от actor resolver. User target обязан иметь активное membership организации и доступ к проекту; party/external требуют явного проверяющего адаптера. Role resolution использует точные organization/project AuthorizationContext через AuthorizationService без org-only списка ролей.
6. Actor, due date и revision шага защищены моделью и PostgreSQL. Reassign сначала создаёт append-only typed decision, затем выполняет projection update с transaction-local decision capability. Composite/deferred guards запрещают raw update, orphan/fork/gap и несовпадающую цепочку; recovery сверяет всю цепочку с исходным snapshot.
7. Все четыре workflow-миграции объявлены forward-only: каждый `down()` отказывает до удаления таблиц, индексов, ограничений или триггеров, поэтому частичный rollback невозможен.
8. PostgreSQL opt-in gate больше не зависит от внешнего fixture id. Он создаёт disposable schema только в выделенной `_test/_testing` БД, применяет production migrations и проверяет двумя соединениями template advisory/head stream, submit replay/conflicts/active uniqueness, instance contention, parallel stale/activation/recovery, terminal uniqueness и reassign DB guard.

## TDD и проверки

- RED: после добавления новых контрактов получены три ожидаемые ошибки — отсутствующие revision-поля переназначения и неоднозначное действие параллельного шага.
- GREEN: 42 DB-less теста, 313 утверждений.
- Laravel Pint: 25 изменённых PHP-файлов, успешно.
- PHPStan/Larastan: workflow-модели, сервисы, feature- и PostgreSQL contract tests — ошибок нет.
- `php -l`: 25 PHP-файлов, успешно.
- RoleDefinitions JSON и `git diff --check`: успешно.

Миграции и PostgreSQL opt-in gate локально не запускались согласно правилам проекта. Gate требует `LEGAL_ARCHIVE_PG_WORKFLOW_CONCURRENCY=1`, отдельную тестовую БД и явное разрешение DDL.
