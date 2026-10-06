<?php

declare(strict_types=1);

namespace Tests\Feature\Privacy\PublicCore;

use App\Services\Privacy\PublicCore\PublicCoreDispatchAuthority;
use App\Services\Privacy\PublicCore\PublicCoreReceiptStore;
use App\Services\Privacy\PublicCore\PublicCoreRuntimeReadiness;
use App\Services\Privacy\PublicCore\RegisteredPublicFixtureRegistry;
use PHPUnit\Framework\TestCase;

final class PublicCoreIsolationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/public-core-isolation-test-' . bin2hex(random_bytes(16));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            if (is_file($file) || is_link($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testClassRoleFlagsAndOfflinePayloadCannotOpenOutbound(): void
    {
        $readiness = new PublicCoreRuntimeReadiness(RegisteredPublicFixtureRegistry::compiled());
        $authority = new PublicCoreDispatchAuthority(new PublicCoreReceiptStore(), $readiness);
        $writes = 0;
        $writer = static function () use (&$writes): void {
            $writes++;
        };
        $payload = ['mode' => 'offline-synthetic', 'transportAllowed' => false, 'is_safe' => true, 'role' => 'processor'];
        self::assertFalse($authority->projectForDispatch($payload, ['status' => 'committed'], (object) ['modelReady' => true])['transportAllowed']);
        self::assertSame('unavailable', $authority->dispatch('app-forged-receipt', $writer)['status']);
        self::assertSame(0, $writes);
        $dto = $readiness->resolve();
        self::assertFalse($dto['model_enabled']);
        self::assertFalse($dto['private_ready']);
        self::assertNull($dto['actual_model']);
        self::assertNull($authority->authority('app-forged-receipt'));
    }

    public function testWrongRoleKeyOrUnsignedAppStateDoesNotCreateAuthority(): void
    {
        $store = new PublicCoreReceiptStore($this->directory, str_repeat('s', 32));
        self::assertSame(['saved' => true], $store->transaction(static function (array &$state): array {
            $state['requests']['owned'] = ['value' => 'registered-public'];
            return ['saved' => true];
        }));
        $wrong = new PublicCoreReceiptStore($this->directory, str_repeat('x', 32));
        self::assertNull($wrong->transaction(static fn (array &$state): array => ['saved' => true]));
        $path = $this->directory . '/authority.json';
        $bytes = file_get_contents($path);
        self::assertIsString($bytes);
        file_put_contents($path, str_replace('registered-public', 'app-forged-public', $bytes));
        self::assertNull($store->transaction(static fn (array &$state): array => ['saved' => true]));
    }

    public function testStableLockFileIsNotReplacedWithTheLedger(): void
    {
        $store = new PublicCoreReceiptStore($this->directory, str_repeat('s', 32));
        $operation = static function (array &$state): array {
            $state['requests']['value'] = count($state['requests']);
            return ['saved' => true];
        };
        self::assertNotNull($store->transaction($operation));
        clearstatcache(true, $this->directory . '/authority.lock');
        $before = stat($this->directory . '/authority.lock');
        self::assertNotNull($store->transaction($operation));
        clearstatcache(true, $this->directory . '/authority.lock');
        $after = stat($this->directory . '/authority.lock');
        self::assertSame($before['ino'], $after['ino']);
        $lock = fopen($this->directory . '/authority.lock', 'c+b');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    public function testMissingRoleStoreAndCallbacksCannotEchoAnAcknowledgement(): void
    {
        $store = new PublicCoreReceiptStore();
        self::assertFalse($store->available());
        foreach (['lineage', 'stage', 'commit', 'abort', 'final_guard', 'begin', 'candidate', 'ack'] as $event) {
            self::assertSame([], $store->publish($event, ['is_safe' => true], ['status' => 'committed']));
        }
        self::assertNull($store->authority('anything'));
    }

    public function testOtherProcessCannotWriteWhileTheAuthorityFenceIsHeld(): void
    {
        $store = new PublicCoreReceiptStore($this->directory, str_repeat('s', 32));
        self::assertNotNull($store->transaction(static fn (array &$state): array => ['initialized' => true]));
        $probe = $this->directory . '/lock-probe.php';
        file_put_contents($probe, '<?php $file=fopen($argv[1],"c+b"); $locked=flock($file,LOCK_EX|LOCK_NB); echo $locked?"acquired":"blocked"; if($locked){flock($file,LOCK_UN);} fclose($file); exit($locked?1:0);');
        $result = $store->transaction(function (array &$state) use ($probe): array {
            $process = proc_open([PHP_BINARY, $probe, $this->directory . '/authority.lock'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process));
            self::assertSame('blocked', $output);
            self::assertSame('', $error);
            return ['fenced' => true];
        });
        self::assertSame(['fenced' => true], $result);
    }
}
