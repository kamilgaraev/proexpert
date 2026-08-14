<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use PHPUnit\Framework\TestCase;

final class MultiAgentUsageContractTest extends TestCase
{
    public function test_new_role_operations_are_allowed_and_declared_by_forward_migration(): void
    {
        $uuid = '00000000-0000-4000-8000-000000000001';
        $contexts = [
            new AiOperationContext($uuid, $uuid, 1, 2, 3, 'understand_documents', 'project_synthesis', 1),
            new AiOperationContext($uuid, $uuid, 1, 2, 3, 'plan_work_items', 'estimate_composition', 1),
            new AiOperationContext($uuid, $uuid, 1, 2, 3, 'validate_draft', 'estimate_audit', 1),
        ];
        self::assertSame(['project_synthesis', 'estimate_composition', 'estimate_audit'], array_column($contexts, 'operation'));

        $migration = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_14_000100_extend_ai_usage_for_multi_agent_roles.php');
        self::assertIsString($migration);
        foreach (array_column($contexts, 'operation') as $operation) {
            self::assertStringContainsString("'{$operation}'", $migration);
        }
        self::assertStringContainsString('NOT VALID', $migration);
        self::assertStringContainsString('VALIDATE CONSTRAINT', $migration);
    }
}
