# Первичный AI-слой AI-смет: план реализации

> **Для агентных исполнителей:** придерживаться TDD: сначала написать падающий тест, затем минимальную реализацию, после этого выполнить рефакторинг и релевантные проверки. В плане используются флажки `- [ ]`.

**Цель:** извлекать из всех документов семантические сущности и связывать их в модель проекта, а не возвращать только OCR-текст и названия листов.

**Архитектура:** форматные адаптеры формируют кандидаты, AI-контракт добавляет семантику и координаты, а merger переносит их в цифровую модель с доказательствами.

**Технологии:** существующий TimewebVisionProvider, OCR, CAD runtime, ProjectModelMerger.

## Global Constraints

- AI не создаёт финальные объёмы без доказательства.
- Контракт ответа закрытый и версионированный; неизвестное значение имеет статус `unknown`, а не выдуманное число.

### Task 1: Заменить ограниченный vision-контракт

**Files:**
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Vision/Providers/TimewebVisionProvider.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Vision/ProjectSheetAnalysisData.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Vision/ProjectSheetAnalysisValidator.php`
- Test: `tests/Unit/EstimateGeneration/Vision/ProjectSheetAnalysisValidatorTest.php`

- [ ] Описать элементы: помещения, стены, проёмы, оси, размерные цепочки, сантехника, мебель, конструктивные элементы, таблицы и листовые связи.
- [ ] Требовать `evidence_ref`, нормализованный полигон, уверенность и значение/единицу у каждого извлечённого факта.
- [ ] Добавить тесты на план с экспликацией, разрез с отметками, фасад, спецификацию и визуализацию.
- [ ] Отклонять ответы без источника, с некорректной геометрией или с неизвестными ключами.

### Task 2: Ввести маршрутизацию по роли листа

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Understanding/SheetRoleClassifier.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Understanding/SheetAnalysisRouter.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Application/Documents/ProductionDocumentUnitProcessor.php`
- Test: `tests/Unit/EstimateGeneration/Understanding/SheetAnalysisRouterTest.php`

- [ ] Определить роли: план, разрез, фасад, узел, экспликация, спецификация, визуализация, неизвестный лист.
- [ ] Для каждой роли задать ровно один целевой контракт и лимит результата; не выполнять повторный полный анализ без причины конфликта или недостатка данных.
- [ ] Добавить целевые повторные проверки только для конфликтных сущностей и связываемых листов.
- [ ] Записать причины повторной проверки в аудит сессии.

### Task 3: Свести результаты в модель проекта

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/Understanding/ProjectModelEvidenceWriter.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Pipeline/UnderstandDocumentsStage.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Pipeline/UnderstandObjectStage.php`
- Test: `tests/Feature/EstimateGeneration/ProjectModelUnderstandingPipelineTest.php`

- [ ] Сохранить кандидаты листа и доказательства отдельно от подтверждённой проекции.
- [ ] Сопоставить номер комнаты на плане со строкой экспликации, размеры с объектами и план с разрезом по осям.
- [ ] Добавить тест на примере: санузел содержит ванну, унитаз, биде и две раковины; значение площади берётся из экспликации.
- [ ] Добавить тест на разрез: отметки кровли и высота этажа доступны только с листом и конкретными размерными подписями.

