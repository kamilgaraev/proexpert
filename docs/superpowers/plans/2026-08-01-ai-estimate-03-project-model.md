# Цифровая модель проекта AI-смет: план реализации

> **Для агентных исполнителей:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Цель:** заменить разрозненные факты и результаты распознавания единой версионированной моделью проекта.

**Архитектура:** модель хранит сущности, утверждения, связи, доказательства и пользовательские правки. Сметный pipeline читает только её подтверждённую проекцию.

**Технологии:** Laravel, PostgreSQL jsonb, S3, существующие EstimateGenerationDocumentFact/DrawingElement/QuantityTakeoff.

## Global Constraints

- Пользовательская правка имеет максимальный приоритет.
- Каждое значение обязано иметь доказательство или источник ручной правки.

### Task 1: Определить доменную схему и миграции

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/BuildingModel/ProjectModelEntity.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/BuildingModel/ProjectModelAssertion.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/BuildingModel/ProjectModelRelation.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/BuildingModel/ProjectModelCorrection.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/migrations/*_create_estimate_generation_project_model_tables.php`
- Test: `tests/Unit/EstimateGeneration/BuildingModel/ProjectModelContractTest.php`

- [ ] Написать тесты схемы для помещения, стены, проёма, размера, таблицы, конструктивного элемента и объёма.
- [ ] Добавить таблицы с `organization_id`, `project_id`, `session_id`, `source_version`, стабильным ключом, JSONB-полями и ограничениями целостности.
- [ ] Не запускать миграции локально; проверить синтаксис и тесты с существующим test bootstrap.

### Task 2: Реализовать правила приоритета и конфликтов

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/BuildingModel/ProjectModelMerger.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/BuildingModel/ProjectModelConflictResolver.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/BuildingModel/ConfirmedProjectModelProjector.php`
- Test: `tests/Unit/EstimateGeneration/BuildingModel/ProjectModelMergerTest.php`

- [ ] Зафиксировать порядок источников: ручная правка, CAD/таблица/явный размер, согласованная геометрия, AI-кандидат.
- [ ] Написать тесты конфликтов площади, размера, назначения помещения и проёма.
- [ ] Реализовать проекцию подтверждённой модели, которая не возвращает конфликтующие или неподтверждённые величины как готовые.

### Task 3: Сделать правки обратимыми и локальными

**Files:**
- Create: `app/BusinessModules/Addons/EstimateGeneration/BuildingModel/ApplyProjectModelCorrection.php`
- Create: `app/Http/Requests/EstimateGeneration/ApplyProjectModelCorrectionRequest.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/routes.php`
- Test: `tests/Feature/EstimateGeneration/ProjectModelCorrectionApiTest.php`

- [ ] Создать API команд исправления с optimistic locking и идемпотентным ключом.
- [ ] Хранить предыдущее и новое значение, автора, время, основание и затронутые зависимости.
- [ ] Добавить API отмены последней правки без потери истории.
- [ ] Проверить права организации/проекта, типы данных и response-контракт.

