# AI-сметчик МОСТ: Frontend и Filament Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Заменить монолитный экран AI-сметчика пошаговым typed workspace и расширить Filament до полного центра управления сессиями, токенами, стоимостью, ошибками, очередями, datasets, benchmark и настройками.

**Architecture:** React feature разделяется на route shell, session store, typed API, семь независимых step-компонентов и review cockpit. Backend является источником status/available actions/readiness. Filament использует observability contracts Plan 2 и benchmark/training contracts Plan 3; мутации ограничены отдельными system-admin permissions и никогда не изменяют обычные сметы.

**Tech Stack:** React, TypeScript, Vite, MUI, existing HTTP client, Vitest, MSW, Laravel 11, Filament, PHPUnit, Larastan, gstack.

## Global Constraints

- Plan 1–3 полностью выполнены; не придумывать frontend shape вместо реального v2 API.
- Не запускать `npm run build` для `prohelper_admin`.
- Не сохранять старый workspace, legacy services/types или runtime fallback после завершения плана.
- Route-level page не должен содержать бизнес-вычисления и должен оставаться меньше 500 строк.
- Frontend не вычисляет `can_apply`, status transitions или blocking rules; использует backend `available_actions` и `blocking_issues`.
- Все network contracts типизированы и нормализуются в service layer.
- Для API-driven tests использовать MSW, не ad hoc axios mocks.
- Перед добавлением новой frontend/visualization библиотеки использовать Context7; предпочесть SVG/MUI и существующие зависимости.
- Filament не показывает API keys, полный prompt, содержимое документов, токены авторизации и персональные диагностические данные.
- Filament не изменяет и не удаляет обычные сметы.
- После frontend tasks выполнять `npx tsc --noEmit` и целевые Vitest; после Filament tasks — PHPUnit и Larastan.

---

## Структура frontend

```text
prohelper_admin/src/features/estimate-generation/
  api/estimateGenerationApi.ts
  api/estimateGenerationContracts.ts
  api/estimateGenerationNormalizers.ts
  model/estimateGenerationStore.tsx
  model/useEstimateGenerationSession.ts
  model/useEstimateGenerationPolling.ts
  model/permissions.ts
  pages/EstimateGenerationWorkspacePage.tsx
  components/EstimateGenerationStepper.tsx
  steps/ObjectSetupStep.tsx
  steps/DocumentsStep.tsx
  steps/GeometryReviewStep.tsx
  steps/BuildingModelStep.tsx
  steps/DraftStep.tsx
  steps/ReviewStep.tsx
  steps/SummaryStep.tsx
  documents/...
  geometry/...
  draft/...
  review/...
  shared/...
  test/handlers.ts
  test/fixtures.ts
```

## Структура Filament

```text
app/Filament/Pages/EstimateGeneration/
  EstimateGenerationDashboard.php
  EstimateGenerationSettings.php

app/Filament/Resources/EstimateGeneration/
  SessionResource.php
  UsageResource.php
  FailureResource.php
  PipelineCheckpointResource.php
  BenchmarkRunResource.php
  TrainingDatasetResource.php
```

### Task 1: Зафиксировать frontend v2 contracts и MSW fixtures

**Files:**
- Create: `../prohelper_admin/src/features/estimate-generation/api/estimateGenerationContracts.ts`
- Create: `../prohelper_admin/src/features/estimate-generation/api/estimateGenerationNormalizers.ts`
- Create: `../prohelper_admin/src/features/estimate-generation/test/fixtures.ts`
- Create: `../prohelper_admin/src/features/estimate-generation/test/handlers.ts`
- Create: `../prohelper_admin/src/features/estimate-generation/api/estimateGenerationNormalizers.test.ts`
- Reference: `app/BusinessModules/Addons/EstimateGeneration/Application/Sessions/SessionSnapshotData.php`

**Interfaces:**
- Consumes: exact backend snapshot, building model, packages, review, usage and action contracts.
- Produces: stable TypeScript types and defensive normalizers for all later UI tasks.

- [ ] **Step 1: Написать failing normalizer test**

```ts
import { describe, expect, it } from 'vitest';
import { normalizeSessionSnapshot } from './estimateGenerationNormalizers';
import { sessionSnapshotFixture } from '../test/fixtures';

describe('normalizeSessionSnapshot', () => {
  it('normalizes optional collections without changing backend decisions', () => {
    const snapshot = normalizeSessionSnapshot({
      ...sessionSnapshotFixture,
      warnings: null,
      blocking_issues: undefined,
    });

    expect(snapshot.warnings).toEqual([]);
    expect(snapshot.blockingIssues).toEqual([]);
    expect(snapshot.availableActions).toEqual(sessionSnapshotFixture.available_actions);
    expect(snapshot.status).toBe('ready_to_generate');
  });
});
```

- [ ] **Step 2: Запустить test**

Run from `prohelper_admin`: `npx vitest run src/features/estimate-generation/api/estimateGenerationNormalizers.test.ts`

Expected: FAIL, module отсутствует.

- [ ] **Step 3: Создать exact contracts**

```ts
export type EstimateGenerationStatus =
  | 'draft'
  | 'processing_documents'
  | 'input_review_required'
  | 'ready_to_generate'
  | 'generating'
  | 'estimate_review_required'
  | 'ready_to_apply'
  | 'applying'
  | 'applied'
  | 'failed'
  | 'cancelled'
  | 'archived';

export interface AvailableAction {
  action: 'upload_documents' | 'start_document_processing' | 'confirm_input' | 'generate' | 'review' | 'apply' | 'retry' | 'cancel' | 'archive';
  label: string;
  method: 'POST' | 'PATCH' | 'DELETE';
  endpoint: string;
  requires_confirmation: boolean;
}

export interface EstimateGenerationSessionSnapshotDto {
  id: number;
  status: EstimateGenerationStatus;
  processing_stage: string;
  processing_progress: number;
  state_version: number;
  available_actions: AvailableAction[];
  blocking_issues: QualityIssueDto[] | null;
  warnings: QualityIssueDto[] | null;
  next_action: AvailableAction['action'] | null;
  documents_summary: DocumentsSummaryDto;
  estimate_summary: EstimateSummaryDto;
  review_summary: ReviewSummaryDto;
  usage_summary: UsageSummaryDto;
  applied_estimate_id: number | null;
  updated_at: string;
}
```

Определить все referenced DTOs в этом файле, не использовать `any`.

- [ ] **Step 4: Реализовать normalizers**

Normalizer преобразует snake_case DTO в camelCase view model, нормализует nullable arrays и выбрасывает `EstimateGenerationContractError` при отсутствии required scalar fields. Он не добавляет actions и не меняет status.

- [ ] **Step 5: Создать MSW handlers**

Handlers покрывают snapshot 200/304, upload progress, geometry confirmation 409 stale version, review decision, apply и server error.

- [ ] **Step 6: Запустить tests и commit в admin repo**

```bash
cd ../prohelper_admin
npx vitest run src/features/estimate-generation/api/estimateGenerationNormalizers.test.ts
npx tsc --noEmit
git add src/features/estimate-generation/api src/features/estimate-generation/test
git commit -m "feat[lk]: типизированы контракты AI-сметчика"
```

Expected: tests PASS, TypeScript `0 errors`.

### Task 2: Реализовать typed API и адаптивный polling

**Files:**
- Create: `../prohelper_admin/src/features/estimate-generation/api/estimateGenerationApi.ts`
- Create: `../prohelper_admin/src/features/estimate-generation/model/estimateGenerationStore.tsx`
- Create: `../prohelper_admin/src/features/estimate-generation/model/useEstimateGenerationSession.ts`
- Create: `../prohelper_admin/src/features/estimate-generation/model/useEstimateGenerationPolling.ts`
- Create: `../prohelper_admin/src/features/estimate-generation/model/useEstimateGenerationPolling.test.tsx`

**Interfaces:**
- Consumes: v2 endpoints and ETag contract Plan 2.
- Produces: one session state source, commands and polling lifecycle.

- [ ] **Step 1: Написать polling test с fake timers**

```tsx
it('stops polling in terminal state and sends If-None-Match', async () => {
  vi.useFakeTimers();
  const { result } = renderHook(() => useEstimateGenerationPolling({ projectId: 1, sessionId: 10 }), {
    wrapper: TestEstimateGenerationProvider,
  });

  await act(() => vi.advanceTimersByTimeAsync(2_000));
  expect(snapshotRequests.at(-1)?.headers.get('If-None-Match')).toBe('W/"estimate-generation-10-2-100"');

  server.use(snapshotHandler({ status: 'applied', state_version: 3 }));
  await act(() => vi.advanceTimersByTimeAsync(4_000));
  const countAtTerminal = snapshotRequests.length;
  await act(() => vi.advanceTimersByTimeAsync(30_000));
  expect(snapshotRequests).toHaveLength(countAtTerminal);
  expect(result.current.isPolling).toBe(false);
});
```

- [ ] **Step 2: Запустить test**

Run: `npx vitest run src/features/estimate-generation/model/useEstimateGenerationPolling.test.tsx`

Expected: FAIL.

- [ ] **Step 3: Реализовать API methods**

```ts
export interface EstimateGenerationApi {
  getSnapshot(projectId: number, sessionId: number, etag?: string): Promise<SnapshotResponse>;
  createSession(projectId: number, input: CreateSessionInput): Promise<SessionSnapshot>;
  uploadDocuments(projectId: number, sessionId: number, files: File[]): Promise<SessionSnapshot>;
  executeAction(projectId: number, sessionId: number, action: AvailableAction, stateVersion: number): Promise<SessionSnapshot>;
  confirmGeometry(projectId: number, sessionId: number, command: ConfirmGeometryInput): Promise<BuildingModelResponse>;
  listPackages(projectId: number, sessionId: number, page: number): Promise<PackagePage>;
  listReviewItems(projectId: number, sessionId: number, filters: ReviewFilters): Promise<ReviewPage>;
}
```

- [ ] **Step 4: Реализовать store**

State: snapshot, etag, activeStep, selectedPackageId, selectedReviewItemId, network status. Commands используют backend action objects; после mutation всегда принимают snapshot из response, а не оптимистично меняют status.

- [ ] **Step 5: Реализовать polling policy**

- `processing_documents`/`generating`/`applying`: 2 s первые 30 s, затем 5 s;
- `input_review_required`/`estimate_review_required`/`ready_to_apply`: polling off;
- hidden tab: 15 s;
- terminal statuses: off;
- 304 не вызывает React state update;
- network error: exponential 2/5/10/30 s с max 30 s и visible offline banner.

- [ ] **Step 6: Запустить tests и commit**

```bash
npx vitest run src/features/estimate-generation/model
npx tsc --noEmit
git add src/features/estimate-generation/api src/features/estimate-generation/model
git commit -m "feat[lk]: добавлено состояние сессии AI-сметчика"
```

Expected: PASS, `0 errors`.

### Task 3: Создать route shell и пошаговую навигацию

**Files:**
- Create: `../prohelper_admin/src/features/estimate-generation/pages/EstimateGenerationWorkspacePage.tsx`
- Create: `../prohelper_admin/src/features/estimate-generation/components/EstimateGenerationStepper.tsx`
- Create: `../prohelper_admin/src/features/estimate-generation/components/SessionSummaryStrip.tsx`
- Create: `../prohelper_admin/src/features/estimate-generation/model/permissions.ts`
- Modify: `../prohelper_admin/src/App.tsx`
- Modify: `../prohelper_admin/src/components/layout/SidebarMenu.tsx`
- Create: `../prohelper_admin/src/features/estimate-generation/pages/EstimateGenerationWorkspacePage.test.tsx`

**Interfaces:**
- Consumes: store and snapshot.
- Produces: accessible seven-step shell and permission-aware route.

- [ ] **Step 1: Написать route/step test**

```tsx
it('opens the backend next action step and hides forbidden actions', async () => {
  server.use(snapshotHandler({
    status: 'input_review_required',
    next_action: 'confirm_input',
    available_actions: [{ action: 'confirm_input', label: 'Подтвердить данные', method: 'POST', endpoint: '/confirm', requires_confirmation: false }],
  }));

  renderWorkspace('/projects/1/estimates/ai-workspace/10');

  expect(await screen.findByRole('tab', { name: 'Проверка геометрии' })).toHaveAttribute('aria-selected', 'true');
  expect(screen.queryByRole('button', { name: 'Создать смету' })).not.toBeInTheDocument();
});
```

- [ ] **Step 2: Запустить test**

Run: `npx vitest run src/features/estimate-generation/pages/EstimateGenerationWorkspacePage.test.tsx`

Expected: FAIL.

- [ ] **Step 3: Реализовать seven-step shell**

Steps: `object`, `documents`, `geometry`, `building`, `draft`, `review`, `summary`. Backend `next_action` maps only к recommended step; пользователь может открыть уже доступные read-only steps. Недоступный step показывает причину из blocking issue, не скрывается без объяснения.

- [ ] **Step 4: Подключить route и navigation permission**

Route использует `estimate_generation.view`; create action требует `estimate_generation.create`. Sidebar не хардкодит роль, только permission.

- [ ] **Step 5: Запустить tests и commit**

```bash
npx vitest run src/features/estimate-generation/pages/EstimateGenerationWorkspacePage.test.tsx src/components/layout/SidebarMenu.test.tsx
npx tsc --noEmit
git add src/features/estimate-generation src/App.tsx src/components/layout/SidebarMenu.tsx
git commit -m "feat[lk]: добавлен новый workspace AI-сметчика"
```

Expected: PASS.

### Task 4: Реализовать параметры объекта и документы

**Files:**
- Create: `../prohelper_admin/src/features/estimate-generation/steps/ObjectSetupStep.tsx`
- Create: `../prohelper_admin/src/features/estimate-generation/steps/DocumentsStep.tsx`
- Create: `../prohelper_admin/src/features/estimate-generation/documents/DocumentDropzone.tsx`
- Create: `../prohelper_admin/src/features/estimate-generation/documents/DocumentList.tsx`
- Create: `../prohelper_admin/src/features/estimate-generation/documents/DocumentDetailsPanel.tsx`
- Create: `../prohelper_admin/src/features/estimate-generation/steps/DocumentsStep.test.tsx`

**Interfaces:**
- Consumes: create/upload/process available actions and document processing units.
- Produces: validated session input and observable per-document progress/retry/review.

- [ ] **Step 1: Написать upload state test**

```tsx
it('shows partial document failure without hiding successful pages', async () => {
  server.use(snapshotHandlerWithDocuments({
    ready: 8,
    failed: 1,
    reviewRequired: 1,
  }));

  renderDocumentsStep();

  expect(await screen.findByText('8 страниц обработано')).toBeInTheDocument();
  expect(screen.getByText('1 страница требует повторной обработки')).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Повторить страницу 9' })).toBeEnabled();
});
```

- [ ] **Step 2: Запустить test**

Run: `npx vitest run src/features/estimate-generation/steps/DocumentsStep.test.tsx`

Expected: FAIL.

- [ ] **Step 3: Реализовать object form**

Поля строятся по backend input contract: mode, building type, region, price period, construction type, description, known area/floors/height. Submit disabled только по frontend format validation; business blockers приходят backend.

- [ ] **Step 4: Реализовать documents UI**

Поддержать drag/drop, file type/size hints, upload progress, классификацию листов, quality, used/ignored flag, page units, safe errors, retry/ignore actions и preview через signed URL. Не читать содержимое файла полностью в JS для предварительного парсинга.

- [ ] **Step 5: Запустить tests и commit**

```bash
npx vitest run src/features/estimate-generation/steps/DocumentsStep.test.tsx
npx tsc --noEmit
git add src/features/estimate-generation/steps/ObjectSetupStep.tsx src/features/estimate-generation/steps/DocumentsStep.tsx src/features/estimate-generation/documents
git commit -m "feat[lk]: улучшена загрузка документов AI-сметы"
```

Expected: PASS.

### Task 5: Реализовать визуальную проверку геометрии

**Files:**
- Create: `../prohelper_admin/src/features/estimate-generation/steps/GeometryReviewStep.tsx`
- Create: `../prohelper_admin/src/features/estimate-generation/geometry/GeometryCanvas.tsx`
- Create: `../prohelper_admin/src/features/estimate-generation/geometry/GeometryOverlay.tsx`
- Create: `../prohelper_admin/src/features/estimate-generation/geometry/ScaleConfirmationPanel.tsx`
- Create: `../prohelper_admin/src/features/estimate-generation/geometry/ElementInspector.tsx`
- Create: `../prohelper_admin/src/features/estimate-generation/geometry/geometryTransforms.ts`
- Create: `../prohelper_admin/src/features/estimate-generation/geometry/geometryTransforms.test.ts`
- Create: `../prohelper_admin/src/features/estimate-generation/steps/GeometryReviewStep.test.tsx`

**Interfaces:**
- Consumes: source image dimensions, normalized source polygons, building model and geometry confirm endpoint.
- Produces: typed scale and element corrections with current state version.

- [ ] **Step 1: Написать coordinate transform test**

```ts
it('maps normalized source coordinates into a letterboxed viewport and back', () => {
  const transform = createGeometryTransform({ source: [2000, 1000], viewport: [800, 600] });
  const screenPoint = transform.toViewport([0.5, 0.5]);

  expect(screenPoint).toEqual([400, 300]);
  expect(transform.toSource(screenPoint)).toEqual([0.5, 0.5]);
});
```

- [ ] **Step 2: Написать scale confirmation test**

Пользователь выбирает две точки, вводит `10`, unit `м`, preview показывает calculated scale, submit отправляет pixel points, meters и state version; 409 reload-ит snapshot и предлагает повторить действие.

- [ ] **Step 3: Запустить tests**

Run: `npx vitest run src/features/estimate-generation/geometry src/features/estimate-generation/steps/GeometryReviewStep.test.tsx`

Expected: FAIL.

- [ ] **Step 4: Реализовать SVG overlay без новой canvas dependency**

Использовать `<svg viewBox="0 0 1 1" preserveAspectRatio="xMidYMid meet">`; polygons имеют keyboard-selectable transparent buttons/paths, visible focus, label и confidence. Цвет не является единственным индикатором: добавить pattern/icon/text status.

- [ ] **Step 5: Реализовать inspector**

Разрешенные edits: room name/type/polygon, wall geometry/material/type, opening geometry/type, floor height и scale. Собрать exact operations contract Plan 3. Никаких arbitrary path strings из пользовательского ввода.

- [ ] **Step 6: Запустить tests и commit**

```bash
npx vitest run src/features/estimate-generation/geometry src/features/estimate-generation/steps/GeometryReviewStep.test.tsx
npx tsc --noEmit
git add src/features/estimate-generation/geometry src/features/estimate-generation/steps/GeometryReviewStep.tsx src/features/estimate-generation/steps/GeometryReviewStep.test.tsx
git commit -m "feat[lk]: добавлена проверка чертежей AI-сметы"
```

Expected: PASS.

### Task 6: Реализовать модель здания и объемы

**Files:**
- Create: `../prohelper_admin/src/features/estimate-generation/steps/BuildingModelStep.tsx`
- Create: `../prohelper_admin/src/features/estimate-generation/building/BuildingTree.tsx`
- Create: `../prohelper_admin/src/features/estimate-generation/building/QuantityTable.tsx`
- Create: `../prohelper_admin/src/features/estimate-generation/building/EvidenceDrawer.tsx`
- Create: `../prohelper_admin/src/features/estimate-generation/steps/BuildingModelStep.test.tsx`

**Interfaces:**
- Consumes: normalized building model, quantities and evidence endpoint.
- Produces: understandable floor/room/element hierarchy and source inspection.

- [ ] **Step 1: Написать evidence rendering test**

```tsx
it.each([
  ['evidenced', 'Измерено по документу'],
  ['user_confirmed', 'Подтверждено пользователем'],
  ['estimated', 'Оценка по допущению'],
])('renders %s source as %s', async (source, label) => {
  renderBuildingModel({ quantitySource: source });
  expect(await screen.findByText(label)).toBeInTheDocument();
});
```

- [ ] **Step 2: Запустить test**

Run: `npx vitest run src/features/estimate-generation/steps/BuildingModelStep.test.tsx`

Expected: FAIL.

- [ ] **Step 3: Реализовать hierarchy и virtualized list при существующей dependency**

Если virtualizer уже установлен, проверить актуальный API через Context7. Если нет — использовать paginated/expandable MUI lists; не добавлять dependency только ради малых наборов.

- [ ] **Step 4: Реализовать EvidenceDrawer**

Показывать document, page, cropped signed preview, source value, formula, transformation, confidence и producer version. Не показывать внутренний prompt.

- [ ] **Step 5: Запустить tests и commit**

```bash
npx vitest run src/features/estimate-generation/steps/BuildingModelStep.test.tsx
npx tsc --noEmit
git add src/features/estimate-generation/building src/features/estimate-generation/steps/BuildingModelStep.tsx src/features/estimate-generation/steps/BuildingModelStep.test.tsx
git commit -m "feat[lk]: показаны объемы и источники AI-сметы"
```

Expected: PASS.

### Task 7: Реализовать черновик и review cockpit

**Files:**
- Create: `../prohelper_admin/src/features/estimate-generation/steps/DraftStep.tsx`
- Create: `../prohelper_admin/src/features/estimate-generation/steps/ReviewStep.tsx`
- Create: `../prohelper_admin/src/features/estimate-generation/draft/PackageRegistry.tsx`
- Create: `../prohelper_admin/src/features/estimate-generation/draft/WorkItemTable.tsx`
- Create: `../prohelper_admin/src/features/estimate-generation/review/ReviewCockpit.tsx`
- Create: `../prohelper_admin/src/features/estimate-generation/review/NormativeCandidates.tsx`
- Create: `../prohelper_admin/src/features/estimate-generation/review/ReviewDecisionForm.tsx`
- Create: `../prohelper_admin/src/features/estimate-generation/steps/ReviewStep.test.tsx`

**Interfaces:**
- Consumes: paginated packages/work items/review queue and decision endpoints.
- Produces: one-at-a-time blocking review flow, candidate selection and updated snapshot.

- [ ] **Step 1: Написать manual normative search regression test**

```tsx
it('shows manual search candidates when initial candidates are empty', async () => {
  server.use(reviewItemHandler({ candidates: [] }), normativeSearchHandler({ candidates: [candidateFixture] }));
  renderReviewStep();

  await userEvent.type(await screen.findByLabelText('Поиск нормы'), 'Отделка помещений м2');
  await userEvent.click(screen.getByRole('button', { name: 'Найти' }));

  expect(await screen.findByText(candidateFixture.code)).toBeInTheDocument();
  expect(screen.getByRole('button', { name: `Выбрать ${candidateFixture.code}` })).toBeEnabled();
});
```

- [ ] **Step 2: Написать blocking progression test**

После сохранения решения текущий item исчезает, count уменьшается, открывается следующий blocking item. Frontend не уменьшает count локально: принимает response/snapshot.

- [ ] **Step 3: Запустить tests**

Run: `npx vitest run src/features/estimate-generation/steps/ReviewStep.test.tsx`

Expected: FAIL.

- [ ] **Step 4: Реализовать package registry**

Server pagination, поиск и filters. Summary: positions, priced, evidenced, needs review, total. Не загружать все 5 000 items в одну response.

- [ ] **Step 5: Реализовать cockpit**

Зоны: queue list, current work item/evidence, top candidates, decision form, previous/next. Candidate показывает code, name, unit, retrieval/rerank scores, match/mismatch reasons, dataset version и price availability.

- [ ] **Step 6: Запустить tests и commit**

```bash
npx vitest run src/features/estimate-generation/steps/ReviewStep.test.tsx
npx tsc --noEmit
git add src/features/estimate-generation/draft src/features/estimate-generation/review src/features/estimate-generation/steps/DraftStep.tsx src/features/estimate-generation/steps/ReviewStep.tsx src/features/estimate-generation/steps/ReviewStep.test.tsx
git commit -m "feat[lk]: добавлена проверка решений AI-сметчика"
```

Expected: PASS.

### Task 8: Реализовать итог, применение и историю

**Files:**
- Create: `../prohelper_admin/src/features/estimate-generation/steps/SummaryStep.tsx`
- Create: `../prohelper_admin/src/features/estimate-generation/shared/ReadinessPanel.tsx`
- Create: `../prohelper_admin/src/features/estimate-generation/shared/AvailableActionButton.tsx`
- Create: `../prohelper_admin/src/features/estimate-generation/shared/SessionHistoryDrawer.tsx`
- Create: `../prohelper_admin/src/features/estimate-generation/shared/ProcessingDialog.tsx`
- Create: `../prohelper_admin/src/features/estimate-generation/steps/SummaryStep.test.tsx`

**Interfaces:**
- Consumes: readiness, available apply/export/archive actions and applied estimate ID.
- Produces: safe final confirmation and navigation to newly created ordinary estimate.

- [ ] **Step 1: Написать apply safety test**

```tsx
it('applies only through backend action and reuses applied estimate id', async () => {
  server.use(snapshotHandler({ status: 'ready_to_apply', available_actions: [applyAction] }), applyHandler({ estimate_id: 501, created: true }));
  renderSummaryStep();

  await userEvent.click(await screen.findByRole('button', { name: 'Создать смету' }));
  await userEvent.click(screen.getByRole('button', { name: 'Подтвердить создание' }));

  expect(await screen.findByRole('link', { name: 'Открыть смету' })).toHaveAttribute('href', '/projects/1/estimates/501');
});
```

- [ ] **Step 2: Запустить test**

Run: `npx vitest run src/features/estimate-generation/steps/SummaryStep.test.tsx`

Expected: FAIL.

- [ ] **Step 3: Реализовать readiness panel**

Группировать blocking issues и warnings по documents/geometry/quantities/normatives/prices. Каждый issue имеет action link на соответствующий step/item. Apply button рендерится только если backend вернул action `apply`.

- [ ] **Step 4: Реализовать processing dialog и history**

Dialog можно закрыть; job продолжается. History paginated; status и next action читаются из snapshot. Applied session показывает link, но не дает edit/re-generate.

- [ ] **Step 5: Запустить tests и commit**

```bash
npx vitest run src/features/estimate-generation/steps/SummaryStep.test.tsx
npx tsc --noEmit
git add src/features/estimate-generation/steps/SummaryStep.tsx src/features/estimate-generation/shared
git commit -m "feat[lk]: завершен сценарий AI-сметы"
```

Expected: PASS.

### Task 9: Удалить frontend monolith и legacy contracts

**Files:**
- Delete: `../prohelper_admin/src/pages/Estimates/EstimateGenerationWorkspacePage.tsx`
- Delete: `../prohelper_admin/src/pages/Estimates/estimateGenerationPresentation.ts`
- Delete: `../prohelper_admin/src/pages/Estimates/estimateGenerationPresentation.test.ts`
- Delete: `../prohelper_admin/src/services/estimateGenerationService.ts`
- Delete: `../prohelper_admin/src/services/estimateGenerationWorkflowService.test.ts`
- Delete: `../prohelper_admin/src/types/estimateGeneration.ts`
- Modify: `../prohelper_admin/src/App.tsx`
- Create: `../prohelper_admin/src/features/estimate-generation/estimateGenerationArchitecture.test.ts`

**Interfaces:**
- Consumes: migrated feature contracts and routes.
- Produces: one frontend implementation with no legacy imports.

- [ ] **Step 1: Написать architecture test**

```ts
it('does not import legacy estimate generation modules', () => {
  const sourceFiles = readFeatureSourceFiles();
  expect(sourceFiles.join('\n')).not.toMatch(/pages\/Estimates\/EstimateGenerationWorkspacePage/);
  expect(sourceFiles.join('\n')).not.toMatch(/services\/estimateGenerationService/);
  expect(sourceFiles.join('\n')).not.toMatch(/types\/estimateGeneration/);
});
```

- [ ] **Step 2: Найти callers**

Run: `rg -n "EstimateGenerationWorkspacePage|estimateGenerationPresentation|estimateGenerationService|types/estimateGeneration" src`

Expected: только legacy files и уже мигрируемые imports.

- [ ] **Step 3: Перевести imports и удалить files**

Удалить старые modules после переноса всех tests. Не создавать re-export aliases.

- [ ] **Step 4: Выполнить frontend gate**

```bash
npx vitest run src/features/estimate-generation src/components/layout/SidebarMenu.test.tsx
npx tsc --noEmit
rg -n "EstimateGenerationWorkspacePage|estimateGenerationPresentation|estimateGenerationService|types/estimateGeneration" src
```

Expected: Vitest PASS, TypeScript `0 errors`, `rg` exit 1.

- [ ] **Step 5: Commit**

```bash
git add -A src/features/estimate-generation src/pages/Estimates src/services src/types src/App.tsx
git commit -m "refactor[lk]: удален старый интерфейс AI-сметчика"
```

### Task 10: Ввести Filament permissions и navigation group

**Files:**
- Modify: `config/RoleDefinitions/system_admin/super_admin.json`
- Modify: `config/RoleDefinitions/system_admin/support_operator.json`
- Modify: `config/RoleDefinitions/system_admin/qa_engineer.json`
- Modify: `config/RoleDefinitions/system_admin/security_auditor.json`
- Modify: `lang/ru/permissions.php`
- Modify: `app/Filament/Support/NavigationGroups.php`
- Create: `tests/Feature/Filament/EstimateGeneration/EstimateGenerationFilamentAuthorizationTest.php`

**Interfaces:**
- Consumes: system-admin role definitions.
- Produces: explicit view/operate/datasets/benchmarks/settings/budgets permissions.

- [ ] **Step 1: Написать authorization matrix test**

```php
public static function accessMatrix(): array
{
    return [
        'support can view sessions' => ['support_operator', 'estimate_generation.monitor', true],
        'support cannot change settings' => ['support_operator', 'estimate_generation.settings', false],
        'qa can run benchmark' => ['qa_engineer', 'estimate_generation.benchmarks', true],
        'auditor cannot retry jobs' => ['security_auditor', 'estimate_generation.operate', false],
        'super admin can manage budgets' => ['super_admin', 'estimate_generation.budgets', true],
    ];
}
```

- [ ] **Step 2: Запустить test**

Run: `php artisan test tests/Feature/Filament/EstimateGeneration/EstimateGenerationFilamentAuthorizationTest.php`

Expected: FAIL.

- [ ] **Step 3: Добавить permissions и labels**

Permissions: `estimate_generation.monitor`, `estimate_generation.operate`, `estimate_generation.datasets`, `estimate_generation.benchmarks`, `estimate_generation.settings`, `estimate_generation.budgets`. Обновить русские названия и resource authorization methods.

- [ ] **Step 4: Создать одну navigation group**

`NavigationGroups::aiEstimator()` возвращает «AI-сметчик». Все новые pages/resources используют эту группу и уникальный sort; не дублировать icons на group/resource.

- [ ] **Step 5: Запустить tests и commit**

```bash
php artisan test tests/Feature/Filament/EstimateGeneration/EstimateGenerationFilamentAuthorizationTest.php tests/Feature/Filament/SystemAdminNavigationTest.php tests/Feature/Filament/SystemAdminResourceAuthorizationTest.php
git add config/RoleDefinitions/system_admin lang/ru/permissions.php app/Filament/Support/NavigationGroups.php tests/Feature/Filament/EstimateGeneration
git commit -m "feat[lk]: добавлены права управления AI-сметчиком"
```

Expected: PASS.

### Task 11: Создать Filament dashboard и session cockpit

**Files:**
- Create: `app/Filament/Pages/EstimateGeneration/EstimateGenerationDashboard.php`
- Create: `app/Filament/Widgets/EstimateGeneration/SessionStatsWidget.php`
- Create: `app/Filament/Widgets/EstimateGeneration/CostTrendWidget.php`
- Create: `app/Filament/Widgets/EstimateGeneration/QueueHealthWidget.php`
- Create: `app/Filament/Resources/EstimateGeneration/SessionResource.php`
- Create: `app/Filament/Resources/EstimateGeneration/SessionResource/Pages/ListSessions.php`
- Create: `app/Filament/Resources/EstimateGeneration/SessionResource/Pages/ViewSession.php`
- Create: `app/Filament/Resources/EstimateGeneration/SessionResource/RelationManagers/DocumentsRelationManager.php`
- Create: `app/Filament/Resources/EstimateGeneration/SessionResource/RelationManagers/CheckpointsRelationManager.php`
- Create: `app/Filament/Resources/EstimateGeneration/SessionResource/RelationManagers/AuditEventsRelationManager.php`
- Create: `tests/Feature/Filament/EstimateGeneration/EstimateGenerationDashboardTest.php`
- Create: `tests/Feature/Filament/EstimateGeneration/EstimateGenerationSessionResourceTest.php`

**Interfaces:**
- Consumes: sessions, checkpoints, failures, usage and audit models.
- Produces: read-optimized dashboard and safe retry/cancel/archive actions.

- [ ] **Step 1: Написать dashboard aggregation test**

```php
#[Test]
public function dashboard_aggregates_status_cost_and_queue_health_without_document_content(): void
{
    $this->seedOperationalMetrics();

    $response = $this->actingAs($this->superAdmin())->get(EstimateGenerationDashboard::getUrl());

    $response->assertOk();
    $response->assertSee('Стоимость успешной сметы');
    $response->assertSee('Зависшие задания');
    $response->assertDontSee('секретный текст документа');
}
```

- [ ] **Step 2: Написать safe operation test**

Retry разрешен только failed/recoverable stage, cancel — только active session, archive — terminal session. Любая action повторно проверяет permission и состояние в application service, не обновляет модель из Filament closure напрямую.

- [ ] **Step 3: Запустить tests**

Run: `php artisan test tests/Feature/Filament/EstimateGeneration/EstimateGenerationDashboardTest.php tests/Feature/Filament/EstimateGeneration/EstimateGenerationSessionResourceTest.php`

Expected: FAIL.

- [ ] **Step 4: Реализовать widgets**

Filters: period, organization, project, provider, model, stage, status, document type, mode. Aggregates: sessions/statuses, apply rate, avg/p95 duration, queue age, documents, review rate, total cost, cost per successful/applied session. Queries используют indexes Plan 2 и не загружают model payload/document text.

- [ ] **Step 5: Реализовать session timeline**

View показывает status transitions, processing units, checkpoints, usage, failures, audit and applied estimate ID как read-only link. Не добавлять delete/edit обычной сметы.

- [ ] **Step 6: Запустить tests и commit**

```bash
php artisan test tests/Feature/Filament/EstimateGeneration/EstimateGenerationDashboardTest.php tests/Feature/Filament/EstimateGeneration/EstimateGenerationSessionResourceTest.php
git add app/Filament/Pages/EstimateGeneration app/Filament/Widgets/EstimateGeneration app/Filament/Resources/EstimateGeneration/SessionResource.php app/Filament/Resources/EstimateGeneration/SessionResource tests/Feature/Filament/EstimateGeneration
git commit -m "feat[lk]: добавлен центр сессий AI-сметчика"
```

Expected: PASS.

### Task 12: Создать Filament usage, errors и queue resources

**Files:**
- Create: `app/Filament/Resources/EstimateGeneration/UsageResource.php`
- Create: `app/Filament/Resources/EstimateGeneration/UsageResource/Pages/ListUsage.php`
- Create: `app/Filament/Resources/EstimateGeneration/FailureResource.php`
- Create: `app/Filament/Resources/EstimateGeneration/FailureResource/Pages/ListFailures.php`
- Create: `app/Filament/Resources/EstimateGeneration/FailureResource/Pages/ViewFailure.php`
- Create: `app/Filament/Resources/EstimateGeneration/PipelineCheckpointResource.php`
- Create: `app/Filament/Resources/EstimateGeneration/PipelineCheckpointResource/Pages/ListPipelineCheckpoints.php`
- Create: `tests/Feature/Filament/EstimateGeneration/EstimateGenerationUsageResourceTest.php`
- Create: `tests/Feature/Filament/EstimateGeneration/EstimateGenerationFailureResourceTest.php`

**Interfaces:**
- Consumes: usage/failure/checkpoint tables Plan 2.
- Produces: filterable cost and failure diagnostics without sensitive fields.

- [ ] **Step 1: Написать cost columns test**

Resource обязан показывать provider/model/stage/input/cached/output/reasoning tokens, images/pages, duration, attempt, status, snapshot cost/currency and session relation. Test проверяет filters по period/organization/model/stage/status.

- [ ] **Step 2: Написать sensitive data test**

```php
#[Test]
public function failure_resource_never_renders_sensitive_keys(): void
{
    $failure = $this->failure(['safe_context' => [
        'Authorization' => '[REDACTED]',
        'api_key' => '[REDACTED]',
        'provider_code' => 'timeout',
    ]]);

    $response = $this->actingAs($this->superAdmin())->get(FailureResource::getUrl('view', ['record' => $failure]));
    $response->assertOk()->assertDontSee('Authorization')->assertDontSee('api_key')->assertSee('timeout');
}
```

- [ ] **Step 3: Запустить tests**

Run: `php artisan test tests/Feature/Filament/EstimateGeneration/EstimateGenerationUsageResourceTest.php tests/Feature/Filament/EstimateGeneration/EstimateGenerationFailureResourceTest.php`

Expected: FAIL.

- [ ] **Step 4: Реализовать resources**

Usage read-only. Failure read-only кроме explicit mark-resolved при permission и фактическом отсутствии active occurrence. Checkpoint actions вызывают application retry/cancel services; resource query не выполняет queue mutation.

- [ ] **Step 5: Запустить tests и commit**

```bash
php artisan test tests/Feature/Filament/EstimateGeneration/EstimateGenerationUsageResourceTest.php tests/Feature/Filament/EstimateGeneration/EstimateGenerationFailureResourceTest.php
git add app/Filament/Resources/EstimateGeneration/UsageResource.php app/Filament/Resources/EstimateGeneration/UsageResource app/Filament/Resources/EstimateGeneration/FailureResource.php app/Filament/Resources/EstimateGeneration/FailureResource app/Filament/Resources/EstimateGeneration/PipelineCheckpointResource.php app/Filament/Resources/EstimateGeneration/PipelineCheckpointResource tests/Feature/Filament/EstimateGeneration
git commit -m "feat[lk]: добавлен мониторинг затрат AI-сметчика"
```

Expected: PASS.

### Task 13: Расширить Filament datasets, benchmark, settings и budgets

**Files:**
- Move/replace: `app/Filament/Resources/EstimateGenerationTrainingDatasetResource.php`
- Move/replace pages: `app/Filament/Resources/EstimateGenerationTrainingDatasetResource/Pages/*`
- Create: `app/Filament/Resources/EstimateGeneration/TrainingDatasetResource.php`
- Create: `app/Filament/Resources/EstimateGeneration/TrainingDatasetResource/Pages/*`
- Create: `app/Filament/Resources/EstimateGeneration/BenchmarkRunResource.php`
- Create: `app/Filament/Resources/EstimateGeneration/BenchmarkRunResource/Pages/ListBenchmarkRuns.php`
- Create: `app/Filament/Resources/EstimateGeneration/BenchmarkRunResource/Pages/ViewBenchmarkRun.php`
- Create: `app/Filament/Pages/EstimateGeneration/EstimateGenerationSettings.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Settings/EstimateGenerationSettingsService.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/Settings/EstimateGenerationSettingsData.php`
- Create: `app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_002000_create_estimate_generation_settings_and_budgets.php`
- Create: `tests/Feature/Filament/EstimateGeneration/EstimateGenerationTrainingResourceTest.php`
- Create: `tests/Feature/Filament/EstimateGeneration/EstimateGenerationBenchmarkResourceTest.php`
- Create: `tests/Feature/Filament/EstimateGeneration/EstimateGenerationSettingsTest.php`

**Interfaces:**
- Consumes: versioned datasets/benchmark runs Plan 3 and configuration defaults.
- Produces: controlled operational settings, model selection and budgets for new operations.

- [ ] **Step 1: Написать dataset isolation test**

Acceptance dataset UI запрещает actions `Use for training` и `Tune rules`, но разрешает benchmark. Development dataset требует trusted review before approved.

- [ ] **Step 2: Написать settings audit test**

```php
#[Test]
public function settings_change_is_audited_and_does_not_expose_secrets(): void
{
    $response = $this->actingAs($this->superAdmin())->post(EstimateGenerationSettings::getUrl(), [
        'vision_model' => 'provider/model-v2',
        'monthly_budget' => '1000.00',
        'currency' => 'USD',
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('estimate_generation_setting_audits', [
        'key' => 'vision_model',
        'old_value' => json_encode('provider/model-v1'),
        'new_value' => json_encode('provider/model-v2'),
    ]);
    $response->assertDontSee('api_key');
}
```

- [ ] **Step 3: Запустить tests**

Run: `php artisan test tests/Feature/Filament/EstimateGeneration/EstimateGenerationTrainingResourceTest.php tests/Feature/Filament/EstimateGeneration/EstimateGenerationBenchmarkResourceTest.php tests/Feature/Filament/EstimateGeneration/EstimateGenerationSettingsTest.php`

Expected: FAIL.

- [ ] **Step 4: Перенести training resource без legacy alias**

Сохранить полезные формы загрузки, добавить type/version/status/review, examples, files, metrics. После смены namespace удалить старый resource и pages; navigation содержит только новый.

- [ ] **Step 5: Реализовать benchmark resource**

List сравнивает pipeline/model/normative/price versions, metrics, cost, duration, status. View показывает case-level failures и delta против current production version. Run action dispatch-ит benchmark job только для development/regression; acceptance run требует explicit confirmation и QA permission.

- [ ] **Step 6: Реализовать settings/budgets**

Настройки: models, timeout и retry только для AI-стадий vision/classification/normative matching; file/page limits; реально используемые classification/geometry/normative-matching confidence thresholds; enabled formats; low-confidence manual review; organization/global daily/monthly budgets. Детерминированные planning/pricing не имеют декоративных AI-controls. Secrets отсутствуют в schema. Settings snapshot применяется только к новым processing units/calls.

- [ ] **Step 7: Запустить tests и commit**

```bash
php -l app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_002000_create_estimate_generation_settings_and_budgets.php
php artisan test tests/Feature/Filament/EstimateGeneration
git add -A app/Filament/Resources/EstimateGenerationTrainingDatasetResource.php app/Filament/Resources/EstimateGenerationTrainingDatasetResource app/Filament/Resources/EstimateGeneration app/Filament/Pages/EstimateGeneration app/BusinessModules/Addons/EstimateGeneration/Settings app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_002000_create_estimate_generation_settings_and_budgets.php tests/Feature/Filament/EstimateGeneration
git commit -m "feat[lk]: расширено управление AI-сметчиком"
```

Expected: PASS.

### Task 14: Финальная browser/Filament приемка и документация

**Files:**
- Update: `docs/workflows/ai-estimator.md`
- Update: `docs/workflows/ai-estimator-roles-and-statuses.md`
- Create: `docs/workflows/ai-estimator-admin-ux.md`
- Create: `docs/runbooks/ai-estimator-operations.md`
- Create: `docs/runbooks/ai-estimator-cost-and-errors.md`
- Modify: `tests/Feature/Filament/SystemAdminNavigationTest.php`
- Modify: `tests/Feature/Filament/SystemAdminResourceAuthorizationTest.php`

**Interfaces:**
- Consumes: полный user и system-admin workflow.
- Produces: проверенный UX, runbooks и отсутствие legacy UI/Filament resources.

- [ ] **Step 1: Выполнить полный frontend gate**

```bash
cd ../prohelper_admin
npx vitest run src/features/estimate-generation src/components/layout/SidebarMenu.test.tsx
npx tsc --noEmit
```

Expected: `0 failed`, TypeScript `0 errors`.

- [ ] **Step 2: Выполнить полный Filament gate**

```bash
cd ../prohelper
php artisan test tests/Feature/Filament/EstimateGeneration tests/Feature/Filament/SystemAdminNavigationTest.php tests/Feature/Filament/SystemAdminResourceAuthorizationTest.php
vendor/bin/phpstan analyse app/Filament/Resources/EstimateGeneration app/Filament/Pages/EstimateGeneration app/Filament/Widgets/EstimateGeneration app/BusinessModules/Addons/EstimateGeneration/Settings --memory-limit=1G
```

Expected: `0 failures`, `No errors`.

- [ ] **Step 3: Выполнить gstack user smoke**

Без запуска запрещенных серверов открыть доступный URL и проверить:

1. создание сессии;
2. PNG/JPEG sketch upload;
3. PDF upload;
4. progress и закрытие страницы;
5. scale confirmation;
6. geometry correction;
7. quantities/evidence;
8. normative review;
9. readiness;
10. apply и переход в новую обычную смету;
11. повторный apply не создает дубль.

Expected: console без errors; network без неожиданных 4xx/5xx; каждый step показывает loading/error/empty/ready state.

- [ ] **Step 4: Выполнить gstack Filament smoke**

Проверить dashboard, filters, session timeline, usage/cost, failures, queues, dataset review, benchmark comparison, settings audit и permission-denied роли.

Expected: чувствительные поля не отображаются; опасные actions требуют permission/confirmation; ordinary estimate mutations отсутствуют.

- [ ] **Step 5: Обновить workflow и runbooks**

Документировать пользовательский сценарий, роли, статусы, Filament daily operations, cost alerts, retry/cancel, stuck jobs, provider outage, benchmark regression и escalation. Не описывать незапущенное поведение как действующее.

- [ ] **Step 6: Проверить отсутствие legacy UI/resources**

```bash
rg -n "EstimateGenerationWorkspacePage|estimateGenerationPresentation|estimateGenerationService|EstimateGenerationTrainingDatasetResource" ../prohelper_admin/src app/Filament
```

Expected: exit code 1.

- [ ] **Step 7: Commit backend docs/tests**

```bash
git add docs/workflows docs/runbooks tests/Feature/Filament
git commit -m "docs[lk]: описана эксплуатация AI-сметчика"
```
