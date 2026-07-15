<?php

declare(strict_types=1);

namespace Tests\Unit\Seeders;

use App\Services\Demo\BrickHouseDemoScenarioService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class BrickHouseDemoScenarioUsersContractTest extends TestCase
{
    public function test_cleanup_user_set_exactly_matches_demo_seeder_accounts(): void
    {
        $expected = [
            'demo.contractor@most.test',
            'demo.general-contractor@most.test',
            'demo.gp.accountant@most.test',
            'demo.gp.foreman@most.test',
            'demo.gp.project-manager@most.test',
            'demo.gp.pto@most.test',
            'demo.gp.supply@most.test',
            'demo.sub.accountant@most.test',
            'demo.sub.foreman@most.test',
            'demo.sub.pto@most.test',
            'demo.sub.storekeeper@most.test',
            'demo.sub.work-manager@most.test',
        ];
        $seeder = file_get_contents(dirname(__DIR__, 3).'/database/seeders/BrickHouseDemoSeeder.php');

        self::assertIsString($seeder);
        preg_match_all('/demo\.[a-z0-9.-]+@most\.test/', $seeder, $matches);
        $seeded = array_values(array_unique($matches[0]));
        sort($seeded);

        $cleanup = (new ReflectionClass(BrickHouseDemoScenarioService::class))
            ->getConstant('USER_EMAILS');
        self::assertIsArray($cleanup);
        sort($cleanup);

        self::assertSame($expected, $seeded);
        self::assertSame($expected, $cleanup);
    }
}
