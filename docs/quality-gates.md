# Backend Quality Gates

## Quick Local Gate

Run after focused backend edits:

```powershell
php -l path\to\changed.php
composer analyse -- --no-progress path\to\changed.php
php artisan test --filter=SpecificScenario
```

## Module Gate

Run before handing off a backend module:

```powershell
composer analyse -- --no-progress app\BusinessModules\Features\Procurement
php artisan test tests\Feature\Procurement --stop-on-failure
```

## Release Gate

Run in CI or before release:

```powershell
composer analyse -- --no-progress
php artisan test
```

## Filament Superadmin Gate

Run before releasing superadmin changes:

```powershell
php artisan test tests\Feature\Filament --stop-on-failure
php artisan test tests\Unit\Filament --stop-on-failure
php artisan test tests\Unit\Blog --stop-on-failure
php artisan test tests\Unit\RoleDefinitions --stop-on-failure
vendor\bin\phpstan analyse app\Filament app\Services\Filament app\Services\Blog app\Services\Security app\Models\SystemAdmin.php --memory-limit=1G --no-progress
rg "DeleteBulkAction|ForceDeleteAction" app\Filament
```

The `rg` check must return no matches. Browser QA is required against a reachable `/admin` URL; if no URL is reachable, document that explicitly in the runbook and final status.

## AI Assistant RAG Gate

Run after changes in `app\BusinessModules\Features\AIAssistant`, RAG collectors, RAG API contracts, source navigation, or the admin AI-assistant page:

```powershell
php artisan test tests\Unit\AIAssistant\Rag --stop-on-failure
php artisan test tests\Feature\Api\V1\Admin\AIAssistantRagContextTest.php tests\Feature\Api\V1\Admin\AIAssistantRagOperationsTest.php --stop-on-failure
php artisan test tests\Feature\Console\AIAssistantRagBackfillCommandTest.php --stop-on-failure
php artisan test tests\Unit\AIAssistant\AIAssistantSourceEncodingTest.php --stop-on-failure
vendor\bin\phpstan.bat analyse app\BusinessModules\Features\AIAssistant --memory-limit=1G
```

For admin contract or navigation changes:

```powershell
cd ..\prohelper_admin
npx tsc --noEmit
npx vitest run src\pages\AIAssistant\ragSources.test.ts src\services\aiAssistantService.test.ts
cd ..\prohelper
```

Do not run local migrations, frontend dev servers, or forbidden admin/land builds only for this gate. If browser smoke is required but no URL is already reachable, record that limitation explicitly.

## Notes

The full backend suite does not need to be the fastest feedback path on every local change. For day-to-day work, use syntax checks, targeted Larastan, and focused tests first; reserve the full suite for release, nightly, or CI validation.
