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
| R07, P1: неизвестная технология | В production QA подтверждены вопрос, ответ с документом, reload/amend/revoke и сохранность ответа после Generate | Session92 получила работу/вопрос и сохранила принятое описание отдельного оборудования из B2/C2 после reload; положительная инженерная запись/reuse не получены |
| R04, P1: сквозная приёмка | Один независимый QA, общий расходный guard; отдельные HTTP/native PostgreSQL проверки | Полный сценарий с нормативами, ценами, экспортом и обычной сметой ERP |
| R06/R09, P1: комплексы и изменения | Текущие entity/scope/invalidation guards используются | Два корпуса с общей системой и доказанный частичный пересчёт без потери соседних строк |
| R08, P1: точность и нормативы | Нормализация единиц, exact decimal import и DB resource coefficient исправлены | HTTP-money и PostgreSQL pins проверены ниже уровня product pilot; официальная применимость, engineering oracle и полный production финансовый сценарий не приняты |

Недавние проверенные поставки:

| PR | Проверенный source head и результат | Интеграция / граница доказательства |
| --- | --- | --- |
| [1680](https://github.com/kamilgaraev/proexpert/pull/1680) | `8cde7fcdc28ca902a834c7669ddd3364c3debc16`; Codex Review completed без оставшихся замечаний; Composer 163/2284, native PG 3/255 | main `b3ef40e33a42c3960602963f66d06db3369ddee7`, deploy40007276986 SUCCESS. Только proposed/source correspondence passed/engineering unresolved; не numeric authority, training или global rule |
| [1678](https://github.com/kamilgaraev/proexpert/pull/1678) | `0b8f84f407eea13335803e27e7b58c603a344759`; Review completed; unit48/101, PG3/58 на исходном code tree, runtime blobs сохранены | main `0d97b5662ba957321ca902c232ea7b7a7adf3988`, deploy40001182419 SUCCESS. Отказ quote объясняет provider required/available; cap и API не ослаблены |
| [1683](https://github.com/kamilgaraev/proexpert/pull/1683) | `b1ad86920b85ee54b70fc162e7ab527c8c1b1b9e`; Review completed; pure26/196 | main `ce8981b98fff3b8c6e7df5b6493ca455b1790ff1`, deploy40006001436 SUCCESS. Сохранение schema-valid decimal в parser/DTO, не доказательство официального коэффициента |
| [1686](https://github.com/kamilgaraev/proexpert/pull/1686) | `6bc5a91aaf8e3f54fbde2db42c2662330a2067c4`; Review completed; unit82/425, canonical PG3/89 | main `2922b746c2042e88b5f07e67ec46ba5f103bcb84`, deploy40010683989 SUCCESS. DB decimal сохраняется до ResourceAssembly; controlled pin drift проверен, persisted old confirmation/full HTTP money этой поставкой не доказаны |
| [1687](https://github.com/kamilgaraev/proexpert/pull/1687) / [admin824](https://github.com/kamilgaraev/prohelper_admin/pull/824) | `871aa519ece1230598eb76ca15e5801bdab56cd2` / `2d12a50096b01dc472ad4c4460f42ba09dbe23dd`; оба Review completed; backend native9/531, UI116 tests | main `2e5515948212e24fca47d58c2d7a42d39c91f8b3` / `d64719f9e850b61944de61eca49b91c6512f6129`, deploy40015092204/40017197885 SUCCESS; public releases/ready200 проверены. Конкретная характеристика и fresh source proof доступны по view; инженерная применимость остаётся unresolved |
| [1689](https://github.com/kamilgaraev/proexpert/pull/1689) | `bf74b5a2fe8c1e5c03e8a79a3accbb2c0b602f67`; exact-head Review completed; native22/200, cumulative1/84 | main `a416c832a266afbf98dfbe01dbe77f533a6d337a`, deploy40021051529 SUCCESS. Один actual provider retry получил JSON, работу и вопрос; это не доказательство инженерной точности |
| [1698](https://github.com/kamilgaraev/proexpert/pull/1698) / [admin826](https://github.com/kamilgaraev/prohelper_admin/pull/826) | `4f7de30c111dbddca0ea3058e7bba60dd89943b0` / `48185819c7d5f2bb7157ce7c615db7a17154d670`; оба exact Review completed без замечаний; backend PG3/328, UI156 tests | main `75c1dad5f0a130204c511558e9e6483f194e96ac` / `2636d8bfc25188be9aaec3fa7adbe05e87e27ffa`, deploy40033296344/40035599141 SUCCESS. Production форма доступна; current length source в QA92 отсутствует, сравнение/reuse не пройдены. Human minimum interpretation остаётся unresolved/numeric false |
| [1697](https://github.com/kamilgaraev/proexpert/pull/1697) | `ee6b25a31a86dc96cd349f2b861f15963497a906`; exact Review completed. Positive net8.35/VAT1.84/gross10.19 и coefficient drift отказ прошли внутри частичных batches; final price-only1/78 GREEN | main `f125e55c01cfeede7aac8c8beeeb8f12a4fc0553`; tests-only, штатный workflow не запускает deploy. Целые failed batches GREEN не объявлены. Price guard доказывает immutability, а недостигнутый stale-price HTTP не заявляется |
| [1699](https://github.com/kamilgaraev/proexpert/pull/1699) | `82d0102c6c8bd9f6fdac71d520dfe4be5a930f16`; exact Review completed без замечаний; actual PG1/92 | main `6c545992a96e55cf3628d58574811548f7a3728d`; tests-only. SQL edition isolation, отдельный явный norm selection и price-context pins проверены; историческая транскрипция/synthetic context не доказывают актуальную официальную применимость |
| [1702](https://github.com/kamilgaraev/proexpert/pull/1702) | 71d37e380ff31016e48093e5a38d0a4c087ad738; exact Review completed без findings; PG12/292 | main346483b2d3be3613f717acd3abf9cb0e263a498b, deploy40046055334 SUCCESS/ready200. Unknown-room admission закрыт; полный комплекс не доказан |
| [admin828](https://github.com/kamilgaraev/prohelper_admin/pull/828) | fed7f7c1d340977e36c568ae4a88457eecb99c5d; exact Review completed без findings; UI225/225 | maina139fbbcd2acb7e50285f5eceb7e89debe2fb3a4, deploy40049455496 SUCCESS/public release200. Scoped consent/preflight cancellation и sent receipt recovery; backend выпускается отдельно |

R07 document handoff интегрирован: backend [1707](https://github.com/kamilgaraev/proexpert/pull/1707), reviewed head `8e3fd94272e3b703c72e3afec6c383009554cd6e`, main `89898cc98ea1454f34a8c1444696df86ff8ec837`; штатный descendant deploy40051401610 на `9b7f599ce49538bca9abae1653e0d5130b14e92f` SUCCESS, public release/ready200 проверены. Snapshot30/114 и HTTP4/67. B [1703](https://github.com/kamilgaraev/proexpert/pull/1703), reviewed head `139d6dc8de052a012dd95dcf72812eada172c491`, main `ebce592e9496d0263842ec46317f989082ea7759`: connected native target1/149; tests-only без deploy. UI828 выпущен ранее. Оба Codex Review completed для полного source SHA без оставшихся замечаний.

Текущий semantic producer, runtime/test checkpoint `7023dd23337521dde2537b6c29fe96f5d2b69ae5`: существующий construction observer предлагает closed equipment_identity/source_requirement; валидатор, outbound projection, arbitration/reducer и обычный ProjectModel publisher сохраняют proposed descriptor с field locators и server pins. Разные пороги одного excerpt не сливаются; legacy absent/null canonical поведение сохранено. Нового LLM-прохода нет. Actual private PG16: target1/544, полный ObserverEvidenceProjectionPostgresTest5/625; arbitration units49/336. Исходные unit61/457 и provider84/548 зафиксированы до коррекции reducer; изменённый arbitration проверен заново49/336. Это разные наборы, их числа не складываются. Scoped plain PHPStan phpVersion80200 прошёл; configured Larastan остаётся BLOCKED storage_configuration_invalid. Controlled VisionProvider не доказывает live extraction accuracy.

После deploy1707/828 тот же QA92 обнаружил disabled upload: в клиентском нормализованном diagnostic snapshot действия upload_documents нет. Raw HTTP response не получен. Scoped security log содержит upload grants; effective denial, stale worker и server snapshot cache причинами не доказаны. Upload/Cancel/full QA пока не приняты. Следующий безопасный диагностический шаг — один private real-owner HTTP test, затем новая бесплатная проверка после штатного выпуска; production права/кеши/сервисы вручную не менять.

Числа тестов приведены как tests/assertions. Provider checks/statuses указанных PR были пусты; успешный CI не приписывается. Полные логи, manifests и восстановимые bundles находятся в каталоге задачи координатора вне checkout. Завершённые backend-деревья убраны после проверенного deploy. У admin824 остались local branch и частично удалённый каталог: ordinary worktree removal завершился Filename too long, дополнительная очистка отклонена автоматической проверкой. Recovery bundle сохранён; полная чистота не заявляется.

## Ближайшие семь результатов

### 1. R05/R07/P1 — снять фактический блокер загрузки после вопроса

- **Проблема/доказательство:** после reviewed deploy1707/828 QA92 всё ещё видит disabled picker и нормализованный snapshot без upload_documents. Причина неизвестна; серверный admission и owner role definitions разрешают действие, scoped logs содержат grants. Нельзя объявлять это доказанным отказом прав или обходить UI.
- **Результат/минимум:** B добавляет один private HTTP сценарий с реальным organization_owner/активной подпиской, без explicit grant/mock authorization. Сопоставить can/canCurrent, ListResource permissions, operational builder и raw GET без ETag. Если local путь проходит, это не объясняет production автоматически: root продолжает только read-only runtime evidence и обычную повторную проверку после deploy.
- **Владелец/зависимости:** B — независимый тест в existing DocumentAdmissionHttpPostgresTest; A исправляет только доказанный runtime/UI дефект после shared contract GO. Главный держит production диагностику и один QA. Текущий producer сначала проходит review/release.
- **Приёмка:** Review snapshot владельца содержит upload_documents/requires_confirmation, denied/exception различимы диагностически. Production отдельно подтверждает Cancel до хранения, загрузку в прежнюю сессию, текущий ответ/источник и отзыв. Existing upload/reuse/admission/recovery и отдельный fresh quote/Generate сохраняются; ручных grants/cacheclear/restarts нет.

### 2. R05/R07/R08/P1 — независимо проверять требование и его применимость

- **Проблема/доказательство:** current minimum_length сравнивает число с человеческой трактовкой. ComposerSourceClaimValidationService возвращает engineering unresolved/numeric false. Новый document_semantic_proposal уже проводит структурированное предложение через существующий observer/arbitration/ProjectModel path; issuer/current edition/clause и независимый current target/config/conditions record ещё не дают положительного admission. Ready document, URL, checksum, native literal или другая LLM по отдельности этого не доказывают.
- **Результат/минимум:** сначала выбрать реальный первичный источник и контекст, представимые текущей моделью, с воспроизводимым independent predicate. Затем добавить лишь недостающую закрытую association в existing Evidence/Decision и расширить нынешние validator/Recorder/EvidenceService/Factory. Ещё один proposed Learning carrier не нужен. Требование должно сохраняться и переиспользоваться без обязательного observed; human input даёт отдельно условное сравнение.
- **Владелец/зависимости:** A — existing semantic questions, association и Learning/Factory/readback; B — первоисточник/редакция/условия, exact operands/unit и independent acceptance. Главный утверждает единственного автора DTO/schema. Нет второго engine, DSL, объектного обработчика, fine-tuning или дополнительного LLM pass.
- **Приёмка:** три независимые оси: requirement applicability, observed confidence, arithmetic. Отсутствие основания даёт unresolved, доказанное противоречие — failed. С human/assumed input numeric_inputs_verified=false; native literal не физический замер. Reuse повторно проверяет source/revision/target/conditions/tenant/revocation; история сохраняется. Proposed/nonpositive/global-index/training guards не обходятся. QA92 и synthetic102 не положительный инженерный пилот.

### 3. R04/P1 — завершить один ограниченный production pilot

- **Проблема/доказательство:** QA91 подтвердил сохранность ответа и локального assumed количества3, но не норму/цену/engineering/export/apply; QA92 получил работу и вопрос; B2/C2 приняты в operator-assisted synthetic QA как description и отдельная document-local equipment identity. Current Fact1405/Evidence44767 и UI принятия сохранились после reload; технология и количество не подтверждены. Metadata fingerprints не доказывают точное содержимое live input.
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
- **Результат/минимум:** добавить только недостающую stable identity/связь в текущую ProjectModel, без второго project graph; fixture два корпуса плюс общая система. Изолированное исправление unknown-room admission выпущено в PR1702; исходный checkpoint `badf5759638595f80c37b8571fa51e9e554a08d2`: meaningful HTTP RED200 вместо422, canonical PG12/292; independent peer без findings. Новый GET/POST закрыт, старые явные legacy/v2 room bindings перепроверяются прежними guards. PR1702 прошёл exact Review и штатный deploy; это не equipment identity и не доказательство комплекса. Комплексный fixture по-прежнему нужен; использовать текущую ProjectModel.
- **Владелец/зависимости:** A — identity/планирование; B — числовые consumers. Главный утверждает shared shape после первого knowledge pilot.
- **Приёмка:** состав и источники различимы, общая работа учитывается один раз, reorder/reload/change не меняют identity; неподтверждённые подсистемы остаются вопросами. Не создавать20 специализированных шаблонов.

### 6. R09/P1 — доказанный адресный пересчёт

- **Проблема/доказательство:** current/source fences существуют, но полная сохранность соседних draft rows при scoped rebuild не принята. Наличие invalidation API не доказывает корректную dependency closure.
- **Результат/минимум:** на текущих corrections/targeted rebuild выполнить один fixture изменения исходного факта и ограничить пересчёт доказанно затронутой областью; до этого явно использовать полный пересчёт.
- **Владелец/зависимости:** A — контекст/closure; B — derivation/proof; после R06 или на уже однозначном entity scope по решению главного.
- **Приёмка:** старый proof не применяется; незатронутые rows/решения/пользовательский ввод сохранены; execution trace подтверждает область. Не выдавать full rebuild за partial.

### 7. R08/P1 — нормативный и финансовый приёмочный pilot

- **Проблема/доказательство:** FSNB source2026-05-07 parsed102536 rows с ресурсными ценами, но retrieval rollout failed/pending; исторический SQLSTATE не установлен. 55987 canonical unit NULL и156 защищённых immutable norms — исторические counts, их текущее пересечение не установлено. Наличие каталога не доказывает применимость каждой нормы.
- **Результат/минимум:** HTTP-money PR1697 и actual DB norm/price pins PR1699 завершены в своих доказательных границах. Далее согласовать безопасное включение existing поиска и подтверждённую связь официальной редакции с catalog/resources; synthetic pins сами эту связь не доказывают. Диагностика backfill остаётся read-only до отдельного разрешения на исправление/запуск; не чинить production через SSH.
- **Владелец/зависимости:** B — нормы/ресурсы/цены/oracle; главный — разрешённый выпуск и приёмка; A — current entity/work context.
- **Приёмка:** pins и происхождение чисел воспроизводимы; смена количества/нормы/цены отклоняет stale подтверждение. Selected CSV/XLSX/apply сохраняют состав и net; gross/НДС/резерв проверяются отдельно. Missing norm/price остаются unresolved, не нулём или скрытым аналогом. Для больших смет сначала измерить реальные память/время/calls в существующем Benchmark; ceiling512 не объявлять поддержкой тысяч строк.

## Правила выпуска и QA

P0 — подтверждённый риск неверных чисел, нарушения целостности или tenant isolation; P1 — необходимые универсальные сценарии и проверяемые пилоты; P2 — удобство/охват/оптимизация после измерений. При появлении подтверждённого P0 он опережает очередь. Ошибка выполнения без установленных последствий не получает автоматически статус security или финансового инцидента.

Одна задача — одна ветка/worktree, один автор shared контракта. Проверки по риску, фактические логи, независимый peer для сложного блока. Затем завершённый Codex Review актуального полного remote SHA со всеми разобранными обсуждениями, squash в main, штатный deploy и public readiness. Новые коммиты требуют нового ревью. Таймаут/успешный CI/отсутствие комментариев не заменяют итог ревью. После выпуска сохранить recovery bundle, проверить точный merged source tip и убрать свою ветку/worktree.

Последний read-only QA preflight24.10.2026 00:21:44UTC: общий cap500ед./90₽, supplier exposure2.550021₽, remaining483.5ед., holds0. В текущем operator-assisted этапе новых quote/paid/Generate нет. Source description принят один раз через UI, reload подтвердил сохранность; временный сбой чтения вопросов восстановлен одним штатным retry, HTTP/причина неизвестны. Полный typed/engineering/norm/price/export/ERP apply pilot ещё не принят. Backfill на production не запускался; снимок бюджета не заменяет свежие gates перед новым расходом.
