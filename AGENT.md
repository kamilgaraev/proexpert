# Agent Instructions for ProExpert Laravel Application

## Build/Test Commands
- **Install:** `composer install && npm install`
- **Development:** `composer run dev` (starts server, queue, logs, and Vite)
- **Test:** `vendor/bin/phpunit` (runs all tests)
- **Test Single:** `vendor/bin/phpunit tests/Feature/ExampleTest.php` (single test file)
- **Code Style:** `vendor/bin/pint` (Laravel Pint formatter)
- **Build Assets:** `npm run build`
- **API Docs:** `npm run docs:build` (generates API documentation)

## Architecture
- **Multi-tenant Laravel 11** with JWT authentication
- **Three separate APIs:** Landing/LK (`/api/v1/lk/`), Admin (`/api/v1/admin/`), Mobile (`/api/v1/mobile/`)
- **Core Models:** User, Organization, Project, Material, WorkType, Supplier with RBAC
- **Service Layer:** Repository pattern with organization-scoped queries
- **Key Directories:** `app/Services/`, `app/Repositories/`, `app/Http/Controllers/Api/V1/`

## Code Style
- **PSR-4 autoloading** with `App\` namespace
- **Spaces:** 4 spaces indentation (see .editorconfig)
- **Arrays:** Use square bracket syntax `[]` over `array()`
- **Imports:** Group by vendor, framework, app with proper namespacing
- **Validation:** Use Form Requests (`app/Http/Requests/`)
- **Resources:** API Resources for responses (`app/Http/Resources/`)
- **Error Handling:** Responsable classes and structured JSON responses
- **Comments:** Extensive PHPDoc blocks, especially for complex business logic
