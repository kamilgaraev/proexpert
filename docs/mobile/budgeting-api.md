# Мобильное бюджетирование

API показывает исполнение бюджетного плана проекта из опубликованных снимков EVM-контроля. Данные смет в этот API не входят.

Для обоих запросов нужны мобильная аутентификация, активная организация, доступ к модулю `budgeting`, доступ пользователя к проекту и право `reports.project_control.view` в строгом контексте проекта.

## Сводка проекта

`GET /api/v1/mobile/budgeting/projects/{project}/summary`

Успешный ответ содержит `data.project`, `data.snapshot` и `data.totals_by_currency`. Показатели ограничены стандартными полями EVM: `bac_minor`, `pv_minor`, `ev_minor`, `sv_minor`, `spi`, `currency`. Если снимка ещё нет, `snapshot` равен `null`, а массив итогов пуст.

## Карточки исполнения

`GET /api/v1/mobile/budgeting/projects/{project}/execution-cards?page=1&per_page=20`

`data` — массив карточек текущего снимка, `meta` — объект с `current_page`, `per_page`, `total` и `last_page`. Максимальный размер страницы — 100. Карточка содержит `row_key`, `wbs_code`, `task_id`, `currency` и стандартные показатели EVM из сводки. Конфиденциальные фактические затраты и производные поля не выдаются.
