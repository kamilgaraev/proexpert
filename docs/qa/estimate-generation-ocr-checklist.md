# QA-чеклист OCR для AI-генерации смет

## Подготовка

- Проверить, что OCR-конфигурация заполнена для окружения.
- Проверить очередь `estimate-generation`.
- Подготовить тестовые файлы:
  - PDF с читаемым текстом;
  - скан/фото чертежа;
  - XLSX с площадями и зонами;
  - файл плохого качества;
  - неподдерживаемый формат;
  - файл больше лимита.

## Backend

- Создать сессию без описания.
- Загрузить PDF, убедиться, что документ получил `queued`.
- Выполнить обработку, убедиться, что появились:
  - pages;
  - facts;
  - facts_summary;
  - quality_score;
  - quality_level;
  - provider/model.
- Проверить, что XLSX обрабатывается без вызова OCR-провайдера.
- Проверить, что pending-документ блокирует `analyze` и `generate`.
- Проверить, что failed/needs_review блокирует `generate`.
- Проверить `retry` для failed/needs_review/ignored.
- Проверить `ignore` для failed/needs_review.
- Проверить, что чужой документ в чужой сессии недоступен.
- Проверить, что API не возвращает `storage_path`.

## Генерация

- Загрузить документ без ручного описания.
- Дождаться `ready`.
- Запустить analyze.
- Запустить generate.
- Проверить, что:
  - object profile взял площадь из OCR;
  - package plan учитывает склад/офис/инженерию;
  - quantities не падают к дефолтам;
  - draft traceability содержит document source refs;
  - local estimates, sections и work items имеют refs на документ.

## Frontend

- Загрузка файлов показывает выбранные имена.
- После upload генерация не стартует автоматически.
- Pending-документы видны в панели, генерация заблокирована.
- Ready-документы показывают качество и найденные факты.
- Failed/needs_review показывают действия retry/ignore.
- После retry включается polling.
- После ignore summary обновляется.
- После готовности документов кнопка генерации доступна.
- Source refs в инспекторе отображают документ и страницу.

## Observability

- В логах есть события queued/started/completed/failed.
- В логах нет:
  - содержимого OCR-текста;
  - storage path;
  - имени файла;
  - API-ключей;
  - raw payload провайдера.
- Есть provider/model, pages_count, facts_count, quality, elapsed_ms.

## Регрессия

- Старый модуль `AIEstimates` продолжает работать своим контуром.
- Новый `EstimateGeneration` не использует `AIEstimates\Services\FileProcessing\FileParserService`.
- Старые сценарии генерации по ручному описанию работают без документов.

## Автотесты

Backend:

```bash
php artisan test tests\Unit\EstimateGeneration\Ocr tests\Feature\EstimateGeneration
```

Admin:

```bash
npx tsc --noEmit
npx vitest run src/services/estimateGenerationWorkflowService.test.ts src/pages/Estimates/estimateGenerationPresentation.test.ts
```

## Критерий готовности

Фича готова к релизу, если документы реально меняют анализ/пакеты/объемы, пользователь видит состояние OCR, генерация не стартует на неполных данных, а ошибки можно безопасно диагностировать по логам без раскрытия содержимого документов.
