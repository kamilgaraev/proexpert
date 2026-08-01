# Качество и выпуск интеллектуальных AI-смет: план реализации

> **Для агентных исполнителей:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Цель:** доказать предсказуемость единого релиза на реальных проектах и обеспечить безопасное наблюдаемое включение.

**Архитектура:** закрытый эталонный набор фиксирует ожидаемую модель, объёмы и смету; benchmark gate сравнивает новый pipeline с ним до включения.

**Технологии:** существующий EstimateGeneration Benchmark, Laravel тесты, React Vitest, GlitchTip.

## Global Constraints

- Нет полного запуска без результатов эталонного корпуса всех поддерживаемых форматов.
- Нельзя считать технически успешной смету без проверяемого основания её ключевых строк.

### Task 1: Собрать эталонный корпус и ожидаемые результаты

**Files:**
- Create: `tests/Fixtures/EstimateGeneration/intelligence/manifest.json`
- Create: `tests/Fixtures/EstimateGeneration/intelligence/*`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Benchmark/AcceptanceBenchmarkGate.php`
- Test: `tests/Feature/EstimateGeneration/IntelligenceAcceptanceBenchmarkTest.php`

- [ ] Включить реальные обезличенные примеры PDF, скана/изображения, DXF/DWG и XLSX.
- [ ] Для каждого примера зафиксировать ожидаемые помещения, размеры, конфликты, ручные правки, quantities и ключевые строки сметы.
- [ ] Добавить gate: отсутствие источника у обязательной величины, неверная единица или неподтверждённое число блокируют результат.

### Task 2: Добавить метрики качества и себестоимости

**Files:**
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Observability/EloquentAiUsageStore.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Observability/ProjectModelQualityMetrics.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Operations/InspectEstimateGenerationProductionCommand.php`
- Test: `tests/Unit/EstimateGeneration/Observability/ProjectModelQualityMetricsTest.php`

- [ ] Считать стоимость одной успешной AI-сметы, число внутренних вызовов, долю повторов, число конфликтов, число ручных правок и время до готового результата.
- [ ] Отдельно считать полноту распознавания помещений/проёмов/размеров и точность объёмов относительно эталона.
- [ ] Не выдавать технические детали пользователям; метрики доступны только операционному интерфейсу.

### Task 3: Выполнить единый релизный прогон

**Files:**
- Create: `docs/runbooks/ai-estimate-intelligence-release.md`
- Modify: `docs/superpowers/specs/2026-08-01-ai-estimate-intelligence-design.md`

- [ ] Запустить все целевые backend/frontend тесты один раз после завершения реализации.
- [ ] Провести независимое ревью архитектуры, прав, данных, конкурентности и UI/UX.
- [ ] Зафиксировать baseline себестоимости относительно цены 500 ₽ и правило пересмотра цены только по измеренным данным.
- [ ] Включить релиз после прохождения эталонного корпуса и UX-приёмки; мониторить ошибки и качество по новым сессиям.
