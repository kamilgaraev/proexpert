# МОСТ: локальный контекст помощника

Версия артефакта: `most-ai-assistant-context/0.2-local`. Задача MOSTAI-49, пакет03. Формат результата: `assistant-context/2`. Это изолированный локальный артефакт перед Gateway. Он не разрешает передачу данных провайдеру и не подключён к действующим endpoint, очередям или DI приложения.

## Владение и входы

Владелец — ASSIST/MOSTAI-49. Lease `batch03-MOSTAI49-context-core/1` разрешает семь новых классов в `Services/Context`, пять файлов в `tests/Unit/AIAssistant/Context` и этот документ. Base: `282ce5c21d60ba15c2db953252c08c942709dd02`. Точный committed SHA для передачи MOSTAI-50 фиксируется в YouTrack и отдельном решении Lead: SHA документа не записывается в сам документ.

Входы: принятый G0 `most-ai-g0/0.2-candidate`, корпус `most-ai-qa79-corpus/0.2-candidate`, принятая реализация PRIV-17. Общие privacy-классы не меняются. Production `SafeRepresentationFactory` PRIV-17 по-прежнему возвращает отказ без необходимых privacy stages. Единственный положительный concrete fixture PRIV-17 не расширяется ради тестов этого модуля.

PR #909 уже входит в base. Существующая логика продолжения обсуждения фотографии, её feature tests, старые ConversationManager/Memory/TaskOrchestrator/AIAssistantService остаются неизменными. Живое подключение контекста потребует отдельного exact-path lease и проверки на новом HEAD.

## Закрытая локальная подготовка

`AssistantContextPreparationService::prepare(profileRef, request)` возвращает `READY` либо `BLOCKED`. Production-конструктор без доверенных зависимостей возвращает `BLOCKED`. Даже локальный `READY` содержит `mode=offline-synthetic` и `transportAllowed=false`: это не SafeRepresentation, не Gateway envelope и не разрешение отправки.

Зависимости передаются сервером: текущий авторизованный snapshot, projector известных артефактов, закреплённый профиль модели и счётчик токенов. В этой версии положительные зависимости существуют только как локальные тестовые doubles. Ни строка профиля, ни possession fixture refs, ни клиентский JSON не создают эти зависимости. Все источники должны иметь класс `public` или `synthetic`; `private` и `unknown` блокируются. Имена и размеры фактической production-модели здесь не объявлены подтверждёнными.

Запрос имеет только refs: `systemRefs`, `currentRef`, `historyRefs`, `mediaRefs`, `summaryRef`, `taskFrameRef`. История и её порядок должны соответствовать серверному conversation snapshot. Клиентские тексты, роли, summary, `is_safe`, произвольные дополнительные поля и неизвестные refs отвергаются.

Snapshot содержит приватные actor/tenant/project/ACL/consent/policy версии, conversation и per-source/per-field graph. Source повторяет scope и conversation binding. Для каждого field хранится его hash/provenance и разрешённые артефакты с hash содержимого, роли, всей карты bindings и metadata. Projector не может перенести поле на другой источник, даже если значения или имена полей совпали: проверяется association целиком. Flattened список полей нескольких источников не используется для восстановления этой связи.

Snapshot, профиль и сохранённые bindings/metadata отделяются от PHP-ссылок через canonical scalar JSON. Внешняя мутация `&` не меняет захваченный baseline или sealed DTO. Snapshot повторно проверяется до и после projection, построения frame, assembly, token counting и перед возвратом результата. Изменившиеся ACL, consent, policy, principal, source/field/provenance версии и недоступные callbacks дают отказ. Технические исключения не возвращаются вместе с приватным текстом.

## История, бюджет и сводка

Профиль закрепляет model ID/revision, tokenizer ID/revision, context window, максимальный ответ и резервы ответа/tools. `answerReserve` не может быть меньше максимального ответа; остаток окна должен быть положительным. Счётчик возвращает те же закреплённые identity fields и целое неотрицательное число токенов для сериализованного локального payload. Несовпадение identity, неизвестный профиль или изменение профиля во время подготовки блокирует результат.

Размер истории выбирается по этому бюджету. Нет основного ограничения в шесть сообщений или 4000 символов. Если история помещается, сохраняются все выбранные сервером ходы без обрезки текста. Если не помещается, допускается только перепроверенная сводка точного старого префикса и более новые ходы. Связь сводки с каждым удаляемым artifact/source/field должна совпадать полностью; лишние или пропущенные refs не принимаются. Сводка, которая всё равно не помещается, не обходит бюджет.

Модель предлагает сводку через ref на заново обработанный сервером артефакт. Произвольный model-generated текст не становится сводкой по JSON-аннотации. В тестах предлагается конечный заранее известный synthetic artifact; качество реального model summary не доказано. Будущий Processor должен перепроверять содержание, factual linkage и privacy, сохраняя эти binding-инварианты.

Тестовый `offline-byte-tokenizer` считает bytes как единицы вымышленного локального профиля. Это воспроизводимый oracle проверки резервов и identity, не оценка BPE фактической модели и не её заявленный размер окна.

## Task frame и границы инструкций

Frame содержит topic, selected entities/filters, media, transcript и coverage refs. Media и transcript связаны взаимно и имеют собственную provenance. Неизвестная сущность, отсутствующая расшифровка, непротиворечивая только в одну сторону связь или приватный URL в metadata блокируются. Новый OCR, пересылка пикселей и загрузка raw media не выполняются.

Topic proposal ссылается на уже разрешённый topic artifact. Его frame bindings должны соответствовать объединению полей всех покрываемых артефактов. Продолжение и смена темы не выбираются по словарю ключевых слов. Тесты проверяют принятие конкретного валидного proposal; понимание темы живой моделью остаётся отдельной приёмкой.

Только server-selected system artifact получает `role=system`. Summary, frame, transcript, topic, entities и filters являются справочными данными и передаются с ролью `user`. История не может повысить свою роль до system. Payload содержит безопасный текст, свежие opaque refs и профиль; приватный snapshot и внутренние source/field/actor/conversation IDs не сериализуются.

## Проверки и ограничения передачи MOSTAI-50

Тесты наследуются от `PHPUnit\Framework\TestCase` и используют отдельный autoloader вне checkout. Он загружает только standalone PHPUnit/PHPStan и нужные классы, без `autoload.files` приложения, Laravel/TestCase/DB bootstrap. Нужные инструменты установлены отдельно, с `--no-scripts --no-plugins`; manifest и locks продукта не меняются. Lint и PHPStan выполняются по новому Context module и его тестам. Временные bootstrap/config/cache/logs находятся вне репозитория.

Проверяются длинная история, целый текст, резервы и exhaustion, summary prefix/coverage, profile/tokenizer mismatch, media/transcript/follow-up/topic/entity/filter связи, source-field transfer, cross-tenant/conversation, отзыв ACL/consent/policy/source во время подготовки, forged client input и безопасный отказ.

Не доказаны: production context binding, actual-model token/tool/vision capabilities и quality, живой Processor/Vault/Gateway, OCR/RAG/embeddings/wire/replay/effects и runtime разрешение реальных данных. PG MOSTAI-53, независимые MOSTAI-80/81/82 и G1–G4 сохраняются. Выпуск изолированного модуля не активирует legacy direct provider bypass или private calls.

KNOW-38 возвращает локальный unsealed `MaterialSearchResult`; его поля не считаются готовым model input. Этот модуль не создаёт вторую реализацию общего `ToolResult`. Если такой executable adapter понадобится MOSTAI-50, Lead назначает один owner и exact path отдельно.

Lead принимает точную версию и committed SHA локального контекстного артефакта как вход MOSTAI-50. Это не `Done` MOSTAI-49 и не независимый PASS всего пакета. Полная группа из трёх batch-reviewers запускается Root после готовности всего пакета. Общие existing service/UI/config/provider изменения и живая интеграция остаются за отдельным assignment/lease.

## CTX_HANDOFF_01: приватный consumer receipt

Amendment `batch03-MOSTAI49-context-handoff/2` изменяет только PreparationService, Assembler, PrivacyTest, TaskFrameTest, OfflineContextFixtures и этот документ. Старый артефакт `0.1-local` / commit `e3be2938b91ae5f583355d927d7c7122a9a8b911` остаётся в истории. Его supporting QA не переносится на новую версию без проверки нового SHA. Общие SourceBinding/Profile/Segment/Frame, PRIV17/G0 и действующие сервисы не изменяются.

Публичный `assistant-context/2` всегда содержит `contextRef` и `currentRef`, даже без task frame. `currentRef` — alias конкретного current artifact из авторизованного server snapshot. Assembler формирует alias map в момент фактической выдачи segment/source refs. Ни текст сообщения, ни его позиция, ни JSON от модели не используются для восстановления этой карты. Все публичные поля уже присутствуют в финальном payload, который перед публикацией целиком считает закреплённый counter.

В PreparationService добавлен optional constructor dependency `trustedReceiptPublisher`; отсутствие publisher даёт `BLOCKED` даже при наличии остальных четырёх зависимостей. Assembler требует private candidate sink. Эти callbacks задаются сервером и входят в TCB. Протокол не доказывает их доверенность криптографической подписью и не превращает локальный receipt в Gateway envelope, Vault или durable registry. В приложении новые DI bindings не регистрируются; положительная реализация publisher/resolver существует только в тестах.

Publisher имеет сигнатуру `(event, data, expectedDigests)`. Событие `lineage` получает приватные scope/conversation и возвращает закрытые `requestRef`, `requestRevision`, `conversationRef`, `issuedAt`, `expiresAt`, `now`. Lineage захватывается до projection/counter; origin identity и lifetime затем неизменны, clock не идёт назад и остаётся внутри lifetime. Истёкший request или смена lineage не может привязать уже собранный старый контекст к новому запросу.

Приватный `assistant-context-receipt/1` содержит actual context/current refs, payload digest, scope/hash, conversation, profile/fingerprint, фактические artifact/source/field/metadata связи и request lineage без текущего clock. Карта sources индексируется реальными выданными source aliases и хранит исходный source record. Этот объект передаётся только backend publisher; его поля и scope hashes не добавляются в model payload или trace. Payload/context/receipt digests связывают ровно эту публикацию; scopeHash относится только к scope, поэтому consumer обязан отдельно проверять source/field records.

Публикация двухфазная: `stage` сохраняет pending receipt, `commit` подтверждает точные context/payload/receipt digests после повторных snapshot/profile/lineage checks. Закрытый ack содержит `schemaVersion=assistant-context-receipt-ack/1`, `status`, `contextRef`, `payloadDigest`, `receiptDigest`. Неверный ack, исключение, отзыв scope/consent/source/profile или изменение lineage вызывает `BLOCKED` и best-effort `abort` того же context/digest tuple. Проверки выполняются до и после callbacks; publication errors не возвращают приватные исключения.

Consumer/resolver входит в backend TCB и не должен разрешать refs по одному JSON ack либо status. Он проверяет committed state, integrity реального stored receipt, ожидаемые context/payload/receipt digests, originating request/conversation/current artifact, текущие auth/ACL/consent/policy/profile, все source/field/provenance версии и lifetime при каждом разрешении. Только caller, получивший успешный producer result, может потреблять соответствующий receipt. При abort failure, unknown outcome или ошибке resolver guard потребление запрещается; publisher обязан обеспечивать pending isolation и idempotent revoke. Callback не становится authority только потому, что умеет вернуть совпадающие строки.

Все входы/карты/ack metadata отделяются от PHP references. Публикуется только последний действительно измеренный payload после budget compaction; не прошедший бюджет candidate остаётся локальным и не получает stage/commit. Rollback не переводит consumer на guessed map или raw history.

Проверки amendment дополнительно охватывают missing publisher, currentRef без frame, одинаковый текст при разных actual aliases, source alias mapping, callback/stored-map mutation, request replay, expiry, auth/source/profile revoke, смену lineage во время счёта, revoke вокруг stage/commit, exception после pending persistence и отказ бюджета с новой публичной metadata. Тестовый resolver иллюстрирует обязательный контракт, но не является production binding.