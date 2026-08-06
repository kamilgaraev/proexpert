# Admin Avatar Auth Contract Implementation Plan

> **For Codex:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Показывать один и тот же актуальный аватар администратора в профиле и шапке МОСТ без дублирующих запросов и раскрытия внутреннего S3-ключа.

**Architecture:** Laravel сериализует текущего пользователя единым `UserResource` в login и `/auth/me`. React хранит текущего пользователя только в `AuthContext`; `Layout` читает это состояние, а сохранение профиля обновляет его через явный метод контекста.

**Tech Stack:** Laravel 11, PHP 8.2, React, TypeScript, Vitest, Material UI.

---

### Task 1: Backend auth contract

**Files:**
- Modify: `app/Http/Controllers/Api/V1/Admin/Auth/AuthController.php`
- Create: `tests/Unit/Http/Controllers/Api/V1/Admin/AuthControllerProfileResponseTest.php`

**Step 1: Write the failing test**

Добавить тест прямого вызова `me()` с реальным `User`: ответ должен содержать `data.user.avatar_url` и не содержать `avatar_path`.

**Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/Http/Controllers/Api/V1/Admin/AuthControllerProfileResponseTest.php`
Expected: FAIL, потому что контроллер возвращает необработанную модель.

**Step 3: Write minimal implementation**

Импортировать `UserResource` и использовать его для поля `user` в `login()` и `me()`.

**Step 4: Run test to verify it passes**

Run: `php artisan test tests/Unit/Http/Controllers/Api/V1/Admin/AuthControllerProfileResponseTest.php`
Expected: PASS без подключения к БД.

**Step 5: Commit**

```bash
git add app/Http/Controllers/Api/V1/Admin/Auth/AuthController.php tests/Unit/Http/Controllers/Api/V1/Admin/AuthControllerProfileResponseTest.php
git commit -m "fix[admin]: унифицирован контракт аватара авторизации"
```

### Task 2: AuthContext owns the current user

**Files:**
- Modify: `src/contexts/AuthContext.tsx`
- Modify: `src/contexts/AuthContext.race.test.tsx`
- Modify: `src/components/layout/Layout.tsx`

**Step 1: Write the failing test**

Расширить harness теста контекста: после вызова `updateUser` аватар и имя в `AuthContext.user` должны измениться с сохранением остальных auth-полей.

**Step 2: Run test to verify it fails**

Run: `npx vitest run src/contexts/AuthContext.race.test.tsx`
Expected: FAIL, потому что `updateUser` отсутствует.

**Step 3: Write minimal implementation**

Добавить типизированный `updateUser`, обновляющий только существующего авторизованного пользователя. Удалить из `Layout` локальное состояние пользователя и повторный `authService.getMe()`; использовать `user` и `isLoading` из `useAuth()`.

**Step 4: Run test to verify it passes**

Run: `npx vitest run src/contexts/AuthContext.race.test.tsx`
Expected: PASS.

### Task 3: Profile updates the shared auth user

**Files:**
- Modify: `src/pages/ProfileSettings/ProfileSettingsPage.tsx`
- Modify: `src/pages/ProfileSettings/ProfileSettingsPage.test.tsx`

**Step 1: Write the failing test**

Замокать `useAuth` на границе контекста и проверить действие: после успешного сохранения профиль передаёт обновлённые имя, email, телефон и `avatar_url` в `updateUser`.

**Step 2: Run test to verify it fails**

Run: `npx vitest run src/pages/ProfileSettings/ProfileSettingsPage.test.tsx`
Expected: FAIL, потому что страница не обновляет общий auth state.

**Step 3: Write minimal implementation**

Получить `updateUser` из `useAuth()` и вызвать его с ответом `updateProfile`. Обновить локальный preview серверным `avatar_url`, чтобы после удаления или загрузки использовался канонический URL.

**Step 4: Run test to verify it passes**

Run: `npx vitest run src/pages/ProfileSettings/ProfileSettingsPage.test.tsx`
Expected: PASS.

**Step 5: Commit frontend block**

```bash
git add src/contexts/AuthContext.tsx src/contexts/AuthContext.race.test.tsx src/components/layout/Layout.tsx src/pages/ProfileSettings/ProfileSettingsPage.tsx src/pages/ProfileSettings/ProfileSettingsPage.test.tsx
git commit -m "fix[admin]: синхронизирован аватар в шапке"
```

### Task 4: Verification and delivery

**Step 1: Backend static verification**

Run targeted PHPUnit test, PHP syntax checks, Laravel Pint on changed PHP files, and PHPStan for the changed backend files.

**Step 2: Frontend verification**

Run both targeted Vitest files, `npx tsc --noEmit`, ESLint and Prettier checks for changed TypeScript/TSX files. Do not run an admin production build.

**Step 3: Review diffs**

Review for contract regressions, auth races, secret leakage and accidental unrelated changes.

**Step 4: PR and deploy**

Push both feature branches, create PRs, merge backend first and admin second into `main`, then monitor the repository's existing deployment workflow. Verify production `/auth/me` contract through the UI/API without exposing the signed URL or credentials.
