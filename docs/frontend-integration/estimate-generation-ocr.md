# Интеграция OCR в интерфейс AI-генерации смет

## Цель

Пользователь должен видеть, что документы реально участвуют в генерации сметы: файл загружен, распознан, качество оценено, факты извлечены, генерация доступна только после готовности данных.

## Основной сценарий

1. Пользователь создает сессию генерации.
2. Пользователь загружает документы.
3. UI показывает документы и `documents_summary`.
4. Пока `has_pending = true`, кнопка генерации заблокирована.
5. Если `has_action_required = true`, UI показывает действия:
   - повторить обработку;
   - исключить документ.
6. Когда `can_generate = true`, пользователь запускает анализ и генерацию.
7. В результате сметы source refs показывают документ, страницу и фрагмент.

## API-клиент

`estimateGenerationService` должен поддерживать:

- `uploadDocuments(projectId, sessionId, files)`;
- `listDocuments(projectId, sessionId)`;
- `getDocument(projectId, sessionId, documentId)`;
- `retryDocument(projectId, sessionId, documentId, reason?)`;
- `ignoreDocument(projectId, sessionId, documentId, reason?)`;
- `getSessionStatus(projectId, sessionId)`.

`uploadDocuments`, `retryDocument`, `ignoreDocument` возвращают `documents_summary`; клиент должен сразу обновлять session state.

## Поллинг

Поллинг нужен не только для генерации, но и для OCR:

- продолжать polling, если `isGenerationInProgress(session)`;
- продолжать polling, если `session.documents_summary.has_pending`;
- остановить polling после завершения OCR, если генерация еще не запускалась;
- после terminal generation status загрузить свежую session, packages и summary.

## Состояния UI

### Empty

Документов нет: показать нейтральный текст и кнопку загрузки.

### Selected files

Файлы выбраны, но еще не отправлены: показать имена файлов.

### Pending

`documents_summary.has_pending = true`:

- показать progress/status по каждому документу;
- заблокировать генерацию;
- оставить страницу в polling.

### Ready

`ready_count > 0`, нет pending/action required:

- показать success-состояние;
- разрешить генерацию;
- показать найденные площадь, этажность, зоны, инженерные системы.

### Action required

`has_action_required = true`:

- показать warning;
- для failed/needs_review показать retry и ignore;
- не запускать генерацию до решения пользователя.

## Source refs

Для `source_refs.type = document` показывать:

`Найдено в документах: {filename}, стр. {page_number}`

`value` у document refs может отсутствовать. Ключи React-ноды нужно строить по `type`, `document_id`, `page_number`, `excerpt` и индексу.

## UX-правила

- Не показывать пользователю raw provider codes, storage path, payload, DTO, exception, SQL.
- Ошибки показывать человеческим текстом из API.
- Не стартовать генерацию автоматически сразу после upload.
- Не считать OCR декоративной загрузкой: readiness документов должен управлять доступностью кнопок.
- Для уже существующей сессии выбранные файлы добавлять в текущую сессию, а не создавать новую.

## Проверки

Минимум перед релизом:

- `npx tsc --noEmit`;
- `npx vitest run src/services/estimateGenerationWorkflowService.test.ts src/pages/Estimates/estimateGenerationPresentation.test.ts`;
- ручная проверка polling/status на тестовой сессии с pending, ready, failed, needs_review.
