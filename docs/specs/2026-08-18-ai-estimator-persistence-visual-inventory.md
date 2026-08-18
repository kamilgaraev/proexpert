# AI-сметчик: атомарная публикация и визуальный инвентарь планов

Дата: 2026-08-18  
Область: backend МОСТ и админка МОСТ  
Статус: утверждено к реализации

## Контекст и production evidence

### Потеря оплаченного результата

В проекте `52`, сессии `76`, документе `178`, на странице `1` завершились четыре оплаченных запуска ролей: `observer_literal`, `observer_construction`, `observer_risk`, `arbiter`. Результаты ролей сохранены, но processing unit завершился с `document_unit_output_persistence_failed`, `output_count=0`, а page projection осталась в состоянии `failed`.

Точная причина подтверждена production-логом и сохранённым arbiter payload: accepted fact `fact_type=elevation` был спроецирован как `estimate_generation_document_facts.fact_type=elevation`, хотя CHECK таблицы допускает `height`, но не `elevation`. PostgreSQL вернул `SQLSTATE 23514`; транзакция публикации корректно откатилась. Затем запись безопасной диагностики также нарушила CHECK failure-ledger: приложение уже формирует ключи `sql_state`, `database_invariant`, `constraint_identifier`, `invariant_code`, но историческая DB-allowlist их не допускает. Поэтому точная причина не появилась в ledger.

### Потеря предметной информации плана

На странице `5` документа `177` наблюдатели обнаружили санитарные приборы, кухонное оборудование и примечание об условном отображении мебели/оборудования. Арбитраж не сохранил это как отдельный визуальный инвентарь. В результате полезная информация исчезла, хотя она не должна автоматически становиться строками строительной сметы.

## Цели и инварианты

1. Публикация evidence, observation elements, canonical facts, lineage, document facts, page projection, `output_count` и terminal unit state выполняется одной транзакцией под существующими tenant/session и advisory fences.
2. Завершённые role results являются durable input для повторной deterministic publication. Replay не обращается к AI-провайдеру и идемпотентен.
3. Один синтаксически или предметно некорректный AI-item изолируется до SQL-записи. Остальные валидные items публикуются. SQL, constraint, deadlock, scope/collision и прочие системные ошибки не переводятся в пользовательское review и откатывают публикацию целиком.
4. Failure ledger хранит только типизированные безопасные признаки: category, code, boundary, invariant, SQLSTATE class/code, constraint identifier и стабильные fingerprints. Raw exception/message, пользовательский контент и секреты запрещены.
5. Все операции сохраняют organization/project/session/document/page/source-version/evidence fences, collision checks, stop semantics и current-output uniqueness.
6. Визуально распознанный объект сам по себе не создаёт нормативную или ценовую позицию. Нормы, цены, объёмы работ и арифметика остаются серверными.

## Контракт визуального инвентаря

Используется существующая доменная сущность `equipment` и существующее evidence/lineage storage. Новая таблица и второе хранилище не создаются. Page typed projection получает `visual_inventory`.

Каждый элемент содержит:

- стабильный `key` и человекочитаемый `label`;
- `category`: `sanitary_fixture`, `kitchen_fixture`, `equipment`, `furniture`, `unknown_fixture`;
- доказанный `object_type` либо `unknown`;
- `quantity` или `null`, а также признак неопределённости;
- помещение/зону, если доказаны;
- `scope`: `estimate_candidate`, `contextual_only`, `requires_confirmation`, `excluded_by_document_note`;
- evidence locator с точными tenant/project/session/document/page/source-version границами;
- arbitration state/reason и lineage всех поддержавших, условных и отклонённых наблюдений.

Серверная классификация:

- унитаз, умывальник, ванна, душ — `sanitary_fixture`; без спецификации/ВК или решения пользователя имеют `requires_confirmation` и не создают estimate rows;
- кухонная мойка и доказанные инженерные подключения — кандидаты, требующие подтверждения комплектации;
- кухонная мебель и бытовая техника по умолчанию `contextual_only`, а при прямом примечании об условности — `excluded_by_document_note`;
- кровати, столы, стулья, диваны — `furniture`, `contextual_only` либо `excluded_by_document_note`, без estimate rows и без россыпи вопросов;
- недоказанный тип — `unknown_fixture`, `requires_confirmation` только при возможном влиянии на работы, иначе `contextual_only`.

Примечание документа применяется сервером и имеет приоритет над предположением наблюдателя. Cross-document synthesis связывает plan/equipment entities с equipment specification, ВК/ОВ/ЭОМ и решениями пользователя через существующие `native_id`, `cross_document_key`, `position` и room/source связи. Подтверждение спецификацией меняет candidate без повторного Vision.

## Вопросы AI

Вопросы формируются только после этапа обработки документов и cross-document synthesis. По санитарным приборам создаётся один сгруппированный вопрос на помещение/комплектацию, например: «На плане санузла обнаружены унитаз и умывальник. Включить поставку и монтаж?». Контракт содержит понятную причину, влияние на работы/стоимость, рекомендацию, варианты, источник; существующий projector добавляет «Другое» и «Оставить нерешённым».

Условная мебель не порождает вопросы и строки сметы. Подтверждённая спецификацией комплектация не создаёт повторный вопрос.

## API и admin

Backend возвращает typed `visual_inventory` как отдельную часть результата страницы и человекочитаемые русские labels. Технические ключи остаются машинными значениями и не отображаются пользователю.

Админка нормализует контракт и показывает два независимых блока:

- «Обнаружено на плане» — весь полезный визуальный инвентарь и его статус;
- «Будет учтено в смете» — только прошедшие domain gate факты.

Примеры отображения:

- «Санузел: обнаружены санитарные приборы — состав требует подтверждения»;
- «Спальни: условно показано 2 кровати — в строительную смету не включены»;
- «Кухня: обнаружена мойка/оборудование — комплектацию нужно уточнить».

На шаге «Вопросы AI» карточка показывает влияние и переход к source locator. Raw enum/key пользователю не показываются.

## Публикация и replay

`DocumentUnitPublication` строится из сохранённых observer/arbiter payload с точным source scope. До транзакции выполняются bounded parsing, evidence validation, semantic dedup и item quarantine. В транзакции:

1. блокируется processing unit и проверяется source/collision/stop state;
2. берётся существующий session advisory lock;
3. записываются evidence, elements, canonical facts и lineage;
4. заменяется current document/page projection, включая visual inventory;
5. фиксируются `output_count=1` и terminal `completed`.

Любая системная ошибка откатывает все пункты. Повторный replay с теми же role payload и source version даёт тот же current output; конкурентный replay публикует только одну current projection. Stop запрещает новые вызовы, но не удаляет уже сохранённые role outputs.

## Изменения схемы

Исторические миграции не меняются. Добавляется только forward migration, расширяющая закрытый CHECK safe-context ключами, которые уже разрешены application sanitizer. Проекция `elevation/level` использует существующий разрешённый тип `height`; новый `document_fact` enum в БД не добавляется.

## RED → GREEN проверки

### Persistence

- fixture из четырёх сохранённых role payload страницы `1` документа `178` публикуется при нулевом числе provider calls;
- `elevation/level` проецируются в допустимый тип, semantic duplicates объединяются с lineage;
- invalid evidence/item/collision изолируются по контракту;
- SQL/invariant failure откатывает page/current projection и unit terminal state;
- повторная и конкурентная publication идемпотентна и оставляет одну current projection;
- failure ledger принимает безопасный typed persistence context и отбрасывает raw diagnostics.

### Visual inventory

- унитаз+умывальник дают candidates и один downstream question;
- условные кровати/стол дают contextual/excluded inventory, ноль estimate rows и ноль вопросов;
- кухонная мойка и кухонная мебель получают разные scopes;
- примечание «условно» влияет на scope;
- спецификация подтверждает candidate без Vision;
- conflicting observers сохраняются в arbitration lineage;
- один malformed object не уничтожает остальные;
- tenant/source/version/locator fences проверяются;
- backend DTO, admin normalizer/MSW и русское отображение согласованы.

### Regression

Сохраняются semantic dimension labels, rooms+areas, единицы без дублирования «мм мм», честная confidence, semantic dedup, cost guard без ложной паузы и stop semantics с сохранением полученных outputs.

## Проверка и выпуск

Backend: целевые PHPUnit unit/integration, PostgreSQL contract harness, PHPStan изменённых PHP-файлов, Pint.  
Admin: целевые Vitest/MSW, `tsc --noEmit`, ESLint и Prettier изменённых файлов. Frontend build локально не запускается.

После последовательного correctness/security/architecture/UX review изменения публикуются отдельными PR backend/admin, проходят стандартный CI, merge и production deploy. Canary только read-only: `/ready`, exact release SHA, protected endpoint `401`, безопасные production logs/GlitchTip и неизменность sessions `75/76`, documents `177/178`, usage/role runs. Retry, resume, новая смета и платный AI после deploy запрещены.
