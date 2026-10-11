<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\Rag\RagCoverageProjectionLease;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RagCoverageProjectionLeaseTest extends TestCase
{
    private mixed $previousApplication;
    private Repository $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApplication = Facade::getFacadeApplication();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(new Container);
        $this->cache = new Repository(new ArrayStore);
        Cache::swap($this->cache);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousApplication);
        parent::tearDown();
    }

    public function test_current_revision_can_replace_a_known_stale_owner_and_old_release_cannot_delete_it(): void
    {
        $old = Cache::lock('projection', 7500, 'old-worker');
        self::assertTrue(RagCoverageProjectionLease::acquire($old, 'projection', 4, 7500));
        $current = Cache::lock('projection', 7500, 'current-worker');
        self::assertTrue(RagCoverageProjectionLease::acquire($current, 'projection', 5, 7500));
        self::assertFalse($old->release());
        self::assertTrue($current->isOwnedByCurrentProcess());
        self::assertFalse(RagCoverageProjectionLease::acquire(Cache::lock('projection', 7500), 'projection', 5, 7500));
        self::assertTrue($current->release());
    }

    public function test_unknown_legacy_owner_is_preserved_until_natural_expiration(): void
    {
        $legacy = Cache::lock('projection', 7500, 'legacy-worker');
        self::assertTrue($legacy->get());
        self::assertFalse(RagCoverageProjectionLease::acquire(Cache::lock('projection', 7500), 'projection', 5, 7500));
        self::assertTrue($legacy->isOwnedByCurrentProcess());
    }

    #[DataProvider('unsafeOwnership')]
    public function test_unproven_ownership_cannot_unlock_an_active_lease(mixed $metadata): void
    {
        $owner = Cache::lock('projection', 7500, 'actual-worker');
        self::assertTrue($owner->get());
        Cache::put('projection:revision-owner', $metadata, 7500);
        self::assertFalse(RagCoverageProjectionLease::acquire(Cache::lock('projection', 7500), 'projection', 5, 7500));
        self::assertTrue($owner->isOwnedByCurrentProcess());
    }

    public static function unsafeOwnership(): array
    {
        return [[null], [['revision' => 4, 'owner' => 'wrong-worker']], [['revision' => 5, 'owner' => 'actual-worker']],
            [['revision' => 6, 'owner' => 'actual-worker']], [['revision' => '4', 'owner' => 'actual-worker']],
            [['revision' => -1, 'owner' => 'actual-worker']], [['revision' => 4, 'owner' => '']]];
    }

    public function test_optional_metadata_storage_failure_does_not_break_normal_acquisition(): void
    {
        $cache = Mockery::mock($this->cache)->makePartial();
        $cache->shouldReceive('put')->once()->andThrow(new RuntimeException('private_storage_failure_fixture'));
        Cache::swap($cache);
        $lease = Cache::lock('projection', 7500, 'current-worker');
        self::assertTrue(RagCoverageProjectionLease::acquire($lease, 'projection', 5, 7500));
        self::assertTrue($lease->isOwnedByCurrentProcess());
        self::assertTrue($lease->release());
    }
}
