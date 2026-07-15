<?php

declare(strict_types=1);

namespace Tests\Unit\Seeders;

use PHPUnit\Framework\TestCase;

final class BrickHouseDemoSeederBudgetingContractTest extends TestCase
{
    public function test_brick_house_demo_seeder_contains_budgeting_flow(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3).'/database/seeders/BrickHouseDemoSeeder.php'
        );

        $this->assertStringContainsString("'slug' => 'budgeting'", $source);
        $this->assertStringContainsString("'budget_periods'", $source);
        $this->assertStringContainsString("'budget_versions'", $source);
        $this->assertStringContainsString("'budget_lines'", $source);
        $this->assertStringContainsString("'budget_amounts'", $source);
        $this->assertStringContainsString('seedBudgetingDemo(', $source);
        $this->assertStringContainsString('applyBudgetingToPayments(', $source);
        $this->assertStringContainsString("'budget_article_id' =>", $source);
        $this->assertStringContainsString("'responsibility_center_id' =>", $source);
    }
}
