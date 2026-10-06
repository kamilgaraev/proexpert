# МОСТ: локальный самостоятельный loop помощника

Версия: `most-ai-assistant-loop/0.1.1-local`. MOSTAI-50, пакет03. Исходный lease `batch03-MOSTAI50-local-loop/1` разрешает восемь новых PHP-классов Loop, пять тестовых файлов и этот документ. Исправления B03-SEC-01 и QA03-HISTORY-01 выполняются по lease `batch03-MOSTAI50-review-r1-fixes/2` в семи существующих файлах поверх candidate `c2c1cb16aa754947ae60422674e56f9e28ec2686`. Это dormant local artifact: без production DI, endpoint, provider SDK, private calls или effect execution. Production default constructor возвращает BLOCKED.

## Входы и границы изменений

Fresh main: `282ce5c21d60ba15c2db953252c08c942709dd02`. Принятый49: `7e01bf5dde68e5cd0da940dbe173a09b08cc3472`, context `0.2.1-local`, public schema `assistant-context/2`. Принятый38: `0b16f8368cc6fd16d288e9a2c35045be0a7b1ae1`, local material-search artifact. Non-ff imports: `1a923666238dee6877afbdbee2363cd4698613e4`, затем import baseline `26c293ba00874416d7efc9a6e487a17a90f81c8b`.

13+9 dependency blobs проверяются против этих источников; они read-only. Own50 delta считается от import baseline, whole-batch freeze отдельно фиксирует все три heads/imports. Никакие Context/MaterialSearch/G0/PRIV/config/registry/DI/UI/provider/dependency файлы не меняются. Общий executable ToolResult имеет одного owner: ASSIST50, `Services/Loop/AssistantToolResult.php`; он не дублирует backend38 или shared privacy factory.

## Модель и сервер

Локальный model driver выбирает plan, tool, refine, summary или final answer. Semantic routing по ключевым словам сервером не выполняется. Model outputs являются закрытыми недоверенными actions; principal, tenant, permission, произвольный backend method/url или effect из JSON не принимаются. Plan не записывается как chain-of-thought и не становится системной инструкцией.

Набор read-only tools конечного local fixture: material.search и material.read_selected. Это не новые runtime registrations. Значимые изменения остаются за canonical preview/confirm; local loop отказывает effect intent, не вызывает реальный effect executor и не изображает выполненную операцию.

Сервер независимо проверяет актуальность scope, source/field bindings, source count, price/unit/currency/provenance/claim scope, limiter и semantic/privacy verdict. Валидный natural reply сохраняется дословно; нет server field dump или универсальной приписки no-data. Неподтверждённый ответ получает ограниченную возможность model repair либо безопасный отказ.

## Consumer receipt и контекст

Loop вызывает реальный accepted49 producer и принимает только успешный server-originating READY. Backend authority должен предоставить actual committed store, receipt digest, snapshot, profile, lineage и текущие issuer artifacts. Local receipt проверяет payload/context/current binding, stored integrity, собственный scope/profile/request/lifetime, actual alias→source/field/metadata/content associations и роли. Model JSON/ack/status не создают registry или authority.

При каждом resolve, callback и новом step повторяются текущие проверки. Private map остаётся backend-only; driver получает public context и opaque refs. Никаких позиционных или текстовых догадок. После tool projection допускается только контролируемое добавление server history/new source: исходный prefix, current/system/media, scope и старые sources остаются неизменными. Старый receipt проверяется по его issued chronology и свежим полномочиям, затем49 заново строит strict current chronology. Это не разрешение менять клиентскую history.

Current photo/transcript/topic/entity/filter context может дать ответ без forced material search. Его selected-context ClaimScope выдаётся loop из проверенного текущего receipt; это не whole-corpus proof. Model refine/summary указывает только известный текущий issuer alias. Frame/summary re-projection, bindings и summary prefix остаются проверками49.

Ответ по предыдущему обсуждению может ссылаться на реально включённые и посчитанные user/assistant history messages либо attested summary текущего receipt. Summary покрывает проверенный непустой prefix authoritative history и сохраняет исходные issuer source-field bindings. Текущий вопрос не подменяет источник истории. System/frame/tool aliases, raw или foreign refs и aliases выпавших сообщений из прошлого receipt не расширяют context ClaimScope. Перед проверкой provenance повторно проверяются actual store, источники и поля, ACL и lifetime; stale evidence не доходит до semantic validator. Числовые claims по-прежнему требуют отдельного canonical material proof.

## Tool result и разные namespaces

KNOW38 MaterialSearchResult/localEnvelope остаётся UNSEALED. Один adapter принимает actual backend result под trusted native AuthenticatedPrivateContext и свежим private gate; модель не выбирает контекст авторизации. Текущие scope/source/record/price права повторно проверяются для всех накопленных результатов, в том числе перед финальным ответом.

Серверный finite public/synthetic projector регистрирует новый known tool artifact и его source-field/provenance association, добавляет его в server chronology и выдаёт новую selection map. Только после очередного49 producer такие data появляются в model context. Raw localEnvelope не передаётся driver напрямую. Projection callback и semantic validator — backend TCB, их presence или JSON-флаг не доказывают production privacy.

Model callRef/contextRef/profile fingerprint отдельно связываются с новым KNOW requestRef/profileRef/profileVersion/resultGenerationRef. Эти namespaces не приравниваются. Каждая backend next ref отображается один раз в issuer-owned local selection ref; stale/foreign/replayed selection после смены result generation отвергается.

Coverage complete у selected_entity/search_subset означает полноту этого scope. Corpus-wide утверждение требует отдельного соответствующего complete proof; текущий38 его не выдаёт. Partial допускает честный ограниченный вывод, no-data применяется только к действительному отсутствию evidence. Сводка по выбранному фото не требует полноты всего корпуса.

## Бюджеты и финальная граница

Используется pinned49 model/tokenizer identity и reserve. Полный driver input включает tool definitions, context/receipt metadata и projected tool outputs; output каждого attempt также считается trusted counter, а model usage fields не являются accounting authority. Дополнительно ограничены steps/tool calls/repairs, elapsed time и cumulative tokens. Guard работает вокруг driver/tokenizer/gate/execute/project; новые действия не начинаются после deadline.

Array output модели глубоко копируется сразу после возвращения driver, до следующих authority/tokenizer/clock callbacks. Accounting и parsing используют эту же отделённую копию. Последующая мутация PHP references исходного output не меняет принятую action или ответ. READY допустим для исходного валидного значения в пределах лимита; неподсчитанное позднее значение не используется.

Отдельный terminal callback существующего backend TCB возвращает coherent authority, clock и native privateContext. Они проверяются без последующих callbacks перед выдачей READY, чтобы не повторять49 tail-lineage race. Consumer guards всё равно обязательны позже. Unknown/missing callback/profile/tokenizer/validator/registry либо incoherent result дают BLOCKED; реальные model context/tool/vision capabilities не объявлены подтверждёнными.

Ответ полностью буферизуется до проверки. Trace содержит только конечные action/status/counters и opaque call refs; prompt, raw tool result, private map, plan text/CoT и секреты не логируются.

## Fixture universe и проверки

Composed tests используют фактические accepted49 producer/receipt и accepted38 `material-search-v1`: B25 7800.00 RUB/m3, B30 8250.50. Этот universe отличается от frozen QA product oracle 4800/57600. Тесты не подменяют цену и не ослабляют PG53; отдельная совместимая trusted fixture для PG при необходимости должна быть назначена явно.

Локальный tokenizer — byte-based synthetic oracle49, не BPE фактической модели. Driver — конечный scripted model double. Semantic validator проверяет finite заранее утверждённые ответы и canonical claims; это механика loop/dataflow, не оценка качества живой модели.

Проверки: composed search→read/refine→natural answer, фото и два follow-up без forced tool, новый topic, partial против whole corpus, price/unit/currency errors, issuer/store/ref replay, revoke между callback, missing projection/validator/default constructor, elapsed/steps/tool calls/repairs/cumulative tokens, output counting, PII buffering/effects denial и trace privacy. R1 regression schedules проверяют plan/final reference mutation в authority/tokenizer callbacks с честным подсчётом и точным сохранением исходного final reply; history/summary recall с исходной provenance; исключение system/raw/foreign/dropped refs и revocation source/field/ACL/lifetime до semantic stage. PHPUnit/PHPStan/lint/UTF-8/diff запускаются на собственных standalone tools/TEMP bootstrap без app autoload.files/Laravel/DB/provider или чужого vendor.

Не подтверждены actual-model quality/capacity/vision, production history/DI/privacy/wire/OCR/RAG/effects и PG/G1–G4. Root организует три independent batch reviewers после готовности всех задач. Author tests не являются независимым PASS; branch/worktree сохраняются до разрешённого whole-batch release/cleanup.
