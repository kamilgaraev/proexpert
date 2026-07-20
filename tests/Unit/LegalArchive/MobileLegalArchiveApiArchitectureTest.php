<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use PHPUnit\Framework\TestCase;

final class MobileLegalArchiveApiArchitectureTest extends TestCase
{
    public function test_mobile_legal_archive_routes_are_registered_and_protected(): void
    {
        $root = __DIR__.'/../../../';
        $api = file_get_contents($root.'routes/api.php');
        $routes = file_get_contents($root.'routes/api/v1/mobile/legal_archive.php');

        self::assertIsString($api);
        self::assertIsString($routes);
        self::assertStringContainsString("require __DIR__.'/api/v1/mobile/legal_archive.php';", $api);
        self::assertStringContainsString("Route::get('/legal-archive/documents'", $routes);
        self::assertStringContainsString("Route::get('/legal-archive/documents/{document}'", $routes);
        self::assertStringContainsString("Route::post('/legal-archive/documents/{document}/actions/{action}'", $routes);
        self::assertStringContainsString("whereIn('action', ['approve', 'reject', 'return'])", $routes);

        foreach (['auth:api_mobile', 'auth.jwt:api_mobile', 'organization.context', 'can:access-mobile-app'] as $middleware) {
            self::assertStringContainsString("'{$middleware}'", $routes);
        }
    }

    public function test_list_resolves_workflow_summaries_in_one_batch_and_keeps_denied_summary_available(): void
    {
        $root = __DIR__.'/../../../';
        $controller = file_get_contents($root.'app/Http/Controllers/Api/V1/Mobile/LegalArchiveController.php');
        $service = file_get_contents($root.'app/Services/Mobile/MobileLegalArchiveService.php');

        self::assertIsString($controller);
        self::assertIsString($service);

        $index = $this->methodSource($controller, 'index');
        self::assertStringContainsString('$this->archive->summaries($actor, $documents->getCollection())', $index);
        self::assertStringNotContainsString('$this->archive->summary($actor, $document)', $index);

        $summaries = $this->methodSource($service, 'summaries');
        self::assertStringContainsString('$this->actions->forMany($actor, $documents)', $summaries);
        self::assertStringNotContainsString('$this->actions->for($actor, $document)', $summaries);

        $resolver = file_get_contents($root.'app/Services/LegalArchive/Workflow/LegalWorkflowActionResolver.php');
        self::assertIsString($resolver);
        self::assertStringContainsString('if (! ($permissions[LegalWorkflowPermissions::VIEW] ?? false)) {', $resolver);
        self::assertStringContainsString('return $this->deniedSummary($document);', $resolver);
    }

    public function test_mobile_action_contract_requires_idempotency_target_step_and_optimistic_locks(): void
    {
        $controller = file_get_contents(__DIR__.'/../../../app/Http/Controllers/Api/V1/Mobile/LegalArchiveController.php');
        self::assertIsString($controller);

        $action = $this->methodSource($controller, 'action');
        foreach ([
            "'idempotency_key' => ['required', 'uuid']",
            "'target_step_id' => ['required', 'integer', 'min:1']",
            "'instance_lock_version' => ['required', 'integer', 'min:0']",
            "'step_lock_version' => ['required', 'integer', 'min:0']",
        ] as $rule) {
            self::assertStringContainsString($rule, $action);
        }
    }

    public function test_mobile_failure_mapping_preserves_validation_authorization_and_lock_contracts(): void
    {
        $controller = file_get_contents(__DIR__.'/../../../app/Http/Controllers/Api/V1/Mobile/LegalArchiveController.php');
        self::assertIsString($controller);

        $failure = $this->methodSource($controller, 'failure');
        self::assertStringContainsString('if ($error instanceof AuthorizationException)', $failure);
        self::assertStringContainsString('if ($error instanceof ValidationException)', $failure);
        self::assertStringContainsString("trans_message('legal_archive.messages.validation_error'), 422, \$error->errors()", $failure);
        self::assertStringContainsString('if ($error instanceof LegalArchiveLockConflict)', $failure);
        self::assertStringContainsString("trans_message('legal_archive.messages.lock_conflict'), 409", $failure);
        self::assertStringContainsString("'current_lock_version' => \$error->currentLockVersion", $failure);
        self::assertStringContainsString("'refresh_url' => \$error->refreshUrl", $failure);
        self::assertStringContainsString('if ($error instanceof DomainException)', $failure);
        self::assertStringContainsString("trans_message('legal_archive.messages.operation_conflict'),\n                409", $failure);
        self::assertStringNotContainsString("trans_message('legal_archive.messages.document_not_found'), 404);\n    }", $failure);
    }

    private function methodSource(string $source, string $method): string
    {
        $start = strpos($source, "function {$method}(");
        self::assertNotFalse($start, "Method {$method} was not found.");

        $next = strpos($source, "\n    public function ", $start + 1);
        if ($next === false) {
            $next = strpos($source, "\n    private function ", $start + 1);
        }

        return substr($source, $start, $next === false ? null : $next - $start);
    }
}
