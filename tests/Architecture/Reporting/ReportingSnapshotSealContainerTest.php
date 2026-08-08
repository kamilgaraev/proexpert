<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportSnapshotSealStore;
use App\BusinessModules\Core\Reporting\Infrastructure\Persistence\EloquentReportSnapshotSealStore;
use App\BusinessModules\Core\Reporting\ReportingContractsServiceProvider;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

final class ReportingSnapshotSealContainerTest extends TestCase
{
    public function test_registered_contracts_resolve_the_persistent_snapshot_seal_store(): void
    {
        require_once dirname(__DIR__, 3).'/app/BusinessModules/Core/Reporting/ReportingContractsServiceProvider.php';

        $app = new Application(dirname(__DIR__, 3));
        $app->instance('config', new ConfigRepository([
            'reporting_execution' => [
                'active_seal' => [
                    'private_key' => rtrim(strtr(base64_encode(
                        sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()),
                    ), '+/', '-_'), '='),
                    'key_id' => 'test-key-v1',
                ],
            ],
        ]));
        (new ReportingContractsServiceProvider($app))->register();

        self::assertInstanceOf(
            EloquentReportSnapshotSealStore::class,
            $app->make(ReportSnapshotSealStore::class),
        );
    }
}
