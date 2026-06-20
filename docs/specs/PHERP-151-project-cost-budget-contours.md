# PHERP-151: Контуры стоимости проекта, смет и бюджетирования

## Статус и цель

Документ фиксирует техническое правило для уже существующих и будущих потоков, где фигурируют суммы проекта, сметы, договора и управленческого бюджета.

Цель PHERP-151 - убрать смешение терминов и предотвратить неявную перезапись паспортной стоимости проекта из смет, CRM-конвертации или бюджетирования.

## Источники истины

### Проект: `projects.budget_amount`

`projects.budget_amount` - паспортная верхнеуровневая плановая стоимость проекта.

Использование:

- карточка проекта, список проектов, карта, сводки, KPI и executive dashboard;
- агрегаты уровня портфеля, холдинга, дашбордов и отчетов, где нужна одна плановая сумма проекта;
- фильтры и сортировки по плановой стоимости проекта.

Не является:

- строками управленческого бюджета;
- суммой сметы;
- договорной суммой;
- фактом выполнения или оплат.

Пользовательская подпись: `Плановая стоимость проекта` или короткая форма `Плановая стоимость`, если место в UI ограничено.

### Budgeting: `budget_versions`, `budget_lines`, `budget_amounts`

Budgeting - детальный управленческий бюджет.

Использование:

- версии бюджета, сценарии, периоды, ЦФО, статьи и месячные план/прогноз суммы;
- план-факт, WIP forecast, margin report, бюджетные лимиты и бюджетные операции;
- перенос presale-данных в бюджетные строки только отдельным явным действием.

Не должен автоматически следовать за `projects.budget_amount`. Паспортная стоимость может быть входной подсказкой для человека, но не заменяет `budget_lines` и `budget_amounts`.

Пользовательские подписи: `Управленческий бюджет`, `План бюджета`, `План и прогноз`, `Бюджетирование`.

### BudgetEstimates: `estimates.total_amount`, `estimates.total_amount_with_vat`

BudgetEstimates - проектные сметы и их версии.

Использование:

- состав работ, материалов, разделов, версий, approval snapshot;
- покрытие договора и связка сметы с договором;
- анализ стоимости сметы без автоматической подмены паспортной стоимости проекта.

Утверждение сметы не должно автоматически перезаписывать `projects.budget_amount`. Если бизнесу понадобится перенос суммы утвержденной сметы в плановую стоимость проекта, это должен быть отдельный явный сценарий с правом, подтверждением пользователя, аудитом и тестом на отсутствие скрытой перезаписи.

### Contract: `contracts.base_amount`, `contracts.total_amount`, `contracts.is_fixed_amount`

Contract - договорная сумма.

Использование:

- `base_amount` - базовая сумма фиксированного договора;
- `total_amount` - текущая договорная сумма с учетом правил договора, спецификаций и состояния;
- `is_fixed_amount` - признак того, можно ли менять сумму автоматически в интеграциях.

Договорная сумма может совпадать с плановой стоимостью проекта на этапе конвертации, но это разные поля и разные контуры ответственности.

Пользовательская подпись: `Сумма договора`.

## Правила записи `projects.budget_amount`

### Ручное создание и редактирование проекта

`ProjectService` должен записывать `projects.budget_amount` через `ProjectBudgetAmountService`.

Контекст записи:

- `contour`: `project_planned_cost`;
- `source`: `manual`;
- `creates_budget_lines`: `false`.

Контекст хранится в `projects.additional_info.budget_amount_context` и нужен для аудита происхождения суммы.

### CRM-конвертация PHERP-113

`DealConversionWizardService` может предзаполнить плановую стоимость проекта из сделки, тендера или КП, если сумма доступна пользователю по правам.

Правила:

- `project.fields.budget_amount` в preview означает только будущую плановую стоимость проекта;
- `contract.fields.base_amount` и `contract.fields.total_amount` означают договорную сумму;
- `budget_seed` является заготовкой для будущего управленческого бюджета и не создает `budget_lines`/`budget_amounts`;
- после convert запись проекта идет с `source = crm_conversion`;
- `budget_seed.creates_budget_lines = false` до отдельного явного действия.

API preview должен отдавать человекочитаемые контексты:

- `project.budget_amount_context.contour = project_planned_cost`;
- `project.budget_amount_context.label = Плановая стоимость проекта`;
- `contract.amount_context.contour = contract_amount`;
- `budget_seed.kind = deferred_budget_seed`.

### Утверждение сметы

Канонический event для утверждения сметы:

`App\BusinessModules\Features\BudgetEstimates\Events\EstimateApproved`.

Legacy event `App\Events\EstimateApproved` и слушатели, которые могли писать `projects.budget_amount` из сметы, не должны использоваться.

При утверждении сметы разрешены сценарии:

- создание approval snapshot;
- синхронизация покрытия договора в рамках `EstimateCoverageService`, если соблюден guard `is_fixed_amount`;
- дальнейшие действия, которые не меняют паспортную стоимость проекта без явного пользовательского решения.

Запрещено:

- автоматически записывать `projects.budget_amount = estimates.total_amount_with_vat`;
- регистрировать скрытый listener `UpdateProjectBudget` на approval event;
- делать bridge из legacy event в новый event без отдельной необходимости и тестов.

### Future PHERP-110/111

Будущий перенос presale-сметы в бюджетирование должен создавать или обновлять `budget_versions`, `budget_lines`, `budget_amounts` отдельным action/service.

Он не должен использовать `projects.budget_amount` как детальный бюджет и не должен менять его побочно. Если action предлагает обновить плановую стоимость проекта, это отдельный checkbox/step с явной подписью `Обновить плановую стоимость проекта`, правом доступа, audit event и тестом.

## Защита от случайной перезаписи

Обязательные правила для новых правок:

- не писать `projects.budget_amount` напрямую из сметных, договорных или бюджетных сервисов;
- для проектного create/update использовать `ProjectBudgetAmountService`;
- для CRM-конвертации передавать `source = crm_conversion`;
- для ручного редактирования передавать `source = manual`;
- в тестах фиксировать `budget_amount_context`;
- в UI не показывать `projects.budget_amount` как просто `Бюджет`, если рядом есть Budgeting, сметы или договоры;
- любые новые слушатели на `EstimateApproved` должны явно доказывать, что не меняют `projects.budget_amount`.

## Проверки PHERP-151

Минимальный набор проверок:

- unit: `ProjectBudgetAmountService` нормализует сумму, пишет контекст и запрещает auto-overwrite из утвержденной сметы;
- unit: канонический `BudgetEstimates\Events\EstimateApproved` остается единственным approval event, unsafe listeners отсутствуют;
- feature/API: CRM preview разделяет `project.budget_amount_context`, `contract.amount_context` и `budget_seed`;
- feature/API: создание и обновление проекта сохраняет `budget_amount_context`;
- UI: карточка проекта, overview, executive dashboard и CRM conversion wizard используют подписи `Плановая стоимость проекта`, `Сумма договора`, `Заготовка для управленческого бюджета`.

## Терминологическая матрица

| Контур | Поля | Пользовательская подпись | Кто пишет |
| --- | --- | --- | --- |
| Project planned cost | `projects.budget_amount` | `Плановая стоимость проекта` | `ProjectService`, CRM conversion через `ProjectBudgetAmountService` |
| Management budgeting | `budget_versions`, `budget_lines`, `budget_amounts` | `Управленческий бюджет`, `План бюджета` | Budgeting services и явные budget actions |
| Estimate | `estimates.total_amount`, `estimates.total_amount_with_vat` | `Смета`, `Сумма сметы` | BudgetEstimates services |
| Contract amount | `contracts.base_amount`, `contracts.total_amount`, `contracts.is_fixed_amount` | `Сумма договора` | Contract services и разрешенные estimate coverage flows |

## Acceptance criteria

- Утверждение сметы не меняет `projects.budget_amount` автоматически.
- В коде нет legacy `App\Events\EstimateApproved` как второго approval event.
- CRM conversion preview и summary не называют одну и ту же сумму просто `Сумма`.
- `budget_seed` явно помечен как отложенная заготовка и не создает бюджетные строки.
- Project UI показывает `projects.budget_amount` как `Плановая стоимость проекта` или `Плановая стоимость`.
- Создание и обновление проекта записывает контекст происхождения суммы.
