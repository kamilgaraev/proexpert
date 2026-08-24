# МОСТ Auth Registration Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task. По прямому указанию пользователя реализация выполняется последовательно без субагентов; допускается ровно один субагент только для финального независимого ревью после завершения кода и тестов.

**Goal:** Устранить AUTH-001–AUTH-018, доказать регрессией целостность регистрации и auth-сессий во всех клиентах МОСТ и довести изменения штатными GitHub Actions workflows до успешного production-деплоя.

**Architecture:** Backend остаётся единственным источником CORS, tenant, session, consent, idempotency и invitation-инвариантов. Main, admin и customer используют единый modern JWT web-auth механизм с разными audiences; mobile сохраняет legacy JWT transport, но проходит общую серверную active-membership проверку. Новые внешние сервисы и зависимости не добавляются: используются PostgreSQL, Laravel cache/queue/auth-session и существующие frontend test stacks.

**Tech Stack:** PHP 8.2, Laravel 11, PostgreSQL 16, `tymon/jwt-auth`, React/Vite/TypeScript, Vitest, MSW, Flutter/Dart, GitHub Actions.

**Spec:** `docs/specs/2026-08-24-auth-registration-production-audit.md`

## Global Constraints

- Продукт называется МОСТ; технические имена каталогов не используются как пользовательское название.
- Только JWT; Sanctum не добавлять.
- Не добавлять wildcard origins и не смешивать wildcard с credentials.
- Не добавлять новые инфраструктурные сервисы, очереди, брокеры или runtime dependencies.
- Backend controllers остаются HTTP-слоем; workflow и транзакции находятся в services.
- Пользовательские backend messages проходят через `trans_message(...)` и профильные Response classes.
- Токены, cookie, password, invitation/reset secrets, raw exception messages и PII не попадают в логи.
- Backend DB tests запускаются только через `tests/Runtime/run-postgres-tests.ps1`; миграции вручную не запускаются.
- `prohelper_land` и `prohelper_admin` локально не собираются; используются targeted Vitest, ESLint и `tsc --noEmit`.
- В каждом техническом репозитории используется отдельная ветка от актуального `origin/main`; чужие изменения не откатываются.
- Реализация последовательная. Один субагент разрешён только на финальное ревью полного набора diff.
- Deployment выполняется только существующими workflows: backend `.github/workflows/deploy-backend.yml`, landing `.github/workflows/deploy.yml`, customer `.github/workflows/deploy.yml`.

---

## File map

### Backend `prohelper`

- `config/web_auth.php` — audiences, origins, token TTL и customer cookie configuration.
- `app/Http/Middleware/CorsMiddleware.php` — route-to-audience classification и CORS response contract.
- `app/Http/Middleware/SetOrganizationContext.php` — active organization membership gate legacy JWT.
- `app/Http/Middleware/WebInterfaceSecurityMiddleware.php` — active membership для modern web audiences.
- `app/Services/Auth/WebAuthTokenService.php` — refresh rotation и короткий concurrent replay result.
- `app/Services/Auth/WebAuthenticationService.php` — общий login/refresh orchestration.
- `app/Services/Auth/JwtAuthService.php` — main registration, verification resend и legacy refresh validation.
- `app/Services/Customer/Auth/CustomerAuthService.php` — атомарные customer registration workflows.
- `app/Services/Project/ProjectParticipantInvitationService.php` — locked single-use invitation transition.
- `app/Http/Controllers/Api/V1/Customer/Auth/AuthController.php` — customer modern web auth HTTP endpoints.
- `app/Http/Controllers/Api/V1/Customer/InvitationController.php` — redacted logs and safe errors.
- `app/Http/Requests/Auth/RegisterRequest.php` — translated validation и server-side consent.
- `app/Http/Requests/Customer/*` — customer login/refresh/register contracts.
- `routes/api/v1/landing/auth.php`, `routes/api/v1/customer.php` — public resend и customer web-auth route stacks.
- `app/Models/AuthRegistrationAttempt.php` — durable idempotency record.
- `app/Models/UserConsent.php` — immutable registration consent evidence.
- `app/Services/Auth/RegistrationIdempotencyService.php` — request fingerprint, ownership и stored response.
- `app/Services/Auth/UserConsentService.php` — versioned consent persistence.
- `app/Jobs/Auth/CompleteRegistrationSideEffects.php` — existing Laravel queue post-commit side effects with deduplication.
- `database/migrations/2026_08_24_000001_create_auth_registration_attempts_table.php` — unique audience/key persistence.
- `database/migrations/2026_08_24_000002_create_user_consents_table.php` — immutable consent evidence.
- `lang/ru/auth.php` — safe translated auth/CORS/transport messages.
- `tests/Unit/Http/Middleware/CorsMiddlewareTest.php`, `tests/Feature/Auth/*`, `tests/Feature/Api/V1/Customer/Auth/*`, `tests/Feature/Api/V1/Landing/Auth/*` — regression suite.

### Landing/LK `prohelper_land`

- `src/components/shared/AutocompleteInput.tsx` — accessible combobox, debounce boundary and current-request semantics.
- `src/hooks/useDaData.ts` — abort, latest-response guard and short in-memory cache.
- `src/utils/api.ts` — normalized transport errors, timeout and idempotency header.
- `src/pages/dashboard/RegisterPage.tsx` — consent payload, retry and duplicate-submit state.
- `src/components/shared/AutocompleteInput.test.tsx`, `src/hooks/useDaData.test.ts`, `src/utils/api.test.ts`, `src/pages/dashboard/RegisterPage.test.tsx` — Vitest/MSW regression.

### Customer portal `prohelper_customers`

- `src/shared/api/customerApi.ts` — `credentials: include`, CSRF header, memory access token and single-flight refresh.
- `src/shared/api/storage.ts` — remove bearer persistence; retain only non-secret UI state if needed.
- `src/shared/api/authService.ts` — modern login/refresh/logout/CSRF bootstrap contract.
- `src/shared/contexts/AuthContext.tsx` — memory-only session lifecycle.
- `src/shared/types/auth.ts` — standardized token/session DTOs.
- `src/shared/api/authService.test.ts`, `src/shared/api/customerApi.test.ts`, `src/shared/contexts/AuthContext.test.tsx` — no-Web-Storage, refresh and logout tests.

### Mobile `prohelpers_mobile`

- Existing auth repository/interceptor tests — verify inactive membership and refresh error mapping; production storage remains `flutter_secure_storage`.

---

### Task 1: Prepare isolated implementation branches and baseline evidence

**Files:**
- Reference: `prohelper/docs/specs/2026-08-24-auth-registration-production-audit.md`
- Create: `prohelper/docs/superpowers/plans/2026-08-24-auth-registration-remediation.md`

**Interfaces:**
- Consumes: current `origin/main` of all five repositories.
- Produces: clean task branches and recorded baseline test commands.

- [ ] **Step 1: Fetch without modifying product state**

Run in each repository: `git fetch origin main --prune`.

- [ ] **Step 2: Preserve existing user branches and create task branches**

Use `git switch -c fix/auth-registration-remediation-20260824 origin/main` in repositories that will change. Backend branch first receives the committed audit spec and this plan by cherry-pick/merge without rewriting history.

- [ ] **Step 3: Record exact starting SHAs and status**

Run: `git status --short --branch` and `git rev-parse HEAD` in all five repositories. Any unrelated untracked `.codebase-memory/` remains untouched.

- [ ] **Step 4: Run narrow non-DB baseline tests**

Run existing CORS unit tests, landing registration tests and customer auth tests. Expected: existing tests pass; confirmed missing behaviors are introduced as failing tests in later tasks.

- [ ] **Step 5: Commit the implementation plan**

Commit: `docs[backend]: добавлен план исправления auth-контура`.

### Task 2: Correct CORS audiences and canonical registration origin

**Files:**
- Modify: `config/web_auth.php`
- Modify: `app/Http/Middleware/CorsMiddleware.php`
- Modify: `lang/ru/auth.php`
- Modify: `tests/Unit/Http/Middleware/CorsMiddlewareTest.php`
- Modify: `tests/Feature/Auth/AuthRouteStackHardeningTest.php`

**Interfaces:**
- Consumes: route prefixes `/landing`, `/admin`, `/customer`.
- Produces: `audienceForPath(string $path): string` returning `lk`, `admin`, `customer` or `public` and exact per-audience origins.

- [ ] **Step 1: Write failing table-driven CORS tests**

Add data providers asserting customer origins receive 204 only on customer endpoints, `lk` only on landing, admin only on admin, root only on public, all cross-audience requests receive 403, and allowed 4xx/5xx retain `Vary: Origin` and the exact ACAO.

- [ ] **Step 2: Verify the new tests fail**

Run targeted PHPUnit for `CorsMiddlewareTest` and `AuthRouteStackHardeningTest`. Expected: customer origin cases fail with 403 under the current classifier.

- [ ] **Step 3: Add the customer audience**

Add `web_auth.origins.customer` with both МОСТ punycode/Unicode-compatible host configuration and legacy service host; classify `/api/v1/customer/*` as `customer`. Keep credentials explicit and never use `*`.

- [ ] **Step 4: Replace the misleading denial response**

Return a stable machine code `cors_origin_forbidden` and `trans_message('auth.cors.origin_forbidden')` through the appropriate safe response shape without exposing the allowlist.

- [ ] **Step 5: Lock the root registration decision**

Keep landing API restricted to `lk`; add contract evidence that root `/register` must redirect before SPA boot and document a release smoke check. Do not broaden root origin unless the actual deployment workflow cannot guarantee the redirect.

- [ ] **Step 6: Run tests and commit**

Expected: full origin matrix passes. Commit: `fix[backend]: исправлены аудитории CORS auth-контура`.

### Task 3: Enforce active organization membership everywhere

**Files:**
- Modify: `app/Http/Middleware/SetOrganizationContext.php`
- Modify: `app/Http/Middleware/WebInterfaceSecurityMiddleware.php`
- Modify: `app/Services/Auth/JwtAuthService.php`
- Modify: `app/Services/Auth/UserAuthSessionService.php`
- Create: `tests/Feature/Auth/ActiveOrganizationMembershipTest.php`

**Interfaces:**
- Produces: `User::activeOrganizations()` as the only organization lookup for auth context; `UserAuthSessionService::revokeForInactiveMembership(User $user, int $organizationId): void`.

- [ ] **Step 1: Write failing PostgreSQL feature tests**

Cover protected request, legacy refresh, web refresh, fallback organization and organization switch after `organization_user.is_active=false` or pivot deletion. Assert 403/401, empty tenant context and revoked session.

- [ ] **Step 2: Verify the tests fail through the canonical launcher**

Run only `ActiveOrganizationMembershipTest.php` through `tests/Runtime/run-postgres-tests.ps1`.

- [ ] **Step 3: Replace unfiltered membership queries**

Use `activeOrganizations()` for token organization lookup and fallback. Reject inactive organization and never silently select another tenant when the token names a removed membership.

- [ ] **Step 4: Validate membership during refresh**

Before preserving an organization claim, reload active membership. Revoke the auth session with reason `organization_membership_inactive` on failure.

- [ ] **Step 5: Run regression and commit**

Run the new test plus existing inactive-user/auth-token tests. Commit: `fix[backend]: закрыт доступ при неактивном членстве`.

### Task 4: Make customer registration and invitation onboarding atomic

**Files:**
- Modify: `app/Services/Customer/Auth/CustomerAuthService.php`
- Modify: `app/Services/Project/ProjectParticipantInvitationService.php`
- Create: `tests/Feature/Api/V1/Customer/Auth/CustomerRegistrationAtomicityTest.php`
- Create: `tests/Feature/Api/V1/Customer/Auth/InvitationRegistrationAtomicityTest.php`

**Interfaces:**
- Produces: `CustomerAuthService::register(array $data): CustomerRegistrationResult` that throws on missing initial role; `registerByInvitation(array $data, string $token): CustomerRegistrationResult` with one outer transaction.

- [ ] **Step 1: Write role-assignment fault test**

Stub the role repository to throw and assert user, organization, membership and role counts remain unchanged.

- [ ] **Step 2: Write invitation post-create fault test**

Force invitation acceptance to fail and assert no account or organization survives; repeat the same request and verify it can succeed.

- [ ] **Step 3: Verify both tests fail**

Run the two files via PostgreSQL launcher.

- [ ] **Step 4: Remove the swallowed owner-role exception**

Let role assignment abort the existing transaction; translate only at the controller boundary.

- [ ] **Step 5: Move invitation acceptance inside the outer transaction**

Pass user/organization into a transaction-aware invitation method. Laravel nested calls must participate in the outer transaction and produce one final commit.

- [ ] **Step 6: Run tests and commit**

Commit: `fix[backend]: обеспечена атомарность customer-регистрации`.

### Task 5: Serialize invitation acceptance and remove secret logging

**Files:**
- Modify: `app/Services/Project/ProjectParticipantInvitationService.php`
- Modify: `app/Http/Controllers/Api/V1/Customer/InvitationController.php`
- Modify: `lang/ru/auth.php`
- Create: `tests/Feature/Api/V1/Customer/InvitationConcurrencyTest.php`
- Create: `tests/Feature/Api/V1/Customer/InvitationPrivacyTest.php`

**Interfaces:**
- Produces: atomic conditional transition `pending → accepted`; safe log context `{invitation_id, token_fingerprint, actor_id, organization_id}`.

- [ ] **Step 1: Write a two-connection concurrency test**

Start two PostgreSQL transactions accepting one token for different eligible organizations. Assert one acceptance and one stable domain conflict; assert only one participant attachment.

- [ ] **Step 2: Write log and response privacy tests**

Capture logs for validate/accept/decline error branches. Assert raw token, full email, exception message and trace are absent; response contains a domain code/message only.

- [ ] **Step 3: Verify failures**

Run the new invitation tests through the PostgreSQL launcher.

- [ ] **Step 4: Lock and conditionally update**

Inside one transaction query the invitation with `lockForUpdate()`, revalidate status/expiry/subject, attach exactly once and conditionally persist accepted state.

- [ ] **Step 5: Redact controller logs**

Use SHA-256 token fingerprint and invitation ID. Remove `$e->getMessage()` from user responses and translate known domain errors.

- [ ] **Step 6: Run tests and commit**

Commit: `fix[backend]: защищено принятие приглашений`.

### Task 6: Add durable registration idempotency and consent evidence

**Files:**
- Create: `database/migrations/2026_08_24_000001_create_auth_registration_attempts_table.php`
- Create: `database/migrations/2026_08_24_000002_create_user_consents_table.php`
- Create: `app/Models/AuthRegistrationAttempt.php`
- Create: `app/Models/UserConsent.php`
- Create: `app/Services/Auth/RegistrationIdempotencyService.php`
- Create: `app/Services/Auth/UserConsentService.php`
- Modify: `app/Http/Requests/Auth/RegisterRequest.php`
- Modify: `app/Services/Auth/JwtAuthService.php`
- Create: `tests/Feature/Api/V1/Landing/Auth/RegistrationIdempotencyTest.php`
- Create: `tests/Feature/Api/V1/Landing/Auth/RegistrationConsentTest.php`

**Interfaces:**
- Produces: `RegistrationIdempotencyService::execute(string $audience, string $key, array $fingerprintPayload, Closure $operation): array`; `UserConsentService::record(User $user, string $type, string $version, CarbonImmutable $acceptedAt): UserConsent`.

- [ ] **Step 1: Write failing idempotency tests**

Cover first request, response loss + same key/payload, concurrent same key, same key/different payload, missing key policy and expiration. Assert one user/org/membership and byte-equivalent business result.

- [ ] **Step 2: Write failing consent tests**

Reject absent/false consent; accept true with exact server-configured versions for terms/privacy; persist user, version, timestamp and request-safe metadata atomically.

- [ ] **Step 3: Verify failures via PostgreSQL launcher**

Run only the two new test files.

- [ ] **Step 4: Add constrained persistence**

`auth_registration_attempts` has unique `(audience, idempotency_key)`, SHA-256 request hash, `processing/completed/failed` status, nullable user ID, JSONB response and expiry. `user_consents` has user/type/version/accepted_at and a uniqueness constraint preventing duplicate evidence.

- [ ] **Step 5: Implement row-locked idempotent execution**

Hash canonical validated input excluding password bytes while including a password digest fingerprint; never persist plaintext password. Return stored completed response; reject key reuse with a different hash; recover stale processing rows deterministically.

- [ ] **Step 6: Make consent part of registration transaction**

Require boolean `terms_accepted` and `privacy_accepted`; use server-side versions from configuration, not arbitrary client versions.

- [ ] **Step 7: Run tests and commit**

Commit: `feat[backend]: добавлена идемпотентная регистрация и учёт согласий`.

### Task 7: Make verification delivery recoverable and post-commit effects reliable

**Files:**
- Create: `app/Jobs/Auth/CompleteRegistrationSideEffects.php`
- Modify: `app/Services/Auth/JwtAuthService.php`
- Modify: `app/Http/Controllers/Api/V1/Landing/Auth/AuthController.php`
- Modify: `routes/api/v1/landing/auth.php`
- Modify: `lang/ru/auth.php`
- Create: `tests/Feature/Api/V1/Landing/Auth/PublicVerificationResendTest.php`
- Create: `tests/Feature/Api/V1/Landing/Auth/RegistrationSideEffectsTest.php`

**Interfaces:**
- Produces: public `POST /api/v1/landing/auth/email/verification-notification` with opaque success; unique queued job keyed by registration attempt/user.

- [ ] **Step 1: Write failing public resend tests**

Existing and unknown emails return identical status/body; unauthenticated request is allowed; verified user remains opaque; rate limit applies per normalized email/IP without leaking existence.

- [ ] **Step 2: Write post-commit job tests**

Assert registration response does not depend on mail provider; job dispatches only after commit and retries without duplicate invitation/sync/notification effects.

- [ ] **Step 3: Implement the public resend contract**

Normalize email, return `LandingResponse` success for all subjects, dispatch verification only for eligible unverified users, and apply named throttle.

- [ ] **Step 4: Move synchronous side effects to the existing Laravel queue**

The unique job receives IDs only, runs after commit, re-loads models, records safe errors and is idempotent per side-effect key. No new broker/service is introduced.

- [ ] **Step 5: Run tests and commit**

Commit: `fix[backend]: восстановлена доставка подтверждения регистрации`.

### Task 8: Make refresh concurrency safe without weakening replay protection

**Files:**
- Modify: `app/Services/Auth/WebAuthTokenService.php`
- Modify: `app/DTOs/Auth/WebAuthTokenPair.php` if serialization is required
- Modify: `config/web_auth.php`
- Create: `tests/Feature/Auth/ConcurrentWebRefreshTest.php`

**Interfaces:**
- Produces: one rotation result per old refresh JTI during a bounded 5-second concurrency window; later reuse still revokes the session.

- [ ] **Step 1: Write failing concurrent refresh tests**

Two simultaneous requests with the same valid refresh token both receive the same current pair and keep the session active. A third replay after the bounded window revokes the session.

- [ ] **Step 2: Verify the test fails**

Run the new test through PostgreSQL launcher.

- [ ] **Step 3: Persist the encrypted short-lived rotation result in existing cache**

Under the existing lock, cache an encrypted serialized token pair keyed by audience/session/old JTI for exactly five seconds. Waiting concurrent requests return it; no raw token appears in logs or durable DB.

- [ ] **Step 4: Preserve replay revocation outside the concurrency window**

Mismatch without a valid encrypted concurrency result follows the existing revoke path.

- [ ] **Step 5: Run tests and commit**

Commit: `fix[backend]: устранена гонка обновления web-сессии`.

### Task 9: Migrate customer portal to modern web auth

**Files:**
- Modify: `config/web_auth.php`
- Modify: `routes/api/v1/customer.php`
- Modify: `app/Http/Controllers/Api/V1/Customer/Auth/AuthController.php`
- Modify: `prohelper_customers/src/shared/api/customerApi.ts`
- Modify: `prohelper_customers/src/shared/api/authService.ts`
- Modify: `prohelper_customers/src/shared/api/storage.ts`
- Modify: `prohelper_customers/src/shared/contexts/AuthContext.tsx`
- Modify: `prohelper_customers/src/shared/types/auth.ts`
- Modify/Create tests in both repositories.

**Interfaces:**
- Produces: customer login response with memory access token and CSRF token; refresh/logout use `__Host-` HttpOnly refresh cookie scoped to customer audience.

- [ ] **Step 1: Write backend customer web-auth tests**

Cover login, refresh, CSRF, logout, audience mismatch, cookie flags, inactive user/membership and legacy bearer rejection on migrated routes.

- [ ] **Step 2: Implement customer audience endpoints using existing services**

Controller delegates to `WebAuthenticationService`, `WebRefreshCookieService` and `CustomerResponse`; no duplicate token implementation is created.

- [ ] **Step 3: Write frontend no-Web-Storage tests**

Assert login token is held only in module memory, `sessionStorage`/`localStorage` contain no auth token, requests include credentials/CSRF, 401 triggers one refresh and logout clears memory.

- [ ] **Step 4: Update customer API/session lifecycle**

Port the existing main/admin memory-token and single-flight pattern into customer-local focused modules; do not add a shared package or dependency.

- [ ] **Step 5: Run backend and customer checks**

Backend targeted PostgreSQL tests; customer `npx tsc --noEmit`, targeted Vitest and ESLint on changed files.

- [ ] **Step 6: Commit in each repository**

Backend: `feat[backend]: customer переведён на защищённую web-сессию`. Customer: `fix[lk]: защищено хранение customer-сессии`.

### Task 10: Stabilize registration network UX and DaData

**Files:**
- Modify: `prohelper_land/src/utils/api.ts`
- Modify: `prohelper_land/src/hooks/useDaData.ts`
- Modify: `prohelper_land/src/components/shared/AutocompleteInput.tsx`
- Modify: `prohelper_land/src/pages/dashboard/RegisterPage.tsx`
- Modify/Create corresponding Vitest files.

**Interfaces:**
- Produces: `ApiTransportError` with `kind: 'offline'|'timeout'|'http'|'aborted'`; one `Idempotency-Key` per user submit attempt reused for safe retry; abortable DaData search.

- [ ] **Step 1: Write failing transport tests**

Mock offline rejection, Safari `Load failed`, timeout, 409/422/429/500 and lost response. Assert translated user messages, stable idempotency key on retry and no double submit.

- [ ] **Step 2: Write failing DaData timing tests**

With fake timers type ten changes; after 300 ms assert one call. Resolve old/new promises out of order and assert only newest options render. Assert abort on unmount.

- [ ] **Step 3: Implement normalized fetch and idempotency key lifecycle**

Use `AbortController` timeout; map raw network errors to safe Russian messages; keep the key until definitive success or validation conflict, then rotate for a genuinely new registration attempt.

- [ ] **Step 4: Implement debounce, cancellation and cache**

Debounce at 300 ms, minimum two characters, cancel superseded request, guard sequence and cache normalized query results briefly in memory.

- [ ] **Step 5: Send server consent fields and protect submit**

Append `terms_accepted=true` and `privacy_accepted=true`; disable submit while pending and preserve editable form values after recoverable error.

- [ ] **Step 6: Run checks and commit**

Run targeted Vitest, ESLint changed files and `npx tsc --noEmit`; do not run build. Commit: `fix[lk]: стабилизирована регистрация и поиск организации`.

### Task 11: Implement accessible organization combobox

**Files:**
- Modify: `prohelper_land/src/components/shared/AutocompleteInput.tsx`
- Create/Modify: `prohelper_land/src/components/shared/AutocompleteInput.test.tsx`

**Interfaces:**
- Produces: WAI-ARIA combobox with stable input/listbox/option IDs and deterministic keyboard behavior.

- [ ] **Step 1: Write failing accessibility tests**

Assert `role=combobox`, `aria-expanded`, `aria-controls`, `aria-activedescendant`, `role=listbox/option`, Enter/Escape/Arrow navigation and live loading/error status.

- [ ] **Step 2: Implement semantic state**

Keep manual text entry; highlight only existing options; update active descendant and close/reset consistently on selection, blur and Escape.

- [ ] **Step 3: Run axe-compatible/component tests and commit**

Commit with Task 10 if the diff is inseparable; otherwise `fix[lk]: форма организации доступна с клавиатуры`.

### Task 12: Standardize auth messages and complete regression coverage

**Files:**
- Modify: `app/Http/Requests/Auth/RegisterRequest.php`
- Modify: `lang/ru/auth.php`
- Modify auth controllers/responses only where the envelope diverges
- Create: `tests/Feature/Auth/AuthResponseContractTest.php`
- Extend: CORS, membership, registration, invitation, reset, verification and customer suites
- Extend mobile auth tests without changing secure storage architecture

**Interfaces:**
- Produces: stable `{success, data, message, code, errors?, correlation_id?}` semantics inside the existing audience-specific Response classes.

- [ ] **Step 1: Write contract tests for all audiences**

Cover validation, unauthenticated, forbidden, rate-limited, conflict and internal failure. Assert no technical exception text and translated messages.

- [ ] **Step 2: Replace hardcoded RegisterRequest messages**

Map field/rule messages to `trans_message('auth.validation.*')` and preserve frontend field keys.

- [ ] **Step 3: Run translation completeness and API contract tests**

Expected: landing/admin/customer/mobile share machine error semantics while keeping their required Response class.

- [ ] **Step 4: Run mobile regression**

Run targeted Flutter auth/storage/interceptor tests; confirm no token-storage regression and inactive membership errors cause local sign-out/context reset.

- [ ] **Step 5: Commit**

Backend: `fix[backend]: унифицированы ошибки auth-контура`. Mobile only if changed: `fix[mobile]: обработано прекращение членства`.

### Task 13: Verification gate and single final independent review

**Files:**
- All changed files in all task branches.

**Interfaces:**
- Consumes: completed Tasks 1–12.
- Produces: release candidates with no unresolved Critical/High review findings.

- [ ] **Step 1: Run backend targeted suite once**

Use the canonical PostgreSQL launcher for all newly added auth tests plus existing auth/CORS/password/invitation tests. Run PHPStan only on changed/staged PHP files and PSR-12 check.

- [ ] **Step 2: Run frontend/mobile gates once**

Landing/customer targeted Vitest, changed-file ESLint and `tsc --noEmit`; mobile targeted tests and analyzer only if Dart changed. Do not run prohibited local builds.

- [ ] **Step 3: Inspect diffs and secrets**

Run `git diff --check`, changed-file review, raw-token/password logging scan, route/audience matrix scan and migration safety review.

- [ ] **Step 4: Invoke the only permitted subagent**

Provide the audit spec, implementation plan, complete diffs and test evidence. Request one independent security/correctness review focused on tenant boundaries, races, session lifecycle, CORS and missing coverage. Do not delegate fixes.

- [ ] **Step 5: Resolve findings locally**

Fix every Critical/High and valid Medium finding; rerun only affected tests, then one minimal regression. No second full subagent review.

- [ ] **Step 6: Prepare release commits**

Ensure each repository branch is clean, commits are scoped, and audit/plan documents match implemented behavior.

### Task 14: PR, штатный deployment and production verification

**Files:**
- Existing `.github/workflows/deploy-backend.yml`
- Existing `prohelper_land/.github/workflows/deploy.yml`
- Existing `prohelper_customers/.github/workflows/deploy.yml`
- No workflow modifications unless an existing workflow defect independently blocks the штатный deployment; such a change requires its own evidence and review.

**Interfaces:**
- Produces: merged release commits, green штатные workflows and verified production auth matrix.

- [ ] **Step 1: Push task branches and create PRs**

Use the existing GitHub repository flow. PR descriptions list AUTH IDs, migrations, tests, rollout order and rollback conditions.

- [ ] **Step 2: Wait for required CI and merge in dependency order**

Merge backend first only when it remains backward-compatible with old clients; otherwise deploy backend compatibility endpoints, then customer frontend, then remove legacy behavior in a later backward-compatible backend commit. Landing follows after backend idempotency/consent contract is available.

- [ ] **Step 3: Use only штатные deployment workflows**

Trigger/observe backend `deploy-backend.yml`, customer `deploy.yml`, landing `deploy.yml`. Do not SSH-deploy, manually copy artifacts, restart services or clear caches.

- [ ] **Step 4: Verify production read-only**

Run safe `OPTIONS` matrix for root/www/lk/admin/customer origins, verify exact ACAO/credentials/Vary/max-age, root registration redirect, customer login page boot and 4xx CORS headers. Use no account creation and no state-changing form submissions.

- [ ] **Step 5: Verify observability**

Check штатный workflow conclusions, read-only nginx logs and GlitchTip for new auth/CORS exceptions. Confirm no new request storm and no secret-bearing log events.

- [ ] **Step 6: Close the goal only after successful deployment evidence**

Record deployed SHAs per repository, workflow run URLs/conclusions, production probe results and remaining non-blocking risks. Mark the long-running goal complete only when all required deployments and checks are green.

---

## Coverage map

| Finding | Tasks |
|---|---|
| AUTH-001 | 2, 9, 14 |
| AUTH-002 | 2, 10, 14 |
| AUTH-003 | 3, 9, 12 |
| AUTH-004 | 4 |
| AUTH-005 | 4 |
| AUTH-006 | 5 |
| AUTH-007 | 7 |
| AUTH-008 | 6, 7, 10 |
| AUTH-009 | 10 |
| AUTH-010 | 10 |
| AUTH-011 | 8, 9 |
| AUTH-012 | 6, 10 |
| AUTH-013 | 5, 12 |
| AUTH-014 | 11 |
| AUTH-015 | 2, 12 |
| AUTH-016 | 2–13 |
| AUTH-017 | 9 |
| AUTH-018 | 2 |

## Completion definition

План завершён только если все чекбоксы Tasks 1–14 закрыты, все AUTH-001–AUTH-018 имеют passing regression evidence, финальное независимое ревью не содержит нерешённых Critical/High замечаний, штатные deployments успешны, production origin matrix соответствует контракту, а deployed SHAs зафиксированы в итоговом сообщении.
