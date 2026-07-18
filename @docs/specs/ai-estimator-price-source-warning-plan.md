# Источник цены в AI-сметчике — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Показать для каждой нормы фактически применённые региональные или базовые цены ФСБЦ без клиентских догадок и пересчётов.

**Architecture:** Backend формирует типизированную `price_details` из закреплённых цен ресурсов и отдаёт её вместе с кандидатом нормы. Admin нормализует опциональное поле и отображает сводку с раскрываемым списком; старые сессии без поля остаются совместимыми.

**Tech Stack:** PHP 8.2, Laravel 11, React, TypeScript, MUI, Vitest, MSW.

## Global Constraints

- Региональная цена имеет приоритет над базовой ценой ФСБЦ.
- Источник и сумма определяются только backend по закреплённому `resource_price_id`.
- Валидная базовая цена ФСБЦ создаёт warning, но не обязательный блокер.
- Полное отсутствие доказуемой цены остаётся обязательным блокером.
- Не запускать frontend build и миграции локально.

---

### Task 1: Backend-контракт фактически использованных цен

**Files:**
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/EloquentNormativeContextPinSource.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/NormativeResourceRowData.php`
- Modify: `app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/NormativeCandidatePresenter.php`
- Test: `tests/Unit/EstimateGeneration/Normatives/NormativeContextPinResolverTest.php`
- Test: `tests/Unit/EstimateGeneration/Normatives/NormativeCandidatePresenterTest.php`

**Interfaces:**
- Consumes: закреплённые `price_id`, `regional_price_version_id`, `dataset_version_id`, `resource_code`, `resource_name`, `unit`, `unit_price`.
- Produces: `price_details: list<{resource_code:string, resource_name:string, unit:string|null, unit_price:float, source:'regional'|'fsbc_base', dataset_version:string|null, regional_fallback:bool}>` и `fsbc_fallback_resources_count:int`.

- [ ] **Step 1: Write the failing presenter test**

Создать кандидата с одним региональным и одним базовым ресурсом и проверить точные `source`, `unit_price`, `dataset_version`, `regional_fallback` и счётчик замен.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Unit/EstimateGeneration/Normatives/NormativeCandidatePresenterTest.php`

Expected: FAIL — поля `price_details` и `fsbc_fallback_resources_count` отсутствуют.

- [ ] **Step 3: Implement the backend projection**

Добавить к ресурсу закреплённые идентификаторы каталога и сформировать в presenter только человекочитаемые поля:

```php
'source' => $resource['regional_price_version_id'] !== null ? 'regional' : 'fsbc_base',
'regional_fallback' => $resource['regional_price_version_id'] === null,
```

Не включать внутренние хэши и служебные диагностические поля.

- [ ] **Step 4: Run backend tests and static analysis**

Run:

```text
php artisan test --compact tests/Unit/EstimateGeneration/Normatives/NormativeCandidatePresenterTest.php tests/Unit/EstimateGeneration/Normatives/NormativeContextPinResolverTest.php
vendor/bin/phpstan analyse app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/EloquentNormativeContextPinSource.php app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/NormativeResourceRowData.php app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/NormativeCandidatePresenter.php --memory-limit=1G
```

Expected: PASS, PHPStan `[OK] No errors`.

- [ ] **Step 5: Commit backend contract**

Run: `git commit -m "feat[lk]: показать источники цен норм AI-сметы"`

### Task 2: Типизация и нормализация admin API

**Files:**
- Modify: `prohelper_admin/src/features/estimate-generation/api/estimateGenerationContracts.ts`
- Modify: `prohelper_admin/src/features/estimate-generation/api/estimateGenerationReviewNormalizers.ts`
- Test: `prohelper_admin/src/features/estimate-generation/api/estimateGenerationNormalizers.test.ts`

**Interfaces:**
- Consumes: опциональные backend-поля `price_details` и `fsbc_fallback_resources_count`.
- Produces: `NormativePriceDetailDto` и безопасный `NormativeCandidateDto.price_details` со значением `[]` для старых сессий.

- [ ] **Step 1: Write failing normalizer tests**

Проверить смешанный набор источников и payload без новых полей. Некорректный `source` должен выбрасывать `EstimateGenerationReviewContractError`.

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run src/features/estimate-generation/api/estimateGenerationNormalizers.test.ts`

Expected: FAIL — новые поля не типизированы и не нормализуются.

- [ ] **Step 3: Add exact TypeScript contract**

```ts
export interface NormativePriceDetailDto {
  resource_code: string;
  resource_name: string;
  unit: string | null;
  unit_price: number;
  source: 'regional' | 'fsbc_base';
  dataset_version: string | null;
  regional_fallback: boolean;
}
```

Нормализатор принимает отсутствие поля как `[]`, но строго валидирует каждый присутствующий элемент.

- [ ] **Step 4: Run Vitest and typecheck**

Run:

```text
npx vitest run src/features/estimate-generation/api/estimateGenerationNormalizers.test.ts
npx tsc --noEmit
```

Expected: PASS без ошибок TypeScript.

- [ ] **Step 5: Commit API contract**

Run: `git commit -m "feat[lk]: типизировать источники цен AI-сметы"`

### Task 3: Карточка нормы и предупреждение ФСБЦ

**Files:**
- Modify: `prohelper_admin/src/features/estimate-generation/review/NormativeCandidates.tsx`
- Test: `prohelper_admin/src/features/estimate-generation/steps/ReviewStep.test.tsx`

**Interfaces:**
- Consumes: `NormativeCandidateDto.price_details` и `fsbc_fallback_resources_count`.
- Produces: видимую сводку источников и раскрываемый список фактически применённых цен.

- [ ] **Step 1: Write failing MSW-backed UI tests**

Проверить тексты «Региональные цены» и «Для 2 ресурсов применены базовые цены ФСБЦ», раскрытие списка, формат цены в рублях и отсутствие предупреждения для legacy payload.

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run src/features/estimate-generation/steps/ReviewStep.test.tsx`

Expected: FAIL — сведения об источнике цены не отображаются.

- [ ] **Step 3: Implement the MUI disclosure**

Использовать `Alert severity="warning"`, `Button` для раскрытия и компактный список строк `код · название · цена · источник`. Форматировать сумму через `Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB' })`.

- [ ] **Step 4: Verify frontend behavior**

Run:

```text
npx vitest run src/features/estimate-generation/steps/ReviewStep.test.tsx
npx tsc --noEmit
```

Expected: PASS; loading, empty и legacy состояния не ломаются.

- [ ] **Step 5: Commit UI**

Run: `git commit -m "feat[lk]: показать применённые цены в AI-сметчике"`

### Task 4: Production verification

**Files:** none.

**Interfaces:**
- Consumes: задеплоенные backend/admin commits и сессию №58 проекта 89.
- Produces: браузерное доказательство источников цен и готовой обычной сметы.

- [ ] **Step 1: Deploy exact commits and wait for successful workflows**

Проверить SHA обоих deploy workflow через `gh run watch`.

- [ ] **Step 2: Regenerate session №58 in the production browser**

Нажать «Сформировать заново» и дождаться 100%.

- [ ] **Step 3: Verify candidate and selected norm UI**

Проверить ненулевой объём, код нормы, цену, источник каждого ресурса и отсутствие офисно-складских работ.

- [ ] **Step 4: Apply the AI result**

Создать обычную смету только кнопкой применения AI-результата и проверить позиции и ненулевой итог в production UI.
