# Реализация обновления помощника МОСТ

Исходный план согласован пользователем 29 сентября 2026 года. Полный аудит: `C:/Users/kamilgaraev/Desktop/prohelper_full/audit/ai-assistant-audit-2026-09-28.md`. Этот документ фиксирует общий контракт для параллельной реализации; статус готовности подтверждается проверками, а не наличием файлов.

## Рабочие каталоги и ограничения

- Backend: `C:/Users/kamilgaraev/Desktop/prohelper_full/.codex-worktrees/ai-assistant-luna-audit`, ветка `task/ai-assistant-luna-audit`, актуальный main уже подтянут и изменения сохранены.
- Админка: `C:/Users/kamilgaraev/Desktop/prohelper_full/prohelper_admin`; ЛК: `C:/Users/kamilgaraev/Desktop/prohelper_full/prohelper_land`; мобильное приложение: `C:/Users/kamilgaraev/Desktop/prohelper_full/prohelpers_mobile`. У каждого клиента ветка `task/ai-assistant-upgrade`.
- Другие исполнители работают одновременно. Сохранять существующие изменения; не откатывать их и не копировать зависимости из соседних checkout.
- Root владеет AIAssistantService, AIAssistantController, ServiceProvider, routes, scheduler, общими переводами и интеграцией.
- DB-тесты пишет владелец блока, запускает root последовательно через `tests/Runtime/run-postgres-tests.ps1`. Запрещены ручные migrate/artisan DB/tinker и любые обращения к рабочей БД. PHP syntax/unit без DB разрешены.
- Выполнять полный выпуск после прохождения технических gates: проверенного состава релиза, подтверждённых DB/runtime backups, измеренного Luna QA и валидного readiness evidence. До readiness approval платные списания не включать. Изменяющийся статус проверок и production отражается в [release checklist](ai-assistant-release-checklist.md); partial test markers и узкие targets не подтверждают полный PASS.
- PHP strict types, UTF-8, без новых комментариев в коде. Использовать текущие бизнес-сервисы, реальные предметные права и изоляцию организации.
- Пользователь разрешает запуск субагентов только на GPT-6 Sol или GPT-6 Luna.

## Единый контракт API

Префиксы: `/api/v1/admin/ai-assistant`, `/api/v1/ai-assistant`, `/api/v1/mobile/ai-assistant`. Ответы используют соответствующий AdminResponse, LandingResponse, MobileResponse: `{success, data, message, meta?}`. ID существующих чатов и сообщений числовые; клиенты нормализуют их при необходимости. UUID используется для request_id, quote_id, новых подтверждений и памяти.

- `GET /conversations?page&per_page`: `data` — массив доступных личных и явно общих чатов; `meta` — `{current_page,last_page,per_page,total}`. Автоматического организационного доступа нет.
- `POST /conversations {title?}`: новый личный чат. Значение scope не предоставляет другим пользователям доступ.
- `GET /conversations/{id}` сохраняет прежний ответ `{conversation,messages}`.
- `GET /conversations/{id}/history?page&per_page`: `data` — сообщения, `meta` — пагинация. Первая страница содержит последние сообщения; внутри страницы порядок хронологический, сортировка по created_at и id. Ответы с недоступными текущему участнику источниками скрываются целиком.
- `DELETE /conversations/{id}`: владелец удаляет чат и связанные материалы помощника.
- `GET /conversations/{id}/participants`: `[{user_id,name,role:viewer|editor}]`.
- `PUT /conversations/{id}/participants {participants:[{user_id,role}]}`: только автор, только активные участники этой организации. Доступ к чату не расширяет доступ к данным.
- `GET /memory`: личная память текущего пользователя внутри организации.
- `POST /memory {content,confirmed:true,source_refs?:[],conversation_id?}` и `PATCH /memory/{uuid} {content,confirmed:true}`: явное подтверждение. `DELETE /memory/{uuid}` удаляет запись. Память не становится общей для всей организации.
- `GET /credits/balance`: `{included_minor,purchased_minor,reserved_minor,available_minor,total_minor,base_period_expires_at,packs:[{id,units_minor,amount_minor}],charging_enabled}`. Единицы и рубли передаются в сотых.
- `GET /credits/history?page&per_page`: собственный организационный журнал, с разрешением на оплату для финансовой детализации.
- `POST /credits/quote {message,conversation_id?,request_id:UUID,profile:short|normal|detailed,allow_actions?,context?}`: `{quote_id,min_units_minor,max_units_minor,expires_at,profile,price_version}`. Оценка связана с точным серверным каноническим запросом.
- `POST /chat`: тот же запрос плюс quote_id. Ответ `{request_id,conversation_id,message,credit_usage:{charged_minor,reserved_minor,available_after_minor},status:completed,usage}`. Повторный request_id возвращает сохранённый результат, не запускает модель и не списывает ещё раз.
- `GET /requests/{request_id}`: `{request_id,conversation_id,status,stage}`. `POST /requests/{request_id}/cancel` прекращает дальнейшие вызовы и освобождает остаток. Чужие запросы недоступны.
- `POST /credits/purchase {pack_id:ai-credits-1000|ai-credits-5000|ai-credits-10000}`: `{order_id,confirmation_url}`. Только права управления оплатой, существующая коммерческая оплата, заказ типа ai_credits.
- `POST /actions/preview {conversation_id,action:<предложение из ответа>}`: серверная сохранённая подготовка, срок 10 минут, идентификатор и preview_token.
- `POST /actions/execute {conversation_id,action:{id,preview_token,confirmed:true}}`: принимает только ссылку на подготовленные сервером аргументы; повторный запуск возвращает сохранённый результат.
- `POST /documents`, `GET /documents/{id}`, `POST /documents/{id}/ocr/quote`, `POST /documents/{id}/ocr/confirm`: права исходной сущности, оценка OCR и разрешение владельца организации.

Message.metadata сохраняет существующую форму и дополнительно содержит `validation_status` (verified, partial, unverified), `source_refs`, `rag_context.sources`, `entity_references`, `request_id`, происхождение финансовых фактов и fetched_at. Источники включают navigation.url и идентификаторы исходных сущностей.

## Основные сервисные границы

`AssistantDataAccessPolicy` принадлежит исполнителю безопасности:

- `canReadSource(User,int,array):bool`
- `canReadEntity(User,int,string,string|int):bool`
- `assertCanReadEntity(...)`
- `canReadDomain(User,int,string):bool`
- `allowedSourceTypes(User,int):array`
- `applyToSources(Eloquent Builder,User,int):Builder`

Неизвестные сущности и источники закрыты. file_document/assistant_document наследуют актуальные права parent entity, включая удаление parent. Все виды поиска фильтруют доступ до ранжирования и LIMIT. Права инструментов, истории, памяти, файлов и отчётов повторно проверяются на текущем пользователе.

`AssistantActionService::preview/execute(array,int,User,?Conversation)` использует серверную запись, права, цель исходного запроса, version/state, блокировку и идемпотентность. allow_actions=false запрещает подготовку и запись. Старый HMAC в контроллере убрать.

`ConversationManager` предоставляет findAccessibleConversation/queryVisibleConversations/getHistoryPage/getParticipants/updateParticipants/getSummary; контекст истории принимает текущего actor. Старые ответы без проверенного source_refs не являются доказательством фактов.

`AICreditService` предоставляет quote/begin/recordSuccessfulCost/recordProviderCost/finalize/cancel/grant. Финансовые проводки транзакционные и идемпотентные. Учитывать сумму успешных вызовов всей задачи; не округлять себестоимость до копеек для каждого вызова. Неудачные технические повторы учитываются только во внутренней себестоимости. Начальная конфигурация `enforce=false`; включение возможно после evaluator v5 readiness: 200 разных фактических успешных model-quality вызовов, полный набор проверок и экономика. До подтверждения оценки никакой rollout не считать готовым.

## Точность, токены, данные

- Единственная модель новых генеративных вызовов — GPT-6 Luna, также OCR, knowledge и генерация смет. Embedding отдельный. Исторические модели/тарифы сохраняются.
- Chat Completions с tools: reasoning_effort=none. Транспорт текущий OpenAI-совместимый.
- Профили short:8192/1024/2 calls; normal:16384/2048/4; detailed:32768/4096/6. Полный текущий запрос и обязательные инструкции сохраняются. Схемы/история/результаты входят во входной бюджет. Compatible BPE token count калибруется по usage, не выдаётся за документированную точную кодировку Luna.
- Источники, документы, история, память — недоверенные данные вне system instructions. Модель выбирает только зарегистрированные инструменты со строгими схемами. Организация, actor и адреса определяются сервером.
- Смета разрешается по номеру/названию с закреплением ID; неоднозначность возвращает только доступные варианты. Денежные уточнения сохраняют выбор. Финансовые числа берутся из текущих серверных данных/расчётов и проверяются; старый ответ модели не источник.
- RAG — отдельные источники каждой позиции, ресурса, раздела; полнота всех активных бизнес-модулей. Обновление после commit, небольшие пакеты, heartbeat/lease/recovery, очистка удалённых источников только по успешно завершённой сверке, измеримое покрытие и лаг.
- Память пользователя управляемая и подтверждённая. 90 дней с последнего сообщения/действия, просмотр не продлевает. Очистка связанных файлов/summary/memory, финансовый журнал отдельно.
- Баланс: 5000 единиц за оплаченный период активного помощника, базовые расходуются первыми и сгорают; купленные бессрочные. Пакеты:1000/1000руб,5000/4500руб,10000/8000руб. Task charge = max(0.5,ceil(successfulCostRub/0.18*2)/2), подтверждённый предел не превышается.

## Проверки и интеграция

Каждый владелец пишет содержательные тесты своего поведения, в том числе PostgreSQL feature tests, и сообщает root пути/контракт. Root запускает DB-тесты в изолированном контуре, исправляет общий bootstrap тестов, связывает маршруты и сервисы. Синтаксис/дифф не заменяет поведенческую проверку. UI: TypeScript/ESLint/Vitest; mobile: analyze и целевые tests. Не считать отсутствие запуска тестов подтверждённым успехом.

Актуальные статусы, root-run logs, текущие PostgreSQL и mobile APK pending jobs перечислены в [release checklist](ai-assistant-release-checklist.md). Число автоматических тестов не заменяет фактические сценарии Luna.

Production миграция, очистка истории, конверсия остатков и платное списание — отдельный контролируемый выпуск с предварительной сверкой. Локальная реализация должна быть пригодна к такому выпуску, без обходов прав и временных заглушек.
