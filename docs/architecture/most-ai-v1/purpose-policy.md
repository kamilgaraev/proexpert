# МОСТ AI V1: purpose/category policy и evidence gaps

Результат [MOSTAI-12 / LEAD-03](https://prohelper.youtrack.cloud/issue/MOSTAI-12), пакет 01. Проверка публичных источников: 05.10.2026 МСК. Input LEAD-02: commit `a9e2db649e866c7d9fec0412375003b0ea762d12`, [caller inventory](caller-inventory.md); LEAD-01: `5bd17112d6316b81ada1cacb7f77ec77e8bcc7bc`, [source snapshot](foundation-current-state.md). Product source — `215bd3faa86678c23a2eb477ee2309e431757919`.

Policy version `most-ai-v1-purpose-policy/0.1-proposed`. Это проект для человеческого согласования. **Policy enforcement и runtime block не реализованы этим документом.** Текущие source paths могут передавать raw; actual runtime конфигурация и передачи не проверялись. G0/interface freeze, private pilot и production readiness не заявляются.

## Правила решения

1. Default external decision — **deny**. Неизвестный purpose/category, неподтверждённые права/consent/source version, ambiguous detector result, unsupported format и неготовый required privacy stage дают blocked/manual-review, без raw fallback.
2. Allow допускается только для явно согласованного purpose, категорий и минимальной typed projection после Required Processor/Gateway/egress/guard pipeline и vendor evidence. Public/synthetic canary не является разрешением использовать реальные частные данные. В этом пакете live provider calls не разрешены даже для canary.
3. Tokenization — псевдонимизация. SafeRepresentation может оставаться чувствительной; она не объявляется анонимизацией или правовым разрешением. Local tenant/project/field ACL разрешает чтение пользователю, но не устанавливает внешнюю передачу provider.
4. Raw identifiers, local ACL/FK, mapping, signed URLs, private source/download refs, credentials и private exports остаются локальными. Model-visible refs — scoped opaque refs; ни реальный ID, ни его hash не заменяют random tenant binding.
5. User text, filename, цитата detokenized UI ответа и переданный history/context проходят raw ingress заново. Модель, клиент, tool и текст документа не могут присвоить доверенный safe marker. Каждый tool result/summary/memory/retry/loop проверяется перед следующим transport attempt.
6. Fresh local ACL/consent/purpose/policy/source-generation check сериализуется с revoke и началом dispatch. Revoke до committed dispatch блокирует outbound; после него блокируются следующие attempts/retries/loops. Уже отправленные bytes невозможно отозвать. TTL и cached approval не заменяют guard.
7. Human review разрешает только последующее применение согласованной policy и Required safe pipeline. Review не даёт разрешения обойти redaction/egress или передать raw. Высокорисковые юридические/медицинские/кадровые решения не принимает модель.

## Category decision matrix — proposed defaults

| Категория | External decision | Допустимая будущая projection / local-review | Evidence и принимающий owner |
| --- | --- | --- | --- |
| Проверенные public/synthetic данные | Conditional allow после pipeline/vendor approvals | Только явно выбранный public source или synthetic corpus, с provenance и тестом final bytes | Policy owner + QA; сейчас не назначен/не утверждено |
| ФИО, email, телефон, табельные номера, user/entity IDs | Suppress raw; local tokenize/resolve | Random tenant-scoped token и opaque ref только когда purpose требует связи сущностей; убрать ненужные поля | Policy/Privacy owner; pipeline и field allowlist ещё не реализованы |
| Паспорт, СНИЛС, дата рождения, bank/payment реквизиты | Suppress / local-review | Индивидуальные значения остаются private; export отдельно под локальными правами, без provider handoff | Policy owner + legal; explicit allowance отсутствует |
| Индивидуальные зарплаты, ставки, начисления, трудовые сведения | Suppress / local-review | Не отправлять person-linked amounts даже после замены имени. Aggregate возможен лишь после отдельной human category/purpose approval и проверки reidentification/minimization | Policy owner + HR/data owner + legal; evidence unknown |
| Здоровье, медосмотры, диагнозы, допуски | Suppress / local-review | Медицинское содержимое и person-linked status остаются локальными; no automated medical/admission decision. Coarse non-person aggregate требует отдельной согласованной policy | Policy owner + safety/medical data owner + legal; evidence unknown |
| Точные адреса, координаты, уникальный объект/проект | Suppress exact; local-review | Более грубое location/project описание возможно только по утверждённому purpose и category policy; token не делает точное место безопасным | Project/data owner + policy/legal; evidence unknown |
| Коммерческая тайна: договоры, цены, бюджеты, чертежи, закупочные условия | Suppress / local-review по default | Минимальный разрешённый business fact set только после решения владельца данных и vendor evidence. Удаление ФИО/IDs недостаточно | Business/data owner + policy/legal; классификация реального содержимого не проверялась |
| Финансовые quantities/totals, даты и факты | Conditional allow только approved projection | Authoritative числа рассчитывает сервер, с currency/units/period/coverage; unsupported factual conclusion блокируется. Не расширяет finance workflow | Business/policy owner; actual field allowlist unknown |
| Свободный текст и смешанные документы | Local classification; unknown = blocked/manual-review | Проверка всех категорий и контекста, не только PII regex; неоднозначное содержимое наружу не выходит | Privacy + policy owner; detector corpus/quality evidence ещё не получено |
| PDF/image/pixels/crops, подписи, лица, QR/barcodes, EXIF/hidden text | Suppress raw; local OCR/redaction; unsupported = blocked | Immutable canonical safe bytes с final leak check; проверить видимый и скрытый текст, pixels и auxiliary metadata. T5/T6/T7 не исключаются из контроля | DOC/PRIV/GATE + QA; runtime pipeline отсутствует в проверенных chains |
| Filename, page/unit metadata, source versions, signed/download URLs | Suppress private metadata; opaque минимум | Provider получает только необходимые safe metadata, без raw filename/URL/private IDs. Citation resolve происходит локально под fresh ACL | Policy/Privacy owner; schemas должны быть frozen в G0 |
| Logs/APM/errors/queue/cache/replay | Запрет payload по default | Allowlisted codes, counters и scoped operational refs; без prompt/body/tool result/technical exception/private storage path. Recovery только из safe view | GATE/ASSIST + QA; текущие partial protections не полная гарантия |

Названия категорий относятся к threat/policy surface из аудитов, а не к прочитанным пользовательским записям. Реальные персональные значения, файлы, payroll/medical records и production dumps не собирались. Thresholds aggregation/reidentification, retention сроки и legal basis не придумываются агентом.

## Purpose policy — scope относительно inventory

| Inventory purpose | Proposed правило включения | Состояние сейчас / downstream block |
| --- | --- | --- |
| assistant_chat / knowledge_answer / history / memory / tools | Typed safe projections; history только из safe view; local rehydration не возвращается provider; fresh scope каждого loop | Required pipeline/vendor/category approvals ещё не доказаны; private enabled V1 scope blocked |
| rag_document_embedding / rag_query_embedding | Processor до chunking и до query embedding; safe generations/profile; raw metadata/FK/ACL local; privacy отдельно от retrieval ACL | Raw chunk/query source paths присутствуют; safe migration/cutover ещё не сделаны |
| project_pulse | Только подтверждённые серверные facts, approved categories, общий billing lifecycle, verified/partial/no-data; модель не источник totals | AS03/05 source follow-up остаётся; unprovable factual scenario должен быть выключен до будущей приёмки |
| spreadsheet_mapping / voice_command_parsing / estimate_classification / estimate_dialogue | Минимизировать headers/sample/transcript/context/code/name/unit; classify всё содержимое; command не исполнять без action workflow | Sample/transcript/business context потенциально private; default deny до approved pipeline |
| estimate_* roles T4 | Per-role field/purpose allowlist и canonical server evidence; каждый attempt/review/correction через Processor/Gateway/fresh guard | Scope/cost limits не data policy; все 7 role callers остаются inventory-covered |
| estimate_raster_analysis / assistant_document_ocr / estimate_pdf_ocr_fallback | Local OCR/redaction; final immutable bytes; hidden/auxiliary text и metadata отдельно; unsupported scope blocked | T7 conditional raw-PDF chain подтверждён source. T5/T6/T7 safe gate/disable не реализованы пакетом |
| ai_analyzer_* | Назначить purpose/data owner и установить caller/root до allowance; unknown не получает implicit grant | Caller chain unknown; external V1 inclusion blocked, класс не исключён из inventory |
| BIM incomplete / advanced page context / document comparison / legal tools / остальные capability gaps | Proposed Recommended остаются backlog. Incomplete BIM исключается end-to-end; context server-validated; legal conclusion требует специалиста | Disable evidence для BIM не получено. 15 proposed tools и 18 gaps не объявлены implemented; их будущие gates сохраняются |

Этот policy документ не меняет legacy product runtime. Для будущего включения private pilot/enabled V1 scope требуется отдельно реализованный block/allow gate и независимое negative evidence, включая jobs/CLI/direct SDK/redirect paths. Обычная локальная работа продукта должна продолжаться при AI block.

## Vendor и residency evidence matrix

`confirmed source` — доказательство конфигурационного/caller кода. `public general` — публичное описание услуги, не договор или настройка МОСТ. `unknown` — конкретное evidence отсутствует. Принимающие роли ниже требуют человеческого назначения; agent LEAD их не заменяет.

| Surface | Что подтверждено | Что unknown / необходимый evidence | Требуемый human owner | Blocked downstream scope |
| --- | --- | --- | --- | --- |
| Timeweb T1/T3/T4/T5/T6/T7 | Code transport и defaults закреплены в LEAD-02; T1 задаёт store:false | Actual endpoint/tenant/model routing, legal entity/product mapping, DPA/terms, subprocessors, processing/storage region, prompt/image/PDF retention, удаление/abuse logs и смысл store:false у совместимого API | Vendor/account owner + legal/privacy | Соответствующий private AI purpose и pilot до evidence; Required safe transport также обязателен |
| OpenAI T2 / configured embedding alternative | Source selectable SDK route; публичные Data controls доступны | Actual customer endpoint/account/project, approval ZDR/MAM, применимые modality/endpoint limitations, DPA/subprocessors/region, actual retention configuration | Vendor/account owner + legal/privacy | Соответствующий private AI purpose; публичные defaults не дают allowance |
| PostgreSQL/Redis/vector/text/history/queues | Source использует локальные tenant/source metadata и content storage; actual topology не проверена | Размещение/РФ, applied schema/access isolation, encryption/retention, actual cache/failed_jobs/APM content policy, backup copies | Infra/storage owner + privacy | Private persistence и safe cutover/pilot до Required evidence |
| Raw S3/object storage и safe artifacts | Source file/derivative channels существуют | Фактический bucket/region/subprocessor, policies, redirects/signed URL lifetime, raw/safe access separation, encrypted backup location/retention | Infra/storage owner + privacy/legal | Document paths и private pilot; ссылка/название bucket не доказательство региона |
| Minimal Vault/recovery key/backup | V1 требует encrypted/recoverable mappings и protected key; реализация этим пакетом не создана | Подтверждённый РФ contour для mapping/key/recovery copies, actor isolation, key доступы, минимальный restore proof; отсутствие raw fallback | Privacy/infra owner | V1 tokens/resolve/recovery и private pilot до Required evidence |
| Advanced KMS / tenant DEK hierarchy / full DR | V2 Hardening, не prerequisite минимального V1 если не используется | Если позже включается: фактический регион/service/account/backup/DR и advanced lifecycle evidence | Privacy/infra + human residual-risk owner | Только выбранный advanced scope; **unknown advanced KMS/full DR не возвращает их в V1 Required** |
| Common Vault key / trusted host/kernel | V1 target допускает упрощённый deployment с этим residual risk | Именованный человек, дата/versions/scope и явное решение о common-key blast radius и общем fault/trust domain | Human risk owner | G4 human release decision; отсутствие advanced V2 не отменяет принятие остаточного риска |
| External legal/knowledge sources | Новые legal tools/источники лишь proposed Recommended | Licence, редакция/дата/applicability, право использования/цитирования/export, contract и specialist approval | Legal/source licence owner | Только соответствующая Recommended legal feature; не универсальный V1 blocker |

Никаких account dashboards, secret/config-cache values, credentials, реальных payloads и production storage settings не читали. IP/домен/маркетинговая страница не доказывают местонахождение всех processing/storage/backup copies. Unknown записан с нужным evidence и ролью; это не назначение владельца и не legal approval.

## Публичные первичные источники и пределы

- [OpenAI Data controls](https://developers.openai.com/api/docs/guides/your-data), Context7 `/websites/developers_openai_api` и прямое чтение официальной страницы: application state/store и abuse-monitoring retention — разные controls. ZDR/MAM требуют approval и имеют endpoint/modality ограничения; residency требует конкретной customer configuration. Поэтому один `store:false` не доказывает нулевой retention или регион. Фактические настройки/договор МОСТ остаются unknown. Эти условия не переносятся на Timeweb-compatible API.
- Context7 `/websites/timeweb_cloud`: focused query для `api.timeweb.ai` retention/DPA/region не вернул соответствующей документации. Bounded official-domain search и [Timeweb Cloud AI agents](https://timeweb.cloud/services/ai-agents) не установили конкретный договор/retention/processing region используемого endpoint. Это evidence gap, а не доказательство отсутствия условий или нарушения. General AI-agent/cloud услуга не принята за подтверждённую service/account mapping этого source transport.
- Source endpoints/payloads/retries и registry проверены отдельно в LEAD-02. Public evidence не подтверждает actual source flags после ENV overrides, runtime workloads, network denial или safe processing.

## Acceptance и человеческие решения

| Native MOSTAI-12 criterion | Результат Foundation | Статус acceptance |
| --- | --- | --- |
| Explicit зарплата/здоровье/адреса/коммерческая тайна policy | Proposed suppress/local-review defaults и ограниченные conditional allow rules записаны | Документ подготовлен; human policy approval **не получено** |
| Unknown residency/DPA/terms отмечены blockers private pilot | Matrix со scope, требуемой ролью и evidence gap подготовлена | Документальная часть выполнена; private pilot blocked |
| Safe representation не анонимизация/правовое разрешение | Явно записано в правилах и matrix | Документальная часть выполнена |
| Evidence получено владельцем и юристом | Vendor/account/legal/infra evidence и именованные decision owners не предоставлены | **Не выполнено; blocker вне разрешённого source-only пакета** |

Требуется назначение human policy/vendor/legal/infra/risk owners, согласование category/purpose policy, получение применимых contract/region/retention evidence без raw данных и независимая будущая runtime приёмка. Агент не делает юридическое заключение и не принимает residual risk. Достаточность документального среза для merge трёх docs может оценить QA и человек; это не превращает открытые acceptance в Done или готовность private pilot.

Состояние для review: Requires Human Review=Yes, Ready for Merge=No. LEAD-04/G0, QA-01, следующий пакет, migration/cutover/pilot/production не запущены. После QA остаётся отдельное решение человека о merge; никакого автоматического перехода к четвёртой задаче.
