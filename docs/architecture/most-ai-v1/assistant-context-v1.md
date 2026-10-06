# МОСТ: локальный контекст помощника

Версия артефакта: `most-ai-assistant-context/0.1-local`. Задача MOSTAI-49, пакет03. Формат результата: `assistant-context/1`. Это изолированный локальный артефакт перед Gateway. Он не разрешает передачу данных провайдеру и не подключён к действующим endpoint, очередям или DI приложения.

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
