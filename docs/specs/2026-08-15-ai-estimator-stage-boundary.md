# Граница этапов AI-сметчика и восстановление частичного результата

## Статус и область

Спецификация фиксирует production-контракт МОСТ для session 72 / document 174 и для всех последующих запусков AI-сметчика. Область: backend/API и admin. Платные AI-вызовы, ручные изменения production и изменение месячной квоты не входят в выпуск.

## Причина инцидента

`ClarificationQuestionProjector` выполнялся во время суммаризации документа, readiness и detail read. Он считал пустой набор вариантов и более восьми вариантов фатальной ошибкой. В session 72 это исключение возникло после корректного operator stop и откатило агрегирующую транзакцию: две оплаченные страницы и terminal unit-состояния сохранились, но document/session остались `processing`/`5%`, а detail read стал отвечать 500.

Архитектурная причина — пользовательские вопросы были производной каждой страницы документа, хотя вопрос является решением по объединённой модели всего комплекта. Read-модель документа тем самым зависела от контракта следующего этапа.

## Целевой поток

1. «Документы» принимает файл, распознаёт и хранит факты, размеры, материалы, наблюдения, evidence, противоречия, неизвестные значения, limitations, ход выполнения и внутренний usage journal.
2. Документная обработка не создаёт, не валидирует и не публикует пользовательские вопросы или варианты ответа. Неоднозначность остаётся typed conflict/unknown/limitation с источником.
3. После завершения комплекта `ProjectUnderstandingCoordinator` объединяет результаты и создаёт канонические вопросы проекта.
4. Только endpoint этапа «Вопросы AI» проецирует эти вопросы в пользовательский контракт. Ноль AI-вариантов означает открытый вопрос. Валидные AI-варианты ограничиваются первыми восемью в стабильном порядке; доменные ответы не выдумываются. UI всегда добавляет «Другое» (до 500 символов) и «Оставить нерешённым».
5. Нормы, цены, расчёты и выпуск сметы остаются строгими серверными этапами.

## Устойчивость частичных результатов

- Невалидный отдельный claim/observation изолируется как typed limitation с evidence/source locator; остальные элементы страницы и остальные страницы не теряются.
- Tenant, project, session, source version, lineage и security fences остаются fail-closed.
- Detail read никогда не выполняет question projection и возвращает сохранённый partial результат.
- Operator stop завершает execution progress как `terminal / total`, отдельно сообщает `usable / total`, сохраняет фактическую стоимость и не превращает отменённые до wire страницы в ошибки системы.
- Для document 174 ожидается execution `22/22`, usefulness `2/22` (либо фактическое число сохранённых пригодных страниц), outcome `stopped/partial` и сохранённый usage. Входящий после stop page 3 может завершить уже начатый физический вызов, но не разрешает downstream dispatch.

## API и admin

- Document detail/list/snapshot используют единые execution/usefulness/outcome поля и не содержат `ai_questions`, document-level `questions`, `ai_question_count` и публичный `cost_journal`.
- Внутренние usage/cost записи сохраняются для оператора и аналитики, но рублёвые суммы и лимиты не показываются пользователю во время обработки.
- После reload admin показывает сохранённые страницы и текст «Обработка остановлена, частичный результат сохранён».
- Ошибка фонового detail refetch не очищает последнюю успешную detail-модель. Последующий 200 заменяет её и снимает предупреждение.
- При внутреннем cost stop UI говорит обычным языком, что обработка приостановлена и требует подтверждения продолжения, без суммы или названия технического лимита.

## Иерархия внутренних cost guard

Месячная квота `10 AI-смет` остаётся отдельным продуктовым billing-контуром. Внутренний cost guard — эксплуатационный предохранитель физического AI wire и не резервирует/не расходует квоту.

Authoritative contract:

| Переменная | Default | Назначение |
| --- | ---: | --- |
| `ESTIMATE_GENERATION_DOCUMENT_COST_LIMIT_RUB` | `600.00` | Верхняя граница текущей lineage одного документа |
| `ESTIMATE_GENERATION_SESSION_COST_LIMIT_RUB` | `900.00` | Общая граница одной полной AI-сметы: все документы, synthesis, composer, auditor и correction |
| `ESTIMATE_GENERATION_DOCUMENT_COST_CONFIRMATION_INCREMENT_RUB` | `300.00` | Явное расширение document guard после подтверждения |
| `ESTIMATE_GENERATION_SESSION_COST_CONFIRMATION_INCREMENT_RUB` | `450.00` | Явное расширение session guard после подтверждения |

Значения задаются `.env`, читаются только через `config/estimate-generation.php` и фиксируются в `.env.example`. Если production уже содержит старое значение `50.00`, его меняют штатным configuration/deploy процессом на `600.00`; вручную `.env` на сервере не редактируется.

Rationale: адаптивный worst-shaped документ на 22 страницы даёт до 88 document calls (три независимых observer + arbiter на содержательной странице), тогда как простая страница использует один observer. Наблюдавшийся остановленный запуск дал `17.150265 ₽` до stop и `23.196780 ₽` после завершения уже начатого вызова; прежний неуспешный полный запуск стоил около `80.24 ₽`. Default не выводится из одного прогона: `600 ₽` оставляет ограниченный запас для 88 вызовов и вариативности токенов, а `900 ₽` ограничивает сумму нескольких документов и downstream ролей. Каждый guard проверяется атомарно до wire, unknown pricing остаётся fail-closed; возможен только bounded overshoot уже начатого вызова.

## Confirmation и состояния

- Document guard и session guard хранят текущий подтверждённый ceiling отдельно от конфигурационного default.
- Достижение session guard блокирует следующий physical attempt независимо от стадии и переводит сессию в resumable user-action state с исходной стадией для продолжения.
- Подтверждение увеличивает только соответствующий ceiling на настроенный increment, сохраняет audit/idempotency и возобновляет штатный dispatch/recovery. Оно не запускает платный вызов само по себе.
- Stop имеет приоритет над подтверждением и новым dispatch.

## Регрессионные границы

- production-shaped document 174 с legacy `choices=[]` и `choices>8`: detail 200, вопросов в документе нет;
- те же вопросы появляются только из current project understanding на Questions AI; zero/many options bounded без 500;
- stop → refresh: list/snapshot/detail согласованы, execution/usefulness/cost journal корректны, partial pages видимы;
- один malformed observation/claim даёт typed limitation, остальные элементы сохраняются;
- PostgreSQL harness доказывает stop/reconcile/read и атомарные document/session guards без skip;
- admin MSW/Vitest доказывает сохранение успешной detail-модели при одноразовом 500 и её замену финальным 200;
- public DTO backend/admin не содержит obsolete document question/cost fields.

## Выпуск и canary

После целевых тестов, PHPStan/TypeScript/lint и одного последовательного self-review создаются отдельные backend/admin PR. После CI — merge и штатный deploy. Canary только read-only: release SHA, readiness, list/snapshot/detail session 72/document 174, terminal/usefulness contract, отсутствие нового GlitchTip occurrence. Retry документа, новая смета и production AI запрещены.
