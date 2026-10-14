<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Models\User;
use Tests\TestCase;

final class SeededAccountCredentialRetirementTest extends TestCase
{
    public function test_migration_retires_only_marked_synthetic_accounts_and_preserves_real_accounts(): void
    {
        $demo = User::factory()->create(['email' => 'demo.owner@prohelper.test', 'settings' => ['demo_account' => true]]);
        $load = User::factory()->create(['email' => 'loadtest-org01-owner@prohelper.test', 'settings' => ['load_test' => true]]);
        $real = User::factory()->create(['email' => 'real@example.test', 'settings' => ['demo_account' => true]]);
        $unmarked = User::factory()->create(['email' => 'demo.unmarked@prohelper.test', 'settings' => []]);
        $previousHashes = [$demo->password, $load->password, $real->password, $unmarked->password];
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_14_020000_retire_seeded_account_credentials.php';
        $migration->up();

        self::assertFalse($demo->fresh()->is_active);
        self::assertFalse($load->fresh()->is_active);
        self::assertNotSame($previousHashes[0], $demo->fresh()->password);
        self::assertNotSame($previousHashes[1], $load->fresh()->password);
        self::assertTrue($real->fresh()->is_active);
        self::assertTrue($unmarked->fresh()->is_active);
        self::assertSame($previousHashes[2], $real->fresh()->password);
        self::assertSame($previousHashes[3], $unmarked->fresh()->password);
    }
}
