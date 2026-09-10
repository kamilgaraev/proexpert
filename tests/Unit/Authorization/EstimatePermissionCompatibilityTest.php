<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Domain\Authorization\Services\PermissionResolver;
use App\Services\Logging\LoggingService;
use Mockery;
use Tests\TestCase;

final class EstimatePermissionCompatibilityTest extends TestCase
{
    public function test_custom_estimator_permissions_match_both_estimate_prefixes(): void
    {
        $resolver = $this->resolver();
        foreach (['view', 'view_all', 'create', 'edit', 'import', 'export'] as $action) {
            self::assertTrue($resolver->check(['budget_estimates' => ['estimates.'.$action]], 'budget-estimates', $action));
            self::assertTrue($resolver->check(['budget_estimates' => ['budget-estimates.'.$action]], 'estimates', $action));
        }
        self::assertTrue($resolver->check(['budget_estimates' => ['estimates.*']], 'budget-estimates', 'view'));
    }

    public function test_permissions_do_not_cross_actions_or_grant_journal_access(): void
    {
        $resolver = $this->resolver();
        self::assertFalse($resolver->check(['budget_estimates' => ['estimates.import']], 'budget-estimates', 'view'));
        self::assertFalse($resolver->check(['budget_estimates' => ['estimates.view']], 'budget-estimates', 'delete'));
        self::assertFalse($resolver->check(['budget_estimates' => ['estimates.view']], 'budget-estimates', '*'));
        self::assertFalse($resolver->check(['budget_estimates' => ['construction-journal.view']], 'budget-estimates', 'view'));
        self::assertFalse($resolver->check(['budget_estimates' => ['estimates.view']], 'construction-journal', 'view'));
        self::assertFalse($resolver->check(['contract_management' => ['contracts.view']], 'budget-estimates', 'view'));
        self::assertFalse($resolver->check([], 'budget-estimates', 'view'));
    }

    private function resolver(): PermissionResolver
    {
        return new class extends PermissionResolver
        {
            public function __construct()
            {
                $this->logging = Mockery::mock(LoggingService::class);
                $this->logging->shouldReceive('technical');
            }

            public function check(array $permissions, string $module, string $action): bool
            {
                return $this->checkModulePermission($permissions, 'budget-estimates', $module, $action, $module.'.'.$action);
            }
        };
    }
}
