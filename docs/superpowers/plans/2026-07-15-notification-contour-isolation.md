# Notification Contour Isolation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Изолировать уведомления МОСТ между `admin`, `lk`, `mobile` и `customer` во всех API и WebSocket-потоках, сохранив возможность явной отправки в несколько контуров.

**Architecture:** `notifications` хранит логическое уведомление и персонального получателя, `notification_targets` хранит отдельное состояние каждого интерфейса. Доверенный серверный resolver выводит интерфейс из группы API-маршрутов; сервис создания требует явные цели, а WebSocket доставляет каждую цель независимо.

**Tech Stack:** PHP 8.2, Laravel 11, PostgreSQL, Reverb, React/Vite/TypeScript, Laravel Echo, PHPUnit, Vitest.

## Global Constraints

- Не запускать миграции локально или вручную на production.
- Новые PHP-файлы содержат `declare(strict_types=1);` и соответствуют PSR-12.
- Пользовательские PHP-сообщения проходят через `trans_message(...)`.
- Контур определяется сервером, параметр клиента не может его переопределить.
- `email` и `telegram` доставляются один раз на логическое уведомление.
- Сборки `prohelper_admin` и `prohelper_land` локально не запускать; использовать точечные ESLint/Prettier, `tsc --noEmit` и Vitest.
- Не изменять пользовательские неотслеживаемые `.cbmignore`, `.codebase-memory/` и `tmp/`.

---

### Task 1: Типизированные цели и схема хранения

**Files:**
- Create: `app/BusinessModules/Features/Notifications/Enums/NotificationInterface.php`
- Create: `app/BusinessModules/Features/Notifications/Models/NotificationTarget.php`
- Create: `app/BusinessModules/Features/Notifications/migrations/2026_07_15_000001_create_notification_targets_table.php`
- Modify: `app/BusinessModules/Features/Notifications/Models/Notification.php`
- Test: `tests/Unit/Notifications/NotificationTargetModelTest.php`
- Test: `tests/Unit/Notifications/NotificationTargetMigrationContractTest.php`

**Interfaces:**
- Produces: `NotificationInterface: string` enum with `Admin`, `Lk`, `Mobile`, `Customer`.
- Produces: `Notification::targets(): HasMany`, `Notification::scopeForInterface(Builder, NotificationInterface): Builder`.
- Produces: `NotificationTarget::markAsRead()`, `markAsUnread()`, `dismiss()`, `markWebSocketSent()`, `markWebSocketFailed(string)`.

- [ ] **Step 1: Write failing model and migration contract tests**

```php
self::assertSame(['admin', 'lk', 'mobile', 'customer'], array_column(NotificationInterface::cases(), 'value'));
self::assertStringContainsString("unique(['notification_id', 'interface'])", $migration);
self::assertStringContainsString("where('interface', $interface->value)", $model);
```

- [ ] **Step 2: Run tests and verify RED**

Run: `php artisan test tests/Unit/Notifications/NotificationTargetModelTest.php tests/Unit/Notifications/NotificationTargetMigrationContractTest.php`
Expected: FAIL because enum, model, relationship and migration do not exist.

- [ ] **Step 3: Implement enum, target model, relationship, scopes and idempotent batch backfill migration**

```php
enum NotificationInterface: string
{
    case Admin = 'admin';
    case Lk = 'lk';
    case Mobile = 'mobile';
    case Customer = 'customer';
}
```

The migration creates UUID targets, a cascading FK, per-interface state, a unique pair, indexes, and backfills known `data.interface`; missing values map to `admin` and unknown values are excluded from automatic publication.

- [ ] **Step 4: Run focused tests, PHP syntax and Pint**

Run: `php artisan test tests/Unit/Notifications/NotificationTargetModelTest.php tests/Unit/Notifications/NotificationTargetMigrationContractTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git commit -m "feat: добавлены цели контуров уведомлений"
```

### Task 2: Явный контракт создания уведомлений

**Files:**
- Create: `app/BusinessModules/Features/Notifications/DTOs/NotificationDeliveryOptions.php`
- Create: `app/BusinessModules/Features/Notifications/Services/NotificationTargetResolver.php`
- Modify: `app/BusinessModules/Features/Notifications/Services/NotificationPayloadNormalizer.php`
- Modify: `app/BusinessModules/Features/Notifications/Services/NotificationService.php`
- Modify: `app/BusinessModules/Features/Notifications/Models/Notification.php`
- Test: `tests/Unit/Notifications/NotificationTargetResolverTest.php`
- Test: `tests/Unit/Notifications/NotificationServiceTargetTest.php`

**Interfaces:**
- Produces: `NotificationDeliveryOptions::__construct(array $channels, array $interfaces, ?int $organizationId, array $requiredPermissions)`.
- Produces: `NotificationTargetResolver::resolve(array $interfaces, array $data): array<NotificationInterface>`.
- Changes: `NotificationService::send(..., string|array|null $requiredPermissions = null, string|array|null $interfaces = null): Notification`.

- [ ] **Step 1: Write failing tests for explicit, legacy, multiple, empty and unknown targets**

```php
self::assertSame(['admin', 'lk'], array_map(
    static fn (NotificationInterface $interface): string => $interface->value,
    $resolver->resolve(['admin', 'lk'], [])
));
$this->expectException(DomainException::class);
$resolver->resolve([], []);
```

- [ ] **Step 2: Run tests and verify RED**

Run: `php artisan test tests/Unit/Notifications/NotificationTargetResolverTest.php tests/Unit/Notifications/NotificationServiceTargetTest.php`
Expected: FAIL because targets are not validated or persisted.

- [ ] **Step 3: Implement transactional creation**

Create the notification and all target rows in `DB::transaction()`, persist normalized required permissions in `metadata.required_permissions`, dispatch only after commit, and remove the conflicting interface defaults from payload normalization and WebSocket delivery.

- [ ] **Step 4: Run tests and static analysis**

Run: `php artisan test tests/Unit/Notifications/NotificationTargetResolverTest.php tests/Unit/Notifications/NotificationServiceTargetTest.php tests/Unit/Notifications/NotificationAfterCommitDispatchTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git commit -m "refactor: введён явный контракт контуров уведомлений"
```

### Task 3: Независимая WebSocket-доставка целей

**Files:**
- Modify: `app/BusinessModules/Features/Notifications/Channels/WebSocketChannel.php`
- Modify: `app/BusinessModules/Features/Notifications/Jobs/SendNotificationJob.php`
- Modify: `app/BusinessModules/Features/Notifications/Services/NotificationService.php`
- Test: `tests/Unit/Notifications/WebSocketChannelTest.php`
- Test: `tests/Unit/Notifications/SendNotificationJobTest.php`

**Interfaces:**
- Consumes: `Notification::targets()` and target WebSocket state methods.
- Produces: one `notification.new` broadcast per pending supported target, with `interface` at the event root and in normalized `data` during compatibility.

- [ ] **Step 1: Add failing tests for two targets, partial failure and retry deduplication**

```php
self::assertSame([
    'private-App.Models.User.162.admin',
    'private-App.Models.User.162.lk',
], $broadcastChannels);
self::assertSame('sent', $adminTarget->fresh()->websocket_status);
self::assertSame('failed', $lkTarget->fresh()->websocket_status);
```

- [ ] **Step 2: Run tests and verify RED**

Run: `php artisan test tests/Unit/Notifications/WebSocketChannelTest.php tests/Unit/Notifications/SendNotificationJobTest.php`
Expected: FAIL because delivery reads one scalar `data.interface`.

- [ ] **Step 3: Implement per-target delivery and bounded retry**

Skip targets already marked `sent`; reject `customer` and `mobile` WebSocket targets with a domain error until their clients exist; keep external channels global and idempotent.

- [ ] **Step 4: Run focused tests and Larastan**

Run: `php artisan test tests/Unit/Notifications/WebSocketChannelTest.php tests/Unit/Notifications/WebSocketChannelLoggingTest.php tests/Unit/Notifications/SendNotificationJobTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git commit -m "fix: изолирована WebSocket-доставка уведомлений"
```

### Task 4: Серверная изоляция всех API-операций

**Files:**
- Create: `app/BusinessModules/Features/Notifications/Services/NotificationRequestInterfaceResolver.php`
- Create: `app/BusinessModules/Features/Notifications/Services/NotificationQueryService.php`
- Modify: `app/BusinessModules/Features/Notifications/Http/Controllers/NotificationController.php`
- Test: `tests/Feature/Api/V1/Notifications/NotificationContourIsolationTest.php`
- Test: `tests/Feature/Api/V1/Admin/AdminBaseExperienceControllerTest.php`

**Interfaces:**
- Produces: `NotificationRequestInterfaceResolver::resolve(Request): NotificationInterface`.
- Produces: `NotificationQueryService::visibleTo(Request): Builder` and `findVisible(Request, string): Notification`.

- [ ] **Step 1: Write failing cross-contour feature tests**

```php
$this->actingAs($user, 'api_landing')
    ->getJson('/api/v1/landing/notifications')
    ->assertJsonMissing(['id' => $adminNotification->id]);
$this->actingAs($user, 'api_landing')
    ->patchJson("/api/v1/landing/notifications/{$adminNotification->id}/read")
    ->assertNotFound();
```

Cover list, unread count, show, read, unread, mark-all-read and delete in both directions.

- [ ] **Step 2: Run tests and verify RED**

Run: `php artisan test tests/Feature/Api/V1/Notifications/NotificationContourIsolationTest.php`
Expected: FAIL because the controller scopes only by user.

- [ ] **Step 3: Implement trusted route resolution and target-scoped mutations**

Clamp `per_page` to 1–100, remove analytics eager loading from lists, update only the current target for read/dismiss operations, return 404 for other targets, and choose `AdminResponse`, `LandingResponse`, `MobileResponse` or `CustomerResponse` by trusted route.

- [ ] **Step 4: Run feature tests and static analysis**

Run: `php artisan test tests/Feature/Api/V1/Notifications/NotificationContourIsolationTest.php tests/Feature/Api/V1/Admin/AdminBaseExperienceControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git commit -m "fix: закрыта утечка уведомлений между контурами"
```

### Task 5: Перевод бизнес-отправителей на явные цели и права

**Files:**
- Modify every `NotificationService::send` and `Notify::send` caller under `app/BusinessModules` and `app/Services`.
- Modify: `app/Services/Filament/NotificationTemplateManagementService.php`
- Modify: `app/BusinessModules/Features/Notifications/Integration/ContractEventIntegration.php`
- Test: `tests/Unit/Notifications/NotificationSenderContractTest.php`
- Test: existing domain notification tests touched by changed senders.

**Interfaces:**
- Consumes: final `interfaces` argument or named `interfaces:` parameter.
- Produces: every sender declares its contour and sensitive domain permission.

- [ ] **Step 1: Write a failing source contract test**

The test scans all known notification senders and fails when a call omits `interfaces:` or a legacy payload omits a valid explicit interface during transition.

- [ ] **Step 2: Run the contract test and verify RED**

Run: `php artisan test tests/Unit/Notifications/NotificationSenderContractTest.php`
Expected: FAIL for contract and payment senders that rely on defaults.

- [ ] **Step 3: Migrate callers**

Contract owner events target `lk`; operational procurement, estimates and payment workflows target `admin`; existing dual security flows preserve separate `lk` and `admin` sends; system-admin customer broadcasts use `customer` and cannot request WebSocket until supported.

- [ ] **Step 4: Run notification and affected domain tests**

Run: `php artisan test tests/Unit/Notifications tests/Unit/BusinessModules/Core/Payments tests/Feature/EstimateGeneration`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git commit -m "fix: назначены явные контуры бизнес-уведомлений"
```

### Task 6: Безопасный lifecycle Echo и дедупликация в админке

**Files:**
- Modify: `../prohelper_admin/src/services/echoService.ts`
- Modify: `../prohelper_admin/src/contexts/NotificationsContext.tsx`
- Modify: `../prohelper_admin/src/contexts/AuthContext.tsx`
- Modify: `../prohelper_admin/src/types/notification.ts`
- Test: `../prohelper_admin/src/contexts/NotificationsContext.test.tsx`
- Test: `../prohelper_admin/src/services/echoService.test.ts`

**Interfaces:**
- Produces: `echoService.disconnect()` on logout/token change.
- Produces: event dedupe key `${notification.id}:admin` and bounded five-item realtime bell state.

- [ ] **Step 1: Write failing Vitest tests for logout, account switch and duplicate events**

```ts
expect(mockEcho.leave).toHaveBeenCalledWith(`App.Models.User.${userId}.admin`);
expect(mockEcho.disconnect).toHaveBeenCalledOnce();
expect(screen.getAllByTestId('notification-item')).toHaveLength(1);
```

- [ ] **Step 2: Run tests and verify RED**

Run: `npx vitest run src/contexts/NotificationsContext.test.tsx src/services/echoService.test.ts`
Expected: FAIL because logout does not disconnect the singleton and events are not deduplicated.

- [ ] **Step 3: Implement lifecycle and dedupe**

Track the subscribed user ID in a ref, leave the captured channel rather than re-reading cleared storage, disconnect and clear state on logout, and recreate Echo when token/user changes.

- [ ] **Step 4: Run targeted Vitest, TypeScript and changed-file lint**

Run: `npx vitest run src/contexts/NotificationsContext.test.tsx src/services/echoService.test.ts src/services/notificationService.test.ts`
Run: `npx tsc --noEmit`
Expected: PASS.

- [ ] **Step 5: Commit in the admin repository**

```bash
git commit -m "fix[lk]: изолированы уведомления и lifecycle Echo"
```

### Task 7: Безопасный lifecycle Echo и API-контракт ЛК

**Files:**
- Modify: `../prohelper_land/src/services/echo.ts`
- Modify: `../prohelper_land/src/hooks/useNotifications.ts`
- Modify: `../prohelper_land/src/services/notificationService.ts`
- Modify: `../prohelper_land/src/types/notification.ts`
- Modify: `../prohelper_land/src/contexts/AuthContext.tsx`
- Test: `../prohelper_land/src/hooks/useNotifications.test.tsx`
- Test: `../prohelper_land/src/services/notificationService.test.ts`

**Interfaces:**
- Produces: exported `disconnectEcho(): void` and token-aware `getEcho()`.
- Consumes: `LandingResponse`-wrapped paginated list and unread count.

- [ ] **Step 1: Write failing tests for only-LK events, dedupe, logout and response normalization**

```ts
expect(mockEcho.private).toHaveBeenCalledWith(`App.Models.User.${userId}.lk`);
expect(result.current.notifications).toHaveLength(1);
expect(disconnectEcho).toHaveBeenCalledOnce();
```

- [ ] **Step 2: Run tests and verify RED**

Run: `npx vitest run src/hooks/useNotifications.test.tsx src/services/notificationService.test.ts`
Expected: FAIL because no logout disconnect or defensive response normalization exists.

- [ ] **Step 3: Implement lifecycle, dedupe and normalized contract**

Leave the captured channel, disconnect the singleton on `user-logout`, clear hook state, recreate with the current token, and normalize `LandingResponse` consistently.

- [ ] **Step 4: Run targeted Vitest, TypeScript and changed-file lint**

Run: `npx vitest run src/hooks/useNotifications.test.tsx src/services/notificationService.test.ts`
Run: `npx tsc --noEmit`
Expected: PASS.

- [ ] **Step 5: Commit in the landing repository**

```bash
git commit -m "fix[lk]: разделены контуры уведомлений личного кабинета"
```

### Task 8: Финальная проверка, интеграция веток и production smoke

**Files:**
- Modify only test/config files required by failures discovered in the scoped verification.

**Interfaces:**
- Consumes all previous tasks.
- Produces merged and deployed backend, admin and landing commits with an end-to-end isolation proof.

- [ ] **Step 1: Run backend verification**

Run notification/broadcast/deployment tests, changed-file Pint, Larastan on changed PHP files, `php -l`, `git diff --check`, and `docker compose config --quiet`.
Expected: all exit 0; no migrations are executed locally.

- [ ] **Step 2: Run frontend verification**

Run targeted Vitest, `npx tsc --noEmit`, and changed-file ESLint/Prettier in admin and landing. Do not run either production build locally.
Expected: all exit 0.

- [ ] **Step 3: Review diffs and merge each feature branch into its repository main**

Use Russian Conventional Commits, preserve unrelated user files, push exact main SHAs, and monitor all deployment workflows to completion.

- [ ] **Step 4: Verify production read-only**

Check deployed SHAs, Horizon/Reverb processes, absence of retry storms, and recent sanitized logs through `codex-ro` SSH.

- [ ] **Step 5: End-to-end smoke**

Create separate user-executed test notifications for `admin` and `lk`. Verify immediate delivery only to the target, absence from the other contour after refresh, independent read state, and no delivery errors.
