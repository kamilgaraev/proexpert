# ИИ-сметчик МОСТ: roadmap

Обновлено 24.10.2026. Исходное состояние и E/F-доказательства: [аудит](AI_ESTIMATOR_AUDIT.md); охват 20 объектов: [универсальность](AI_ESTIMATOR_UNIVERSALITY.md); владельцы: [агенты](AI_ESTIMATOR_AGENTS.md). Исторический аудит не переписывается под новые результаты. Документ ниже описывает текущую очередь, а не историю каждого коммита.

## Главный результат

Универсальный сметчик должен пройти один обязательный путь: **неизвестная технология → конкретный вопрос → ответ и документы → независимая автоматическая проверка → сохранение знания → повтор с проверкой применимости**. Это основной приоритет R05/R07 наравне с достоверными числами. Использовать ProjectModel, Decision, Learning и RAG; второй движок, fine-tuning и объектные шаблоны не нужны.

LLM понимает описание, объект, технологию и неопределённость. Детерминированный код проверяет структуру, единицы, геометрию, нормативы, цены, источники и допустимость расчёта. Regex/словари служат точным кодам и нормализации, а не универсальному русскому NLP. Неоднозначность требует уточнения, без выдуманного состава.

Различать подтверждение человеком, соответствие конкретному источнику и инженерную применимость. Совпадение характеристики с ячейкой доказывает только это совпадение. Другая LLM не является независимым подтверждением. Недостаток основания — unresolved, противоречие — failed. Локальное допущение не становится общим правилом, положительным рейтингом или обучающим примером.

## Текущая доказательная граница

| Задача | Что достигнуто | Что ещё не принято |
| --- | --- | --- |
| R01, P0: физическая геометрия | Защиты и регрессии выпущены ранее; исходный дефект F01 описан в аудите | Полное извлечение геометрии произвольного чертежа/CAD и точность всех конструкций |
| R02, P1: универсальное понимание | Убрана молчаливая house-подстановка; existing Composer используется для семантических intents; «Монтаж стендов» защищён регрессией. Requirement-key guard выпущен PR1668 | Factory без Composer остаётся fail-closed для неизвестного состава; 20 объектов не прошли продуктовую приёмку |
| R03, P1: экспорт; R10, P2: разделы норм | Локальные исправления и проверки выпущены ранее | Полный production export/apply и наличие всех нормативов |
| R05, P0/P1: scope и reuse | Изоляция записи, current-source/answer/work guards, literal source claim через existing Learning выпущены | Инженерная достоверность технологии и переносимый положительный knowledge loop |
| R07, P1: неизвестная технология | В production QA подтверждены вопрос, ответ с документом, reload/amend/revoke и сохранность ответа после Generate | Session92 после JSON release получила работу и конкретный вопрос; положительная инженерная запись/reuse на проде не получены |
| R04, P1: сквозная приёмка | Один независимый QA, общий расходный guard; отдельные HTTP/native PostgreSQL проверки | Полный сценарий с нормативами, ценами, экспортом и обычной сметой ERP |
| R06/R09, P1: комплексы и изменения | Текущие entity/scope/invalidation guards используются | Два корпуса с общей системой и доказанный частичный пересчёт без потери соседних строк |
| R08, P1: точность и нормативы | Нормализация единиц, exact decimal import и DB resource coefficient исправлены | Официальное покрытие каталога/редакций, инженерный oracle неизвестной технологии и полный финансовый пилот |

Недавние проверенные поставки:

| PR | Проверенный source head и результат | Интеграция / граница доказательства |
| --- | --- | --- |
| [1680](https://github.com/kamilgaraev/proexpert/pull/1680) | `8cde7fcdc28ca902a834c7669ddd3364c3debc16`; Codex Review completed без оставшихся замечаний; Composer 163/2284, native PG 3/255 | main `b3ef40e33a42c3960602963f66d06db3369ddee7`, deploy40007276986 SUCCESS. Только proposed/source correspondence passed/engineering unresolved; не numeric authority, training или global rule |
| [1678](https://github.com/kamilgaraev/proexpert/pull/1678) | `0b8f84f407eea13335803e27e7b58c603a344759`; Review completed; unit48/101, PG3/58 на исходном code tree, runtime blobs сохранены | main `0d97b5662ba957321ca902c232ea7b7a7adf3988`, deploy40001182419 SUCCESS. Отказ quote объясняет provider required/available; cap и API не ослаблены |
| [1683](https://github.com/kamilgaraev/proexpert/pull/1683) | `b1ad86920b85ee54b70fc162e7ab527c8c1b1b9e`; Review completed; pure26/196 | main `ce8981b98fff3b8c6e7df5b6493ca455b1790ff1`, deploy40006001436 SUCCESS. Сохранение schema-valid decimal в parser/DTO, не доказательство официального коэффициента |
| [1686](https://github.com/kamilgaraev/proexpert/pull/1686) | `6bc5a91aaf8e3f54fbde2db42c2662330a2067c4`; Review completed; unit82/425, canonical PG3/89 | main `2922b746c2042e88b5f07e67ec46ba5f103bcb84`, deploy40010683989 SUCCESS. DB decimal сохраняется до ResourceAssembly; controlled pin drift проверен, persisted old confirmation/full HTTP money этой поставкой не доказаны |
| [1687](https://github.com/kamilgaraev/proexpert/pull/1687) / [admin824](https://github.com/kamilgaraev/prohelper_admin/pull/824) | `871aa519ece1230598eb76ca15e5801bdab56cd2` / `2d12a50096b01dc472ad4c4460f42ba09dbe23dd`; оба Review completed; backend native9/531, UI116 tests | main `2e5515948212e24fca47d58c2d7a42d39c91f8b3` / `d64719f9e850b61944de61eca49b91c6512f6129`, deploy40015092204/40017197885 SUCCESS; public releases/ready200 проверены. Конкретная характеристика и fresh source proof доступны по view; инженерная применимость остаётся unresolved |
| [1689](https://github.com/kamilgaraev/proexpert/pull/1689) | `bf74b5a2fe8c1e5c03e8a79a3accbb2c0b602f67`; exact-head Review completed; native22/200, cumulative1/84 | main `a416c832a266afbf98dfbe01dbe77f533a6d337a`, deploy40021051529 SUCCESS. Один actual provider retry получил JSON, работу и вопрос; это не доказательство инженерной точности |

Числа тестов приведены как tests/assertions. Provider checks/statuses указанных PR были пусты; успешный CI не приписывается. Полные логи, manifests и восстановимые bundles находятся в каталоге задачи координатора вне checkout. Завершённые backend-деревья убраны после проверенного deploy. У admin824 остались local branch и частично удалённый каталог: ordinary worktree removal завершился Filename too long, дополнительная очистка отклонена автоматической проверкой. Recovery bundle сохранён; полная чистота не заявляется.

## Ближайшие семь результатов

### 1. R05/R07/P1 — связать точный параметр, ответ и документ

- **Проблема/доказательство:** текстовый work-ответ и отдельный manual numeric fact не обеспечивали общий current binding параметра, работы и документа. Связанный backend-путь проверен на checkpoint `199028692e7ccdf7a8cbc52f4a4167058c3cf80c` (canonical PG16/SDK mock HTTP3/328; plain static0, configured Larastan BLOCKED); production QA ещё требуется. Число не извлекается regex из свободного ответа.
- **Результат/минимум:** существующий вопрос принимает точное значение/unit/basis и выбранный текущий native operand. A сохраняет human answer и два отдельных unresolved GenericFacts через carrier B. Первый predicate minimum_length проверяет единицы и точное сравнение при явно указанной человеком трактовке источника как минимума. Engineering остаётся unresolved, numeric_inputs_verified=false. Существующие source-карточка и Learning используются без нового LLM-прохода.
- **Владелец/зависимости:** A — Questions/admission/Learning/UI и одна узкая новая CHECK migration; B — ProjectModel carrier, exact comparison и read-only native descriptor. Shared контракт утверждает главный. Literal ai_role_run и server-derived estimate_session различаются; origin component NULL-safe, proposed/nonpositive/normless, rollback сохраняет историю.
- **Приёмка:** два named параметра не схлопываются; exact decimal/unit сохраняются; replay идемпотентен; подмена definition/work/entity/org, stale/revoke/source replacement исключают зависимое использование. Human measurement и независимый source operand различимы. Документная ссылка и валидный scalar не создают numeric/engineering/training authority.

### 2. R05/R07/R08/P1 — первое независимо проверяемое инженерное знание

- **Проблема/доказательство:** существующий source claim удостоверяет строку документа, а не допустимость монтажа. Исторический oracle118чел.-ч/100м³ не доказывает связь с текущим dataset, редакцией или неизвестным оборудованием.
- **Результат/минимум:** после связанного typed consumer получить отдельный current source operand и проверить одно полезное условие существующей арифметикой/единицами. B реализует generic length carrier в существующих Fact/vocabulary/repository/proof, без нового движка/DSL. Его арифметический bool не равен инженерному passed. GEA HYGIA K manual с порогом102mm — только ограниченный источник для проверки контракта, не доказанная часть synthetic доильной установки; applicability требует отдельного основания.
- **Владелец/зависимости:** B — predicate/oracle/числа; A — запись, вопрос, current reuse; главный — один согласованный контракт и единственный автор shared файлов.
- **Приёмка:** passed/unresolved/failed с объяснимым основанием; недостаточный источник не считается ошибкой. Проверены несовместимая единица/условие, новая редакция, отзыв, другой объект и организация. Реальный положительный повтор предлагает claim только в подходящем контексте; numeric/source/engineering результаты раздельны. Mock/oracle/live provider явно различаются.

### 3. R04/P1 — завершить один ограниченный production pilot

- **Проблема/доказательство:** QA91 подтвердил сохранность ответа и локального assumed количества3, но не норму/цену/engineering/export/apply; QA92 после JSON release получил работу и вопрос, источник B2/C2 ещё не принят человеком. Metadata fingerprints не доказывают точное содержимое live input.
- **Результат/минимум:** продолжить того же одного QA после интеграции исправлений и успешного deploy. Проверить вопрос/документы/независимый результат/сохранение/reuse, затем расчёт и экспорт только при наличии действительных оснований.
- **Владелец/зависимости:** главный + тот же QA; A/B устраняют подтверждённые блокеры. Не создавать второго QA или искусственный positive через правку старого ответа.
- **Приёмка:** UI и server receipts согласованы; replay/stale/tenant negatives; нет выдуманных объёмов/норм/цен. Общий cap500ед./90₽ сохраняется. Перед каждым paid шагом свежие release/access/budget/source gates и котировка; ERP apply отдельно допускается только для проверенного сценария.

### 4. R02/P1 — понятный вход для неизвестного состава в Factory

- **Проблема/доказательство:** `FactoryDraftBuilder` не вызывает Composer; пустая оценка fail-closed, а полного clarification handoff нет (F02/E06, исходный R02 trace).
- **Результат/минимум:** после первого knowledge pilot связать существующий Factory prerequisite с existing universal setup и явным выбором разделов/approval. Не вводить второй семантический проход ради обхода.
- **Владелец/зависимости:** A; главный согласует только необходимый UI/API контракт. B сохраняет quantity/financial guards.
- **Приёмка:** неизвестный объект не получает house/fake work/ложную completeness; пользователь понимает следующий шаг; frozen inputs и source proofs не копируются с другой сессии.

### 5. R06/P1 — два сооружения и общая система в одной модели

- **Проблема/доказательство:** ProjectModel имеет entity/work scope, но архитектурная матрица не является runtime-доказательством комплекса (E08/E10). Общая сеть может потеряться или посчитаться дважды без явной принадлежности. В production QA для неизвестной equipment-работы chooser предложил помещение: WorkEntityScopePolicy::types() возвращает room по умолчанию, а native review создаёт отдельную document-local equipment entity. Их связь с работой не доказана.
- **Результат/минимум:** добавить только недостающую stable identity/связь в текущую ProjectModel, без второго project graph; fixture два корпуса плюс общая система. Сначала убрать принудительный room fallback для неизвестной работы и явно разделить пространственную привязку и идентичность оборудования; использовать текущую ProjectModel.
- **Владелец/зависимости:** A — identity/планирование; B — числовые consumers. Главный утверждает shared shape после первого knowledge pilot.
- **Приёмка:** состав и источники различимы, общая работа учитывается один раз, reorder/reload/change не меняют identity; неподтверждённые подсистемы остаются вопросами. Не создавать20 специализированных шаблонов.

### 6. R09/P1 — доказанный адресный пересчёт

- **Проблема/доказательство:** current/source fences существуют, но полная сохранность соседних draft rows при scoped rebuild не принята. Наличие invalidation API не доказывает корректную dependency closure.
- **Результат/минимум:** на текущих corrections/targeted rebuild выполнить один fixture изменения исходного факта и ограничить пересчёт доказанно затронутой областью; до этого явно использовать полный пересчёт.
- **Владелец/зависимости:** A — контекст/closure; B — derivation/proof; после R06 или на уже однозначном entity scope по решению главного.
- **Приёмка:** старый proof не применяется; незатронутые rows/решения/пользовательский ввод сохранены; execution trace подтверждает область. Не выдавать full rebuild за partial.

### 7. R08/P1 — нормативный и финансовый приёмочный pilot

- **Проблема/доказательство:** FSNB source2026-05-07 parsed102536 rows с ресурсными ценами, но retrieval rollout failed/pending; исторический SQLSTATE не установлен. 55987 canonical unit NULL и156 защищённых immutable norms ограничивают backfill. Наличие каталога не доказывает применимость каждой нормы.
- **Результат/минимум:** завершить existing HTTP-money fixture с нецелым coefficient/price, точным rounding и защитой старого confirmation при source drift. Затем согласовать безопасный план включения поиска и independently reviewed нормативный fixture с dataset/edition/unit/conditions/price source. Диагностика backfill остаётся read-only до отдельного разрешения на исправление/запуск; не чинить production через SSH.
- **Владелец/зависимости:** B — нормы/ресурсы/цены/oracle; главный — разрешённый выпуск и приёмка; A — current entity/work context.
- **Приёмка:** pins и происхождение чисел воспроизводимы; смена количества/нормы/цены отклоняет stale подтверждение. Selected CSV/XLSX/apply сохраняют состав и net; gross/НДС/резерв проверяются отдельно. Missing norm/price остаются unresolved, не нулём или скрытым аналогом. Для больших смет сначала измерить реальные память/время/calls в существующем Benchmark; ceiling512 не объявлять поддержкой тысяч строк.

## Правила выпуска и QA

P0 — подтверждённый риск неверных чисел, нарушения целостности или tenant isolation; P1 — необходимые универсальные сценарии и проверяемые пилоты; P2 — удобство/охват/оптимизация после измерений. При появлении подтверждённого P0 он опережает очередь. Ошибка выполнения без установленных последствий не получает автоматически статус security или финансового инцидента.

Одна задача — одна ветка/worktree, один автор shared контракта. Проверки по риску, фактические логи, независимый peer для сложного блока. Затем завершённый Codex Review актуального полного remote SHA со всеми разобранными обсуждениями, squash в main, штатный deploy и public readiness. Новые коммиты требуют нового ревью. Таймаут/успешный CI/отсутствие комментариев не заменяют итог ревью. После выпуска сохранить recovery bundle, проверить точный merged source tip и убрать свою ветку/worktree.

Срез QA23.10.2026 19:34UTC: QA92 failed, reserve545 cancelled/consumed0, provider расход0.087386₽; shared remaining485.5ед./holds0. Это исторический снимок, не разрешение на новый расход. Баланс интерфейса не заменяет ledger/provider readback. Нормативный backfill, production записи и скрытые paid retry не выполнялись.
