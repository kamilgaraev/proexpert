<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class LegacyBillingRuntimeRemovalTest extends TestCase
{
    #[DataProvider('forbiddenRuntimeContracts')]
    public function test_legacy_commercial_contract_is_absent_from_runtime(string $pattern): void
    {
        $root = dirname(__DIR__, 2);
        $matches = [];

        foreach (['app', 'bootstrap', 'config', 'routes'] as $directory) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory));

            foreach ($iterator as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), ['php', 'json'], true)) {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());
                if (is_string($contents) && preg_match($pattern, $contents) === 1) {
                    $matches[] = str_replace('\\', '/', $file->getPathname());
                }
            }
        }

        self::assertSame([], $matches, $pattern.' remains in: '.implode(', ', $matches));
    }

    public static function forbiddenRuntimeContracts(): iterable
    {
        yield 'plan slug' => ['~plan_slug~i'];
        yield 'subscription plan model' => ['~SubscriptionPlan~'];
        yield 'organization subscription model' => ['~OrganizationSubscription~'];
        yield 'subscription plans table' => ['~subscription_plans~i'];
        yield 'organization subscriptions table' => ['~organization_subscriptions~i'];
        yield 'starter offer' => ['~[\'\"]Starter[\'\"]~'];
        yield 'business offer' => ['~[\'\"]Business[\'\"]~'];
        yield 'profi offer' => ['~[\'\"]Profi[\'\"]~'];
        yield 'enterprise constructor offer' => ['~[\'\"]Enterprise Constructor[\'\"]~'];
    }

    public function test_legacy_landing_and_module_billing_routes_are_absent(): void
    {
        $root = dirname(__DIR__, 2);
        $billing = file_get_contents($root.'/routes/api/v1/landing/billing.php');
        $modules = file_get_contents($root.'/routes/api/v1/landing/modules.php');

        self::assertIsString($billing);
        self::assertIsString($modules);
        self::assertStringNotContainsString('SubscriptionPlanController', $billing);
        self::assertStringNotContainsString('OrganizationSubscriptionController', $billing);
        self::assertStringNotContainsString('toggleAutoRenew', $modules);
        self::assertStringNotContainsString('bulkToggleAutoRenew', $modules);
        self::assertStringNotContainsString("prefix('billing')", $modules);
    }

    public function test_cleanup_migration_runs_after_commercial_schema_and_preserves_domain_data(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 2).'/database/migrations/2026_07_15_000001_remove_legacy_billing_runtime.php');

        self::assertIsString($migration);
        self::assertStringContainsString('Schema::dropIfExists($table)', $migration);
        self::assertStringContainsString("'commercial_order_id'", $migration);
        self::assertStringContainsString("'commercial_payment_id'", $migration);
        self::assertStringContainsString("'organization_module_activations'", $migration);
        self::assertStringNotContainsString("DB::table('projects')->delete()", $migration);
        self::assertStringNotContainsString("DB::table('users')->delete()", $migration);
        self::assertStringNotContainsString("DB::table('organizations')->delete()", $migration);
    }
}
