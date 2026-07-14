<?php

declare(strict_types=1);

namespace Tests\Unit\Broadcasting;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class BroadcastTopologyTest extends TestCase
{
    public function test_only_interface_specific_auth_routes_are_registered(): void
    {
        $root = dirname(__DIR__, 3);
        $bootstrap = file_get_contents($root.'/bootstrap/app.php');
        $provider = file_get_contents($root.'/app/Providers/BroadcastServiceProvider.php');
        $channels = file_get_contents($root.'/routes/channels.php');

        self::assertIsString($bootstrap);
        self::assertIsString($provider);
        self::assertIsString($channels);
        self::assertStringNotContainsString("channels: __DIR__.'/../routes/channels.php'", $bootstrap);
        self::assertSame(2, substr_count($provider, 'Broadcast::routes(['));
        self::assertStringContainsString('UserChannel::class', $channels);
    }

    public function test_contract_events_are_domain_events_instead_of_duplicate_broadcasts(): void
    {
        require_once dirname(__DIR__, 3).'/app/Events/ContractStatusChanged.php';
        require_once dirname(__DIR__, 3).'/app/Events/ContractLimitWarning.php';

        self::assertFalse(is_subclass_of(\App\Events\ContractStatusChanged::class, ShouldBroadcast::class));
        self::assertFalse(is_subclass_of(\App\Events\ContractLimitWarning::class, ShouldBroadcast::class));
        self::assertTrue((new ReflectionMethod(\App\Events\ContractLimitWarning::class, 'getWarningLevel'))->isPublic());
        self::assertTrue((new ReflectionMethod(\App\Events\ContractLimitWarning::class, 'getWarningMessage'))->isPublic());
    }
}
