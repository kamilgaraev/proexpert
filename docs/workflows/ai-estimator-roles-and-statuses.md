# AI-сметчик МОСТ: права, статусы и переходы

## Права

Модуль использует ровно восемь проектных прав:

| Право | Возможность |
| --- | --- |
| `estimate_generation.view` | Просмотр сессий, документов, черновика, пакетов и очереди проверки |
| `estimate_generation.create` | Создание AI-сессии |
| `estimate_generation.upload_documents` | Загрузка исходных документов, чертежей и рисунков |
| `estimate_generation.generate` | Анализ исходных данных, запуск и повтор расчёта |
| `estimate_generation.review` | Повтор или исключение документа, обратная связь и решения проверки |
| `estimate_generation.select_normative` | Поиск и ручной выбор нормативной позиции |
| `estimate_generation.export` | Экспорт расчётного черновика |
| `estimate_generation.apply` | Создание новой обычной сметы из готового AI-черновика |

Права назначаются ролям через `config/RoleDefinitions`. Техническое имя права не заменяет проверку принадлежности сессии текущей организации и проекту.

## Статусы

| Статус | Смысл | Основное действие оператора |
| --- | --- | --- |
| `draft` | Сессия создана, исходные данные ещё формируются | Загрузить материалы или запустить обработку |
| `processing_documents` | Документы распознаются и сверяются | Наблюдать прогресс, повторить проблемный документ |
| `input_review_required` | Во входных данных нужны решения | Проверить или исключить спорный материал |
| `ready_to_generate` | Исходные данные готовы | Запустить расчёт |
| `generating` | Формируется и проверяется черновик | Наблюдать этап и попытку |
| `estimate_review_required` | В черновике есть обязательные вопросы | Проверить объёмы, нормы, ресурсы, цены и допущения |
| `ready_to_apply` | Черновик прошёл обязательные проверки | Применить или повторно сформировать |
| `applying` | Создаётся новая обычная смета | Дождаться атомарного завершения |
| `applied` | Новая обычная смета создана | Открыть результат или архивировать сессию |
| `failed` | Активный этап завершился ошибкой | Повторить сохранённый этап, отменить или архивировать |
| `cancelled` | Незавершённая работа отменена | Архивировать при необходимости |
| `archived` | Историческая сессия убрана из рабочего списка | Только просмотр и аудит |

## Матрица переходов

| Из | Событие | В |
| --- | --- | --- |
| `draft` | `start_document_processing` | `processing_documents` |
| `draft` | `cancelled` | `cancelled` |
| `processing_documents` | `documents_ready` | `ready_to_generate` |
| `processing_documents` | `documents_need_review` | `input_review_required` |
| `processing_documents` | `failed` | `failed` |
| `processing_documents` | `cancelled` | `cancelled` |
| `input_review_required` | `input_confirmed` | `ready_to_generate` |
| `input_review_required` | `retried` или `documents_changed` | `processing_documents` |
| `input_review_required` | `cancelled` | `cancelled` |
| `ready_to_generate` | `generation_started` | `generating` |
| `ready_to_generate` | `documents_changed` | `processing_documents` |
| `ready_to_generate` | `cancelled` | `cancelled` |
| `generating` | `generation_needs_review` | `estimate_review_required` |
| `generating` | `generation_ready` | `ready_to_apply` |
| `generating` | `documents_changed` | `processing_documents` |
| `generating` | `failed` | `failed` |
| `generating` | `cancelled` | `cancelled` |
| `estimate_review_required` | `generation_ready` | `ready_to_apply` |
| `estimate_review_required` | `generation_started` | `generating` |
| `estimate_review_required` | `review_updated` | `estimate_review_required` |
| `estimate_review_required` | `documents_changed` | `processing_documents` |
| `estimate_review_required` | `cancelled` | `cancelled` |
| `ready_to_apply` | `apply_started` | `applying` |
| `ready_to_apply` | `generation_started` | `generating` |
| `ready_to_apply` | `review_reopened` | `estimate_review_required` |
| `ready_to_apply` | `documents_changed` | `processing_documents` |
| `ready_to_apply` | `cancelled` | `cancelled` |
| `applying` | `apply_completed` | `applied` |
| `applying` | `failed` | `failed` |
| `failed` | `retried` после обработки документов | `processing_documents`, `input_review_required` или `ready_to_generate` по состоянию документов |
| `failed` | `retried` после генерации | `generating` с новой попыткой |
| `failed` | `retried` после применения | `ready_to_apply` |
| `failed` | `cancelled` | `cancelled` |
| `failed` | `archived` | `archived` |
| `cancelled` | `archived` | `archived` |
| `applied` | `archived` | `archived` |

Переходы, отсутствующие в таблице, запрещены. Для `retried` допустимы только сохранённые статусы `processing_documents`, `generating` и `applying`; последний безопасно нормализуется в `ready_to_apply`.

Явный `retried` из `input_review_required` повторно обрабатывает только `uploaded`, `queued`, `processing`, `failed` и `needs_review`. `ignored` не возвращается в очередь. При отсутствии таких документов workflow либо подтверждает готовность входа, либо оставляет сессию в состоянии проверки без зависания в `processing_documents`.

## Терминальные состояния и инварианты

Метод `isTerminal()` возвращает `true` только для `applied`, `cancelled` и `archived`. `failed` не терминален: его можно повторить, отменить или архивировать.

Все поля состояния сессии меняет только workflow state store. Каждая успешная мутация сравнивает ожидаемую версию, атомарно изменяет состояние и повышает `state_version`. Идентификатор попытки защищает от поздней публикации устаревшего результата.

AI-модуль не изменяет существующие обычные сметы. Единственный адаптер записи создаёт новый граф сметы при `apply`. Чтение для обучения, экспорта и проверки номера выполняют внешние интеграционные адаптеры через нейтральные контракты; прямого списка разрешённых чтений внутри AI-модуля нет. Повторный `apply` возвращает тот же идентификатор результата.

## Действия оператора

- `confirm_input` вызывает `POST /{session}/confirm-input` и событие `input_confirmed`.
- `retry` вызывает `POST /{session}/retry` и событие `retried`.
- `cancel` вызывает `POST /{session}/cancel` и событие `cancelled`.
- `archive` вызывает `POST /{session}/archive` и событие `archived`.
- `apply` вызывает `POST /{session}/apply`, затем события `apply_started` и `apply_completed` в одной транзакционной операции.

Отмена и архивирование требуют подтверждения интерфейса. Snapshot не показывает действие, если переход запрещён текущим статусом или у пользователя нет соответствующего права.
