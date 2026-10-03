# Цены ресурсов: продолжение проверки медленных SQL 3 октября 2026

После PR #878/#876 production работает на b17fffd0. В снимке slow-log в 09:15 МСК за два часа найдено 3582 медленных страницы estimate_resource_prices из 3621 события. Среднее по просмотренным медленным страницам около 1,17 с, максимум 26,047 с. Файл читался ограниченным хвостом; двухчасовое окно покрыто, общие числа за день неполны.

Отдельный базовый интервал 09:24:45–09:34:45 МСК: 484 медленные страницы, среднее 1192,41 мс, p95 1382,79 мс, максимум 2006,95 мс. Все события относятся к одному RefreshRagCoverageJob. Cursor увеличивается с 9135215 до 9681244, повторов нет. Bindings/trace_id полные во всех 484 событиях. Эти средние относятся только к SQL выше порога 500 мс.

## Причина

OrganizationReportingRagSource::collectForOrganization вызывает scopedQuery для global каталогов через lazyById(50). В resource price nullable construction_resource_id проверялся как NULL OR IN (полный опубликованный каталог ресурсов). Production EXPLAIN без ANALYZE показал SubPlan Materialize на 111199 resource IDs. Первая и следующая страницы имеют Total Cost около 2337396, 121/123 JIT-функции. Оценка планировщика и наличие JIT не измеряют долю JIT во времени.

Это чтение справочника, отдельное от исправленной compatibility-проверки chunks. Полный refresh каждой организации снова обходит каталог: работа масштабируется как страницы × проходы. В наблюдённом десятиминутном job не обнаружено повторов одного cursor; это не доказательство отсутствия retries во всех jobs.

## Исправление

Для approved_estimate_resource_price используется nullable scalar SELECT EXISTS проверка parent по PK construction_resources.id. Публикационные ограничения parentQuery и bindings сохраняются. Размер страницы, ASC id keyset, модель embedding, tenant identity и workflow не меняются; результаты фильтруются до LIMIT.

Сохраняются три последних пригодных datasets по source_type; parsed, finished_at, rows_imported > 0, errors_count = 0 и порядок finished_at DESC/id DESC. Regional price требует активную версию/activation, supported region и совпадение region/zone/period. Собственный dataset цены проверяется только в нерегиональной ветке; dataset цены и ресурса могут различаться. Base price должна быть положительной; NULL resource допустим.

На production только EXPLAIN вариантов SQL, без выполнения выборки или изменения кода сервера. Plain EXISTS дал Total Cost 14662,79 / 14663,26 без JIT и Materialize; parent проверяется по construction_resources_pkey с оценкой одной строки. Scalar EXISTS дал 14664,70 / 14665,18 без JIT и Materialize. В изолированном PostgreSQL 16 plain EXISTS всё ещё планировал чтение каталога из 20000 строк; скалярная форма нужна для сохранения lookup по конкретному parent. Регрессия проверяет этот сценарий, а не только порог стоимости.

Новых индексов и миграций нет: PK parent уже используется в предложенной форме. Добавление ещё одного индекса не устранило бы повторное формирование полного каталога.

## Проверки

Семантическая регрессия охватывает две страницы с допустимыми строками среди недопустимых: NULL resource, current/stale/unfinished/error/empty datasets, одинаковый finished_at с tie-break по id, разные datasets цены и ресурса, региональные active/unactivated/draft/unsupported версии и несовпадающие dimensions. Проверяется порядок и полный обход, два SQL на 67 допустимых цен, global scope организаций и отказ при org=0/project scope.

Регрессия плана использует 20000 ресурсов/цен и work_mem=64kB в изолированном PostgreSQL. Кроме порога стоимости и отсутствия JIT, проверяет, что lookup construction_resources не планирует чтение всего каталога. Один порог стоимости на этом объёме не выявлял старую форму; проверка cardinality уточнена. Production ANALYZE не выполнялся.

Старый план воспроизведён регрессией: 20000 строк ресурсов вместо lookup одной строки, тест завершился failure. После scalar EXISTS целевой набор SlowSqlQueryPlanTest + AssistantOrganizationReportingTest через tests/Runtime/run-postgres-tests.ps1: 19 тестов / 359 assertions, exit code 0, 3:47. Larastan по трём изменённым PHP-файлам, PHP syntax и diff checks прошли. Зависимости установлены из lock без обновления; локально на Windows проигнорированы только недоступные pcntl/posix расширения. Миграций и изменений CI/CD нет. Выпуск выполняется штатным pipeline, затем проверяются live-план и свежие события.
