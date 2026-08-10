<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\Http\Requests\EstimateGeneration\ApplyProjectModelCorrectionRequest;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProjectModelCorrectionApiTest extends TestCase
{
    #[Test]
    public function correction_command_requires_a_scope_version_canonical_value_reason_and_idempotency_key(): void
    {
        $rules = (new ApplyProjectModelCorrectionRequest)->rules();

        self::assertContains('required', $rules['expected_source_version']);
        self::assertContains('regex:/^sha256:[a-f0-9]{64}$/', $rules['expected_source_version']);
        self::assertContains('required', $rules['expected_value_fingerprint']);
        self::assertContains('regex:/^[a-f0-9]{64}$/', $rules['expected_value_fingerprint']);
        self::assertContains('required', $rules['assertion_stable_key']);
        self::assertContains('required', $rules['value']);
        self::assertContains('required', $rules['reason']);
        self::assertContains('required', $rules['idempotency_key']);
    }

    #[Test]
    public function routes_expose_separate_append_and_latest_only_revert_commands_under_review_permission(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 3).'/app/BusinessModules/Addons/EstimateGeneration/routes.php');

        self::assertIsString($routes);
        self::assertStringContainsString("Route::post('/{session}/project-model/corrections'", $routes);
        self::assertStringContainsString("Route::post('/{session}/project-model/corrections/revert'", $routes);
        self::assertStringContainsString("authorize:estimate_generation.review,project,project", $routes);
    }

    #[Test]
    public function correction_workflow_locks_the_actual_model_head_and_uses_one_idempotency_namespace_before_assertion_lookup(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/app/BusinessModules/Addons/EstimateGeneration/BuildingModel/ApplyProjectModelCorrection.php');

        self::assertIsString($source);
        self::assertStringNotContainsString('$modelCorrections = $this->modelCorrections(', $source);
        self::assertStringContainsString('$latest = $this->latestCorrection(', $source);
        self::assertStringContainsString("->where('stable_key', \$this->stableKey(\$idempotencyHash))", $source);
        self::assertLessThan(
            strpos($source, '$assertion = $this->assertion('),
            strpos($source, '$existing = $this->idempotentCorrection($model'),
        );
        self::assertStringContainsString("return 'correction:'.\$idempotencyHash;", $source);
        self::assertStringNotContainsString("->where('content_version', \$expectedSourceVersion)\n            ->latest('id')", $source);
        self::assertStringContainsString("->latest('id')\n            ->lockForUpdate()", $source);
    }
}
