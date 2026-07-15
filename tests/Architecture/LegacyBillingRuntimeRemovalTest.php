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
        yield 'organization subscription expiry field' => ['~subscription_expires_at~i'];
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
        self::assertStringNotContainsString("DB::table('contractor_referral_rewards')->delete()", $migration);
        self::assertStringContainsString("'legacy_invited_subscription_id'", $migration);
        self::assertStringContainsString('commercial_source_not_found_after_billing_migration', $migration);
        self::assertStringContainsString("Schema::hasColumn('balance_transactions', 'payment_id')", $migration);

        $dropPaymentReferenceAt = strpos($migration, '$this->dropLegacyForeignColumns()');
        $dropPaymentsTableAt = strpos($migration, "foreach (['organization_subscription_addons'");

        self::assertIsInt($dropPaymentReferenceAt);
        self::assertIsInt($dropPaymentsTableAt);
        self::assertLessThan($dropPaymentsTableAt, $dropPaymentReferenceAt);
    }

    public function test_cleanup_migration_is_explicitly_irreversible(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 2).'/database/migrations/2026_07_15_000001_remove_legacy_billing_runtime.php');

        self::assertIsString($migration);
        self::assertStringContainsString('throw new \\RuntimeException(', $migration);
        self::assertStringNotContainsString('restoreLegacyTables', $migration);
    }

    public function test_removed_balance_and_limits_contracts_are_absent(): void
    {
        $root = dirname(__DIR__, 2);

        self::assertFileDoesNotExist($root.'/app/Models/Payment.php');
        self::assertFileDoesNotExist($root.'/app/Http/Resources/Billing/SubscriptionLimitsResource.php');
        self::assertFileDoesNotExist($root.'/config/billing.php');

        $balanceService = file_get_contents($root.'/app/Services/Billing/BalanceService.php');
        $balanceContract = file_get_contents($root.'/app/Interfaces/Billing/BalanceServiceInterface.php');
        $balanceTransaction = file_get_contents($root.'/app/Models/BalanceTransaction.php');
        $balanceResource = file_get_contents($root.'/app/Http/Resources/Billing/BalanceTransactionResource.php');

        self::assertIsString($balanceService);
        self::assertIsString($balanceContract);
        self::assertIsString($balanceTransaction);
        self::assertIsString($balanceResource);
        self::assertStringNotContainsString('App\\Models\\Payment', $balanceService.$balanceContract);
        self::assertStringNotContainsString('payment_id', $balanceService.$balanceTransaction.$balanceResource);
    }

    public function test_registration_does_not_mint_testing_balance(): void
    {
        $authService = file_get_contents(dirname(__DIR__, 2).'/app/Services/Auth/JwtAuthService.php');

        self::assertIsString($authService);
        self::assertStringNotContainsString('grantTestingBalanceIfEnabled', $authService);
        self::assertStringNotContainsString('billing.testing', $authService);
    }

    public function test_brick_house_demo_is_preserved_without_old_subscription_bootstrap(): void
    {
        $root = dirname(__DIR__, 2);
        $seederPath = $root.'/database/seeders/BrickHouseDemoSeeder.php';
        $servicePath = $root.'/app/Services/Demo/BrickHouseDemoScenarioService.php';
        $commandPath = $root.'/app/Console/Commands/SeedDemoScenarioCommand.php';

        self::assertFileExists($seederPath);
        self::assertFileExists($servicePath);
        self::assertFileExists($commandPath);

        $seeder = file_get_contents($seederPath);
        $service = file_get_contents($servicePath);

        self::assertIsString($seeder);
        self::assertIsString($service);
        self::assertStringContainsString('commercial_accounts', $seeder);
        self::assertStringContainsString('organization_package_subscriptions', $seeder);
        self::assertStringNotContainsString('subscription_plans', $seeder.$service);
        self::assertStringNotContainsString('organization_subscriptions', $seeder.$service);
    }

    public function test_generated_route_manifests_do_not_reference_removed_billing_runtime(): void
    {
        $root = dirname(__DIR__, 2);

        self::assertFileDoesNotExist($root.'/route-list.json');
        self::assertFileDoesNotExist($root.'/routes.json');
    }
}
