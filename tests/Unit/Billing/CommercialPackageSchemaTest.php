<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use PHPUnit\Framework\TestCase;

class CommercialPackageSchemaTest extends TestCase
{
    public function test_migration_defines_new_commercial_tables_and_constraints(): void
    {
        $migration = $this->read('database/migrations/2026_07_14_000001_create_commercial_package_model.php');

        $this->assertStringContainsString("Schema::create('organization_commercial_accounts'", $migration);
        $this->assertStringContainsString("Schema::create('organization_package_trial_usages'", $migration);
        $this->assertStringContainsString("\$table->unique(['organization_id', 'package_slug'])", $migration);
        $this->assertStringContainsString("\$table->enum('status', [", $migration);
        $this->assertStringContainsString("\$table->enum('access_source', [", $migration);
        $this->assertLessThan(
            strpos($migration, '$table->dropColumn(['),
            strpos($migration, "DB::table('organization_package_subscriptions')->delete();"),
        );
    }

    public function test_package_subscription_model_contains_no_legacy_commercial_fields(): void
    {
        $model = $this->read('app/Models/OrganizationPackageSubscription.php');

        $this->assertStringNotContainsString("'subscription_id'", $model);
        $this->assertStringNotContainsString("'is_bundled_with_plan'", $model);
        $this->assertStringNotContainsString("'tier'", $model);
        $this->assertStringNotContainsString("'expires_at'", $model);
        $this->assertStringContainsString('PackageSubscriptionStatus::periodAccessValues()', $model);
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3).'/'.$relativePath);

        $this->assertIsString($contents);

        return $contents;
    }
}
