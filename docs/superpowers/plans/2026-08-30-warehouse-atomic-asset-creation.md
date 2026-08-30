# Warehouse Atomic Asset Creation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Сделать создание складского актива с первоначальными экземплярами атомарным и устранить связанные дефекты понятности формы.

**Architecture:** Существующий endpoint создания принимает необязательные экземпляры, а новый application service выполняет создание карточки и экземпляров в одной транзакции. Админка отправляет один запрос; фотографии загружает после подтверждённого создания с отдельным состоянием частичного успеха.

**Tech Stack:** Laravel 11, PHP 8.2, PostgreSQL 16, React/Vite, TypeScript, Vitest.

**Spec:** `docs/superpowers/specs/2026-08-30-warehouse-atomic-asset-creation-design.md`

## Global Constraints

- Не выполнять миграции и не менять схему БД.
- DB-тесты запускать только через `tests/Runtime/run-postgres-tests.ps1`.
- Не запускать сборку админки.
- Пользовательские PHP-тексты получать через `trans_message(...)`.
- Не использовать ИИ-смету.

---

### Task 1: Серверный контракт и атомарный workflow

**Files:**
- Create: `app/BusinessModules/Features/BasicWarehouse/Services/AssetCreationService.php`
- Modify: `app/BusinessModules/Features/BasicWarehouse/Http/Requests/StoreAssetRequest.php`
- Modify: `app/BusinessModules/Features/BasicWarehouse/Controllers/AssetController.php`
- Modify: `lang/ru/validation.php`
- Modify: `tests/Feature/Api/V1/Admin/WarehouseSerializedAssetTest.php`

**Interfaces:**
- Consumes: `AssetService::createAsset(int, array): Asset`, `SerializedAssetReceiptService::receive(int, int, int, int, array): Collection`.
- Produces: `AssetCreationService::create(int $organizationId, int $actorId, array $data): Asset`.

- [ ] **Step 1: Написать падающие feature-тесты**

Добавить сценарии одного `POST /assets` с `instances`, rollback при конфликте уже существующего инвентарного номера и проверки условной валидации/русских подписей.

- [ ] **Step 2: Подтвердить RED**

Run: `powershell -ExecutionPolicy Bypass -File tests/Runtime/run-postgres-tests.ps1 -Filter WarehouseSerializedAssetTest`

Expected: новые проверки падают, потому что `instances` ещё не создаются в `store` и технические имена полей попадают в ошибки.

- [ ] **Step 3: Реализовать минимальный серверный контракт**

Добавить условные правила `instances`, `instances.*` и обязательный склад, русские имена атрибутов, затем сервис:

```php
public function create(int $organizationId, int $actorId, array $data): Asset
{
    return DB::transaction(function () use ($organizationId, $actorId, $data): Asset {
        $instances = $data['instances'] ?? [];
        unset($data['instances']);
        $asset = $this->assets->createAsset($organizationId, $data);

        if ($instances !== []) {
            $this->serializedAssets->receive(
                $organizationId,
                (int) $asset->id,
                (int) $data['warehouse_id'],
                $actorId,
                $instances,
            );
        }

        return $asset;
    });
}
```

Контроллер вызывает сервис и не добавляет текст исключения в ответ 500.

- [ ] **Step 4: Подтвердить GREEN и статический анализ**

Run: `powershell -ExecutionPolicy Bypass -File tests/Runtime/run-postgres-tests.ps1 -Filter WarehouseSerializedAssetTest`

Run: `vendor/bin/phpstan analyse app/BusinessModules/Features/BasicWarehouse/Services/AssetCreationService.php app/BusinessModules/Features/BasicWarehouse/Http/Requests/StoreAssetRequest.php app/BusinessModules/Features/BasicWarehouse/Controllers/AssetController.php --memory-limit=1G`

- [ ] **Step 5: Зафиксировать backend**

Commit: `fix[admin]: сделать создание складского актива атомарным`

### Task 2: Атомарный клиентский запрос и UX формы

**Files:**
- Modify: `prohelper_admin/src/types/warehouse.ts`
- Modify: `prohelper_admin/src/services/assetService.ts`
- Modify: `prohelper_admin/src/pages/Warehouse/components/CreateAssetDialog.tsx`
- Modify: `prohelper_admin/src/pages/Warehouse/components/SerializedAssetFields.tsx`
- Modify: `prohelper_admin/src/pages/Warehouse/components/CreateAssetDialog.test.tsx`
- Test: `prohelper_admin/src/services/assetService.test.ts`

**Interfaces:**
- Consumes: `POST /assets` с необязательным `instances` и `warehouse_id`.
- Produces: `StoreAssetRequest.instances?: SerializedAssetInstanceInput[]`; `AssetService.createAsset` создаёт запись, а сбой фотографий возвращает различимый частичный результат или пробрасывается в форму после сохранения созданного `asset.id`.

- [ ] **Step 1: Написать падающие Vitest-тесты**

Проверить один запрос создания сериализованного актива, отсутствие вызова `createAssetInstances`, отдельное сообщение при сбое фото, `maxLength` полей, лимит и доступное имя удаления фото.

- [ ] **Step 2: Подтвердить RED**

Run: `npx vitest run src/pages/Warehouse/components/CreateAssetDialog.test.tsx src/services/assetService.test.ts`

Expected: тесты падают на двухшаговом создании и неразличимом сбое фотографий.

- [ ] **Step 3: Реализовать минимальное клиентское поведение**

Передавать `instances` внутри `StoreAssetRequest`, убрать второй вызов при первоначальном создании, разделить DB-create и photo-upload, показывать понятный частичный успех, закрывать и обновлять форму после созданной карточки. Удалить устаревшее поле, добавить ограничения длины, подсказку лимита и `aria-label`.

- [ ] **Step 4: Подтвердить GREEN, типы и стиль**

Run: `npx vitest run src/pages/Warehouse/components/CreateAssetDialog.test.tsx src/services/assetService.test.ts`

Run: `npx tsc --noEmit`

Run: `npx eslint src/pages/Warehouse/components/CreateAssetDialog.tsx src/pages/Warehouse/components/SerializedAssetFields.tsx src/pages/Warehouse/components/CreateAssetDialog.test.tsx src/services/assetService.ts src/services/assetService.test.ts src/types/warehouse.ts`

- [ ] **Step 5: Зафиксировать админку**

Commit: `fix[admin]: завершать создание актива без частичных данных`

### Task 3: Релиз и production-проверка

**Files:**
- No product file changes expected.

**Interfaces:**
- Consumes: два проверенных commit-а backend/admin.
- Produces: штатные PR, успешные CI/deploy и production evidence.

- [ ] **Step 1: Выполнить независимое ревью итоговых diff**

Проверить транзакционность, tenant scope, безопасные ошибки, обратную совместимость и ветки частичного успеха фото.

- [ ] **Step 2: Создать PR и дождаться CI**

Сначала backend, затем админка после успешного backend deployment.

- [ ] **Step 3: Выполнить штатный merge/deploy**

Не применять ручные изменения на сервере и не добавлять инфраструктуру.

- [ ] **Step 4: Проверить production во встроенном браузере**

Создать уникальный поэкземплярный актив, проверить его экземпляры, понятные ошибки, сброс формы и консоль; затем проверить количественный актив. Не использовать ИИ-смету.
