# Plan 2 / Task 3 — Processing units

## Реализовано

- Документы AI-сметчика fenced неизменяемой версией `sha256:{checksum}`; загрузка без организации запрещена.
- Добавлены строгие типы единиц: PDF page, spreadsheet sheet, raster image, sketch, CAD drawing, text page.
- Добавлена PostgreSQL-схема processing units с tenant scope, уникальной identity, CAS claim/lease, безопасными failure fields и exact-once связью с document pages. Миграция не запускалась.
- `ProcessEstimateGenerationDocumentJob` заменён на чистый dispatcher без чтения файла/OCR/parser.
- Общий `DocumentProcessingUnitStore` используется production Eloquent и DB-less InMemory реализациями; `ProcessDocumentUnit` не зависит от Eloquent-моделей.
- Unit job передаёт только unit ID + source version, имеет overlap/rate-limit/timeout; lease больше timeout. Pending/expired/failed/unfinalized units восстанавливаются durable recovery job каждую минуту. Horizon имеет отдельные production/local supervisors для долгих unit jobs и короткой maintenance-очереди; их timeout/tries/memory согласованы с jobs.
- Публикация page output и завершение unit происходят одной owner/source/lease CAS-транзакцией. Повторная доставка не создаёт второй output.
- Multi-page PDF с текстовым слоем и multi-sheet workbook читаются один раз при построении manifest; на каждую страницу/лист создаётся отдельный organization-scoped S3 artifact. Unit jobs не скачивают исходный большой объект повторно.
- Scanned PDF без text layer и CAD без geometry renderer переводятся в actionable review до dispatch, без гарантированно падающих unit jobs и без ложных geometry-результатов.
- Aggregate finalizer под document lock побеждает один раз, читает только outputs текущей source version, удаляет stale AI outputs, фиксирует aggregate/ready, затем после commit один раз вызывает session reconcile. Сбой finalizer не откатывает completed unit и чинится повторной доставкой.
- Retry документа выполняется под tenant-scoped locks, удаляет только AI-unit outputs текущего документа и создаёт новый fenced прогон; обычные сметы не затронуты.

## TDD / проверки

- RED подтверждён отсутствующими unit DTO/store и failing contract suite.
- GREEN: processing-unit, queue backpressure, Horizon routing и multi-page PDF contracts — 27 tests / 153 assertions.
- Regression: ordinary-estimate boundary, pipeline checkpoint/atomicity/status boundary, pipeline unit tests — 63 tests / 486 assertions.
- PHPStan/Larastan по затронутому модулю: без ошибок (`--memory-limit=1G`).
- `php -l`: 26 файлов текущего reviewer-fix diff, без ошибок.
- Pint: пройден.
- `git diff --check`: пройден.

## Не запускалось

- Миграции и DB/PostgreSQL integration tests не запускались согласно ограничениям проекта.
- Реальный S3/OCR smoke требует staging credentials и относится к production-readiness gate последующих задач.

## Явная граница следующего этапа

- Для scanned PDF необходим page renderer/artifact provider; до его подключения документ честно требует проверки.
- Для CAD необходим geometry processor; система не заявляет извлечённую геометрию до его появления.
