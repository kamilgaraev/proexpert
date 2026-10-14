<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Models\User;
use Database\Seeders\ProductionLoadTestSeeder;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class LoadTestSeederSecurityTest extends TestCase
{
    public function test_seeded_account_uses_the_explicit_environment_password(): void
    {
        $password = bin2hex(random_bytes(24));
        $environment = [
            'MOST_LOAD_TEST_SEEDER_CONFIRM' => 'yes', 'LOAD_TEST_PASSWORD' => $password,
            'MOST_LOAD_TEST_ORGS' => '1', 'MOST_LOAD_TEST_USERS_PER_ORG' => '1',
            'MOST_LOAD_TEST_PROJECTS_PER_ORG' => '1', 'MOST_LOAD_TEST_MATERIALS_PER_ORG' => '1',
            'MOST_LOAD_TEST_SITE_REQUESTS_PER_ORG' => '0', 'MOST_LOAD_TEST_TASKS_PER_PROJECT' => '0',
            'MOST_LOAD_TEST_PAYMENTS_PER_ORG' => '0',
        ];
        $previous = [];
        try {
            foreach ($environment as $key => $value) {
                $previous[$key] = getenv($key);
                putenv($key.'='.$value);
            }
            (new ProductionLoadTestSeeder())->run();
            $account = User::query()->where('email', 'loadtest-org01-owner@prohelper.test')->firstOrFail();
            self::assertTrue(Hash::check($password, $account->password));
            self::assertTrue($account->is_active);
            self::assertTrue($account->settings['load_test']);
        } finally {
            foreach ($previous as $key => $value) {
                putenv($value === false ? $key : $key.'='.$value);
            }
        }
    }
}
