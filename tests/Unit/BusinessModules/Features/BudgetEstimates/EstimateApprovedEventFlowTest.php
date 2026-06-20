<?php

declare(strict_types=1);

namespace Tests\Unit\BusinessModules\Features\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Events\EstimateApproved as BudgetEstimateApproved;
use PHPUnit\Framework\TestCase;

final class EstimateApprovedEventFlowTest extends TestCase
{
    public function test_budget_estimates_module_owns_the_only_estimate_approved_event(): void
    {
        self::assertTrue(class_exists(BudgetEstimateApproved::class));
        self::assertFileDoesNotExist($this->projectPath('app/Events/EstimateApproved.php'));
        self::assertFalse(class_exists('App\\Events\\EstimateApproved', false));
    }

    public function test_unregistered_project_budget_overwrite_listeners_are_removed(): void
    {
        self::assertFileDoesNotExist($this->projectPath('app/Listeners/UpdateProjectBudgetListener.php'));
        self::assertFileDoesNotExist($this->projectPath('app/BusinessModules/Features/BudgetEstimates/Listeners/UpdateProjectBudget.php'));
        self::assertFalse(class_exists('App\\Listeners\\UpdateProjectBudgetListener', false));
        self::assertFalse(class_exists('App\\BusinessModules\\Features\\BudgetEstimates\\Listeners\\UpdateProjectBudget', false));
    }

    private function projectPath(string $path): string
    {
        return dirname(__DIR__, 5).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
    }
}
