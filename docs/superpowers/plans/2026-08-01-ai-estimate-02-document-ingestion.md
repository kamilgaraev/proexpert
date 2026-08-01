# Универсальный вход документов AI-смет: план реализации

> **Для агентных исполнителей:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Цель:** создать единый версионированный вход для всех документов, прикреплённых к AI-смете.

**Архитектура:** каждый файл преобразуется в набор проверяемых единиц документа, но оригинал и встроенная структура сохраняются. Типовой контракт единицы содержит источник, координатное пространство, версию и производные материалы.

**Технологии:** Laravel, S3, PyMuPDF/существующая обработка PDF, CAD runtime, XLSX extractor.

## Global Constraints

- Никакой формат не идёт по неявной упрощённой ветке.
- Исходник и производные материалы изолированы по организации, проекту, сессии и версии файла.

### Task 1: Унифицировать контракт единицы документа

**Files:**
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/DocumentUnitType.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/ArtifactDocumentUnitDetector.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/ProductionDocumentUnitProcessor.php`
- Test: `tests/Unit/EstimateGeneration/Documents/ArtifactDocumentUnitDetectorTest.php`

- [ ] Добавить в контракт обязательные поля `source_kind`, `source_version`, `coordinate_space`, `artifact_path`, `artifact_sha256`, `artifact_version_id`.
- [ ] Написать матрицу тестов для PDF, изображения, DWG/DXF и XLSX: каждая единица имеет валидный источник и воспроизводимый индекс.
- [ ] Заменить форматные допущения в детекторе на явные адаптеры `PdfDocumentAdapter`, `ImageDocumentAdapter`, `CadDocumentAdapter`, `SpreadsheetDocumentAdapter`.
- [ ] Выполнить целевые unit-тесты и PHPStan изменённого модуля.

### Task 2: Довести извлечение встроенной структуры форматов

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Documents/Cad/CadDocumentAdapter.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Documents/Spreadsheet/SpreadsheetDocumentAdapter.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Services/Ocr/PdfTextLayerExtractor.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Services/Ocr/SpreadsheetDocumentExtractor.php`
- Test: `tests/Unit/EstimateGeneration/Documents/DocumentAdapterContractTest.php`

- [ ] Сформировать fixtures с нативным PDF, сканированным PDF, изображением, DXF/DWG и XLSX.
- [ ] Для CAD извлечь слои, блоки, полилинии, текст и размеры без растеризации как единственного источника.
- [ ] Для XLSX извлечь листы, заголовки, значения и формулы с адресами ячеек.
- [ ] Для PDF сохранить текстовый слой, геометрию и высококачественный рендер как взаимодополняющие источники.
- [ ] Запустить contract-тесты каждого адаптера.

### Task 3: Нормализовать жизненный цикл и прогресс

**Files:**
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/EloquentDocumentUnitAggregateReconciler.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Http/Presentation/GeometryReviewPayloadService.php`
- Modify: `prohelper_admin/src/features/estimate-generation/api/estimateGenerationReviewNormalizers.ts`
- Test: `tests/Feature/EstimateGeneration/EstimateGenerationDocumentReadinessTest.php`

- [ ] Показать пользователю этапы «Подготавливаем файл», «Читаем структуру», «Понимаем содержание», «Проверяем связи».
- [ ] Зафиксировать, что готовность документа зависит от полноты структуры, а не только от отсутствия HTTP-ошибки.
- [ ] Добавить тест, что изменение файла инвалидирует производные данные только этого документа и зависимые части модели.

