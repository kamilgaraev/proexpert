# Рабочее место проверки AI-смет: план реализации

> **Для агентных исполнителей:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Цель:** дать пользователю быстрый и понятный контроль над любой распознанной сущностью до выпуска сметы.

**Архитектура:** UI читает только API цифровой модели, показывает источник и наложение сущностей, отправляет командные правки и получает пересчитанные зависимости.

**Технологии:** React/TypeScript/Vite, существующий `EstimateGenerationWorkspacePage`, типизированный API-клиент.

## Global Constraints

- Никаких технических ошибок и терминов в пользовательском интерфейсе.
- Пользователь может начать с готового результата и перейти к источнику одним действием.

### Task 1: Создать модель API и навигацию проверки

**Files:**
- Create: `prohelper_admin/src/features/estimate-generation/api/projectModelApi.ts`
- Create: `prohelper_admin/src/features/estimate-generation/types/projectModel.ts`
- Modify: `prohelper_admin/src/features/estimate-generation/pages/EstimateGenerationWorkspacePage.tsx`
- Test: `prohelper_admin/src/features/estimate-generation/pages/EstimateGenerationWorkspacePage.test.tsx`

- [ ] Добавить вкладку «Проверка проекта» вместо разрозненных технических экранов.
- [ ] Реализовать список документов, листов, конфликтов и сущностей с фильтрами по типу и статусу.
- [ ] Добавить состояния загрузки, пустого результата, ошибки и блокирующей проверки.
- [ ] Проверить переходы с этапов «Документы», «Модель здания», «Черновик сметы».

### Task 2: Реализовать просмотр листа и редактор сущностей

**Files:**
- Create: `prohelper_admin/src/features/estimate-generation/components/SourceSheetViewer.tsx`
- Create: `prohelper_admin/src/features/estimate-generation/components/ProjectEntityInspector.tsx`
- Create: `prohelper_admin/src/features/estimate-generation/components/CorrectionForm.tsx`
- Test: `prohelper_admin/src/features/estimate-generation/components/CorrectionForm.test.tsx`

- [ ] Отрисовать лист с привязкой выделения к координатам доказательства.
- [ ] В инспекторе показывать значение, источник, уверенность, связанные сущности и влияние на расчёты.
- [ ] Разрешить редактирование типа, количества, единицы, размера, назначения помещения и связи.
- [ ] После сохранения показать пересчитанный список зависимостей и возможность отмены правки.

### Task 3: Провести UX-приёмку сценариев

**Files:**
- Create: `prohelper_admin/src/features/estimate-generation/__tests__/review-workspace.test.tsx`
- Modify: `docs/superpowers/specs/2026-08-01-ai-estimate-intelligence-design.md`

- [ ] Проверить на ноутбуке и узком экране сценарии: исправление комнаты, подтверждение размера, разрешение конфликта, переход из строки сметы к листу.
- [ ] Зафиксировать время прохождения каждого сценария и устранить препятствия до перехода к плану 06.
- [ ] Запустить Vitest, ESLint/Prettier изменённых файлов и `npx tsc --noEmit`.

