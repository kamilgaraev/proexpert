# МОСТ AI V1: продуктовая policy и vendor evidence

Результат [MOSTAI-12 / LEAD-03](https://prohelper.youtrack.cloud/issue/MOSTAI-12), продолжение пакета 01, 05.10.2026. Исходная последовательность LEAD-01 `5bd17112d6316b81ada1cacb7f77ec77e8bcc7bc` → LEAD-02 `a9e2db649e866c7d9fec0412375003b0ea762d12` → LEAD-03 `4f1224094e0d8f7c4e4b4945c11d25607dc20652`; исправленный документальный срез `45b1ec0e48daafab7c34871806994d69e675f6bd`. [Inventory](caller-inventory.md) и [source snapshot](foundation-current-state.md) сохраняют product baseline `215bd3faa86678c23a2eb477ee2309e431757919`; delta/deployed metadata SHA — `1b1650c65a5593ac46afbf9355987ce50c129f07`.

## Источник согласования

Policy version: `most-ai-v1-purpose-policy/0.2-product-approved-20261005`. В [исходном чате](codex://threads/01a1054e-e1f6-7c31-bef1-c4aff7ab6cf0) пользовательское сообщение `01a10a61-9682-7230-8002-996f27db78f6` согласует предложенную перед ним таблицу: «Согласен, пусть так и будет, однако юриста пока нет, однако условия провайдеров в открытом доступе, я пользуюсь таймвебом». LEAD непосредственно прочитал message и предшествующее предложение.

Product policy owner — согласовавший пользователь/владелец продукта. Юридическое имя и полномочия представителя отдельной организации не устанавливаются. Юрист не назначен; lawyer evidence/legal approval не получены. Timeweb — user-reported provider, а не доказательство actual endpoint/model/profile/account или региона.

**Это product approval; runtime enforcement этим документом не реализован.** Условия ниже обязательны для будущего включения. Согласование не даёт legal basis/customer consent, не разрешает raw fallback или реальную внешнюю передачу. Версия не frozen G0 interface.

## Согласованные категории V1

| Категория | Product-approved решение | Обязательные границы / evidence |
| --- | --- | --- |
| Объёмы работ, материалы, цены, бюджеты, сроки, статусы, условия договора | Необходимый минимум для конкретного purpose | Privacy-проверка, полномочия организации, применимые vendor conditions, tenant/field ACL и минимизация. Не blanket allowance договора или всей коммерческой информации |
| ФИО, контакты, реальные IDs людей/организаций | Удаление либо random tenant-scoped tokens | Mapping/resolve остаются в МОСТ; оставшийся контекст проверяется на reidentification |
| Паспорта, СНИЛС, платёжные реквизиты, индивидуальные зарплаты | Локально для V1 | Ни token, ни общий budget purpose не разрешают individual payroll/реквизиты или восстановление записи из группы |
| Здоровье, медицинские сведения и персональный safety status | Локально для V1 | Никакого implicit allowance из safety purpose; модель не принимает medical/admission decision |
| Точные адреса/координаты и уникальный определяющий контекст | Suppress exact / local-review | Более грубое описание только как необходимый разрешённый fact; неизвестная категория/reidentification блокируют отправку. Exact-location allowance отдельно не получено |
| Отдельно запрещённые владельцем сведения, особо конфиденциальные договоры/чертежи/материалы | Локально | Общая policy не отменяет запрет владельца конкретных данных |
| Документы, PDF/images/pixels/crops, подписи, лица, QR/barcodes, EXIF/hidden text | Verified sanitized representation; originals локальны | Local OCR/redaction, immutable final bytes verification; visible/hidden/auxiliary text и metadata проверяются; unsupported = blocked |
| Свободный текст / смешанные документы | Classification всех категорий; unknown/неуверенная очистка = blocked | Никакого raw fallback или allowance лишь по PII regex |
| Filename/page/unit metadata, signed/download refs, ACL/FK/IDs | Suppress private metadata; opaque минимум | Citation resolve локален под fresh ACL; private URL/filename/keys не передаются |
| Logs/APM/errors/queue/cache/replay | Запрет payload по default | Allowlisted codes/counters/scoped refs; recovery из safe view; без prompt/body/tool result/exception/private path |

1. Default external decision — deny. Неизвестные purpose/category, права/consent/source version или неготовый Required stage дают blocked/manual-review.
2. Необходимый минимум согласованных facts обрабатывается автоматически внутри approved policy **после** privacy, organisation и vendor gates. Исключения/изменения policy требуют human review. Public/synthetic test data не разрешают частные данные; реальные AI requests этим пакетом не выполняются.
3. Tokenization — псевдонимизация. SafeRepresentation может оставаться чувствительной; удаление имени не устраняет определение человека по контексту и не создаёт анонимизацию/legal permission.
4. User text, filename, detokenized UI output/history/context снова проходят raw ingress. Модель, tool, клиент и документ не могут назначить safe marker. Каждый result/summary/memory/retry/loop проверяется до следующего attempt.
5. Fresh local ACL/consent/purpose/policy/source-generation check сериализуется с revoke и dispatch. Revoke до committed dispatch блокирует outbound, после — следующие attempts; уже отправленные bytes не отзываются. TTL/cached approval не заменяют guard.
6. Модель не принимает юридические, медицинские и кадровые решения. Authoritative totals считает сервер с units/currency/period/coverage; unknown/partial facts не превращаются в verified.
7. Human approval не обходит Processor/Gateway/redaction/egress. Требуемые organisational decisions, legal basis, retention сроки и reidentification thresholds не придумываются агентом.

Настоящие payroll/medical records, пользовательские файлы, payloads и log records не собирались. Product approval не доказывает готовность field projections или detector quality.

## Purpose scope

| Purpose / transport | Требование будущего включения | Текущее ограничение |
| --- | --- | --- |
| assistant_chat / knowledge / history / memory / tools, T1/T2 | Approved категории, typed safe projection/history, fresh scope каждого loop; local rehydration не возвращается provider | Required implementation/vendor/organisation evidence не доказаны |
| rag_document_embedding / rag_query_embedding, T3 | Processor до chunk/query embedding, safe generations/profile, metadata/FK/ACL local | Raw source paths присутствуют; safe reindex/cutover не выполнены |
| project_pulse | Серверные facts, approved categories, billing lifecycle, verified/partial/no-data | AS03/05 follow-up остаётся; model totals не allowance |
| spreadsheet_mapping / voice / classification / dialogue | Минимизация headers/sample/transcript/context/code/name/unit; actions только через workflow | Product approval не включает raw sample/transcript или обход stages |
| estimate_* roles, T4 | Per-role минимум business facts/canonical evidence; каждый attempt/review/correction проходит safe route | Cost/scope limits не privacy/vendor gates; все 7 roles сохранены |
| raster / assistant_document_ocr / pdf_fallback / estimate_unit_ocr, T5–T7 | Local OCR/redaction, final bytes, hidden/auxiliary text/metadata; unsupported blocked | PDF-import fallback при explicit DI usage guard блокируется pre-HTTP; отдельный context-bearing unit OCR найден. Usage guard не privacy gate; whole-source PDF transmission не доказан |
| ai_analyzer_* | Установить purpose/data owner и caller/root до allowance | Unknown caller не исключает класс из inventory |
| Incomplete BIM / advanced context / comparison / legal tools / остальные gaps | Recommended backlog; incomplete BIM выключается end-to-end, context server-validated, specialist decisions отдельно | BIM disable не доказан; 15 proposed tools и 18 gaps не implemented |

Trusted Processor, immutable safe bytes, minimal encrypted/recoverable Vault, fresh serialized guard, safe refs/history/RAG/embeddings, local OCR/redaction, Gateway-only keys и hard egress — V1 Required. Separate Authority/global nonce/signatures/advanced KMS/full DR — V2. Current consent не означает принятие common-key/trusted-host residual risks.

## Vendor/residency matrix

`confirmed source` — проверенный код; `confirmed metadata` — конкретные безопасные чтения host; `public-only` — публичный документ с непроверенной account applicability; `unknown` — evidence отсутствует.

| Surface | Подтверждено | Остаток / роль / блокируемый scope |
| --- | --- | --- |
| Timeweb T1/T3/T4/T5/T6/T7 | User-reported provider, code defaults api.timeweb.ai; public Gateway docs/terms links найдены | Actual model/host/account/selected service, upstream processors/region, prompt/image/PDF retention/training/deletion/abuse logs/store:false semantics unknown. Account/vendor owner + legal/privacy; private purpose blocked |
| OpenAI T2 / embedding alternative | Selectable source route сохранён; OpenAI SDK может использовать Timeweb base URI | Actual route/key presence неизвестны; слово Timeweb не исключает direct OpenAI. Account/vendor + legal evidence для включённого route |
| Package/runtime metadata | Deployed SHA 1b1650c и selected source/SDK hashes сверены; installed drift записан в LEAD-02 | .env permission-denied; config cache absent/parent hidden; effective host/model/key presence unknown. Runtime wrappers/bindings attribution частично недоступны |
| DB/Redis/vector/history/queues | Source channels известны; БД/contents не читались | Actual РФ topology/schema/isolation/encryption/retention/backup/APM content policy unknown. Infra/privacy; private persistence/cutover/pilot |
| Raw S3/safe artifacts | Source derivatives/file paths известны; bucket/данные не читались | Region/subprocessor, access separation, redirects/URL lifetime/backup unknown. Infra/privacy/legal; document/private pilot |
| Minimal Vault/key/recovery | Encrypted/recoverable mapping/protected key — Required; implementation не создана пакетом | РФ contour, access/key permissions, минимальный restore proof. Privacy/infra; tokens/resolve/pilot |
| Advanced KMS/DEK/full DR | V2, не prerequisite минимального V1 если не используется | Evidence только выбранного advanced scope; не возвращается в Required |
| Common key/trusted host/kernel | V1 target residual risk | Именованный risk owner и отдельное scope/version/date решение для G4; нынешний consent его не заменяет |
| External legal/knowledge sources | Proposed Recommended feature | Licence/version/applicability/export/specialist evidence только выбранной legal feature |

Кроме product policy owner роли не назначаются агентом. Read-only metadata не включает ENV/config values, process ENV, payloads/records/logs, DB/provider HTTP или account changes. IP/домен и российское юрлицо не подтверждают регион всех processing/storage/backup copies.

## Публичные primary sources Timeweb — чтение 05.10.2026

| Источник / версия / место | Public-only вывод | Ограничение |
| --- | --- | --- |
| [AI Gateway docs](https://timeweb.cloud/docs/ai-agents/api-usage/ai-gateway), differences/connection/request; Context7 /websites/timeweb_cloud | Direct model API отдельно от AI Agents API; пример api.timeweb.ai/v1 через OpenAI SDK | Не actual route/account МОСТ |
| [AI Gateway service](https://timeweb.cloud/services/ai-gateway), proxy/checkout/FAQ history | Proxy над API моделей; FAQ заявляет отсутствие хранения истории Gateway, контекст передаёт клиент | Не zero retention telemetry/abuse/upstream, не training или region guarantee |
| [AI agreement][tw-agreement], внутри PDF ред.06.02.2026/effective13.02.2026; стр.3 п.1.6, стр.5 раздел3 п.1.1(2) | Linked с Gateway checkout документ об ИИ-агентах требует правила выбранной модели и надлежащее согласие при ПДн | Service definition — ИИ-агенты; Gateway/account applicability, exact retention/training/routes unknown; старое имя файла не редакция |
| [Platform offer][tw-offer], ред.06.02.2026/effective13.02.2026; §9.2–9.4, стр.18 | Лицензиат отвечает за свои данные/операторскую обработку; общая оферта не является поручением обработки ПДн | Не автоматический DPA для AI prompts; нужны применимые дополнительные условия |
| [General personal-data policy][tw-personal], ред.01.12.2025/effective08.12.2025; introduction/categories, стр.1 | Описывает пользователя сайта/платформы, purpose-specific сроки | Не устанавливает customer prompt/model retention |
| [Cross-border consent][tw-cross], стр.1 categories/recipient | Контакты лицензиата/metric data; получатель в Казахстане | Не customer consent МОСТ и не prompt routing proof |
| [Official July digest](https://timeweb.cloud/blog/digest-july-2026), pub07.08.2026/update04.10.2026; AI Agents HA/models | Прочитанный блок сообщает HA AI Agents в Германии/США | Это не Gateway route. Проверенная страница не подтвердила локальный subset; actual model geography остаётся unknown |

Relevant PDF pages прочитаны и визуально сверены; копии вне checkout. SHA-256: agreement `1d57cd47967906c0c6ee8203d423c9218ad4d04d26194a560d7053b4a76259ab`; consent `2c52e18a97b0de3b093a533b5112a44f3304309ba2a81743d0b6dcaf8671678e`; offer `f23cf99e739f3b5328ce0933b3c5ce8786b63fcb60caf71190efa7b2a1ad46f5`; personal policy `34e113c3effea51237e503612c04c9464d5d78a0ea047bce6478d1f205bd24b7`. Договоры целиком не копируются; это не legal certification.

[tw-agreement]: https://s3.twcstorage.ru/tw-cloud-static/legal-info/%D0%A1%D0%BE%D0%B3%D0%BB%D0%B0%D1%88%D0%B5%D0%BD%D0%B8%D0%B5_%D0%98%D0%98_%D0%B0%D0%B3%D0%B5%D0%BD%D1%82%D1%8B_%D0%B4%D0%BB%D1%8F_%D1%81%D0%B0%D0%B9%D1%82%D0%B0_timeweb_cloud_%D1%80%D0%B5%D0%B4%D0%B0%D0%BA%D1%86%D0%B8%D1%8F_01_12_2025.pdf
[tw-offer]: https://s3.twcstorage.ru/tw-cloud-static/legal-info/timeweb-cloud-public-offer.pdf
[tw-personal]: https://s3.twcstorage.ru/tw-cloud-static/legal-info/personal-data-policy.pdf
[tw-cross]: https://s3.twcstorage.ru/tw-cloud-static/legal-info/%D0%A2%D0%92%D0%9A_%D0%A1%D0%BE%D0%B3%D0%BB%D0%B0%D1%81%D0%B8%D0%B5_%D0%BD%D0%B0_%D1%82%D1%80%D0%B0%D0%BD%D1%81%D0%B3%D1%80%D0%B0%D0%BD%D0%B8%D1%87%D0%BD%D1%83%D1%8E_%D0%BF%D0%B5%D1%80%D0%B5%D0%B4%D0%B0%D1%87%D1%83_%D0%98%D0%98_%D0%B0%D0%B3%D0%B5%D0%BD%D1%82%D1%8B_01_12_2025.pdf

[OpenAI Data controls](https://developers.openai.com/api/docs/guides/your-data) — отдельный public-general источник возможного direct OpenAI route. Store/application state, abuse logs, ZDR/MAM и configured residency различаются; не переносятся на Timeweb-compatible transport. Фактический OpenAI route/account неизвестен.

## Acceptance: явное уточнение Foundation

Новая команда пользователя `01a10a6f-8dcb-7e90-9500-e299686c2c83` разрешает завершение/merge/штатный docs deploy и начало выбранного следующего пакета по готовности. Она не закрывает gates. Для синтетических контрактов не требуется утверждать законность конкретной частной передачи, поскольку такой передачи/activation нет. Уточнение Foundation критериев ниже требует independent QA; исходный criterion сохраняется в истории.

| Criterion / прежнее состояние | Предлагаемое Foundation acceptance | Сохраняемый release blocker |
| --- | --- | --- |
| Explicit зарплата/здоровье/адреса/коммерческая тайна policy была proposed | Exact human product approval/version и guards записаны | Организационные полномочия/customer consent/enforcement не получены |
| Unknown residency/DPA/terms blockers | Public evidence, applicability boundaries и required evidence/roles записаны | Private use/pilot blocked до contract/model/region/retention evidence |
| SafeRepresentation не anonymization/legal permission | Сохранено явно | Неизвестная legal basis не получает allowance |
| Evidence owner + lawyer — первоначальный criterion не выполнен: юриста нет | Product owner approval, public vendor-source review и отдельное указание отсутствующего lawyer evidence достаточны для technical Foundation/synthetic contracts | Lawyer/account-specific review **не объявляется полученным**; остаётся обязательным для требующего его private/release scope в [MOSTAI-84 / G3](https://prohelper.youtrack.cloud/issue/MOSTAI-84) и [MOSTAI-16 / G4](https://prohelper.youtrack.cloud/issue/MOSTAI-16) |

Это явное разделение технического входа и legal/private release acceptance, а не выполнение старого criterion задним числом. Нельзя закрыть G3/G4 или включить реальную передачу лишь после merge документов. No-lawyer/product approval не является customer consent или принятием residual risks.

Для account/vendor owner подготовлены вопросы: linked AI-Agents agreement применим к Gateway/account? Какие DPA/model rules/downstream processors/regions, retention/training/abuse/deletion/store:false и fallback гарантии действуют для выбранных text/embedding/image/PDF моделей? В поддержку вопросы не отправлялись. Actual config/scheduler/binding evidence gap указан в inventory; нужен allowlisted metadata export, не raw ENV/БД доступ.

До supporting QA уточнения Ready for Merge=No, Requires Human Review=Yes. После exact-HEAD QA и required checks разрешены merge/штатный deploy пакета01; затем LEAD-04 первым при принятых Foundation inputs. QA-01/PRIV-01 только после G0. Merge/deploy пакета02, private pilot и AI production activation этой командой не разрешены.
