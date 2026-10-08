<?php

declare(strict_types=1);

namespace Tests\Unit\Privacy\Gateway;

use LogicException;
use JsonException;
use Most\PublicCore\GatewayRuntimeBootstrap;
use Most\PublicCore\AppRuntimeBootstrap;
use Most\PublicCore\ProtectedRoleBootstrap;
use App\BusinessModules\Features\AIAssistant\AIAssistantServiceProvider;
use App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreAssistantRuntime;
use App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreBackendAuthorityFence;
use Illuminate\Foundation\Application;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 4).'/docker/public-core/runtime.php';

final class PublicCoreRuntimeBootstrapTest extends TestCase
{
    private function example(): string
    {
        return dirname(__DIR__, 4).'/deploy/public-core-runtime.json.example';
    }

    public function testInactiveManifestCannotStartGateway(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('runtime_not_activated');
        GatewayRuntimeBootstrap::serve($this->example());
    }

    public function testMetadataInspectionCannotQualifyAnActualRuntime(): void
    {
        $metadata = GatewayRuntimeBootstrap::inspect($this->example());
        self::assertSame('inactive', $metadata['configurationState']);
        self::assertFalse($metadata['actualRuntimeVerified']);
        self::assertFalse($metadata['actualModelVerified']);
        self::assertArrayNotHasKey('credentialFile', $metadata);
        self::assertArrayNotHasKey('profile', $metadata);
    }

    public function testMissingManifestIsUnavailableWithoutCreatingIt(): void
    {
        $path = $this->example().'.not-present';
        self::assertSame('unavailable', GatewayRuntimeBootstrap::inspect($path)['configurationState']);
        self::assertFileDoesNotExist($path);
    }

    public function testInvalidJsonDoesNotReachGateway(): void
    {
        $this->expectException(JsonException::class);
        GatewayRuntimeBootstrap::serve(__FILE__);
    }

    public function testRoleIdsStayDistinctAndManifestContainsNoActualProofs(): void
    {
        $configuration = json_decode(file_get_contents($this->example()), true, 64, JSON_THROW_ON_ERROR);
        self::assertNotSame(82, $configuration['gatewayUid']);
        self::assertNotSame(82, $configuration['processorPeer']['uid']);
        self::assertNotSame($configuration['gatewayUid'], $configuration['processorPeer']['uid']);
        self::assertSame(GatewayRuntimeBootstrap::GATEWAY_UID, $configuration['gatewayUid']);
        self::assertSame(GatewayRuntimeBootstrap::GATEWAY_GID, $configuration['gatewayGid']);
        self::assertNull($configuration['profile']);
        self::assertNull($configuration['tokenizerSha256']);
        self::assertNull($configuration['tokenizerPatternSha256']);
        self::assertSame([], $configuration['evidence']);
    }

    private function application(): Application
    {
        $app = new Application(dirname(__DIR__, 4));
        $app->instance('config', new Repository());
        // Register the actual provider without booting its unrelated routes/DB observers.
        (new AIAssistantServiceProvider($app))->register();
        return $app;
    }

    public function testProviderHookDoesNotResolveProvidersOrRuntimeDuringBoot(): void
    {
        $app = $this->application();
        foreach ([PublicCoreAssistantRuntime::class,
            \App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface::class,
            \App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface::class] as $class) {
            self::assertFalse($app->resolved($class));
        }
        $app->boot();
        self::assertFalse($app->resolved(PublicCoreAssistantRuntime::class));
        self::assertFalse($app->resolved(\App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface::class));
        self::assertFalse($app->resolved(\App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface::class));
        self::assertNull((new \ReflectionProperty(PublicCoreAssistantRuntime::class, 'nativePortFactory'))->getValue(
            $app->make(PublicCoreAssistantRuntime::class),
        ));
    }

    public function testMissingInactiveAndUntrustedReadersKeepDefaultBinding(): void
    {
        foreach ([AppRuntimeBootstrap::DEFAULT_BOOTSTRAP.'.absent', $this->example(), __FILE__, 'php://memory'] as $path) {
            $app = $this->application();
            self::assertFalse(AppRuntimeBootstrap::register($app, $path));
            $runtime = $app->make(PublicCoreAssistantRuntime::class);
            self::assertNull((new \ReflectionProperty($runtime, 'nativePortFactory'))->getValue($runtime));
        }
    }

    public function testAfterRegistrationBindingKeepsRequestDynamicAndScopesSeparate(): void
    {
        $app = $this->application();
        $nativeCalls = 0;
        $fence = new PublicCoreBackendAuthorityFence(); // Deliberately unavailable, no DB/provider.
        $app->booted(static function (Application $app) use ($fence, &$nativeCalls): void {
            self::assertTrue(AppRuntimeBootstrap::bind($app, $fence, static function (int $expiry) use (&$nativeCalls): null {
                $nativeCalls++;
                return null;
            }));
        });
        $app->boot();
        $first = Request::create('/public-core-one');
        $second = Request::create('/public-core-two');
        $app->instance('request', $first);
        $runtime = $app->make(PublicCoreAssistantRuntime::class);
        $origin = (new \ReflectionProperty($runtime, 'originSource'))->getValue($runtime);
        self::assertSame($first, $origin());
        $app->instance('request', $second);
        self::assertSame($second, $origin());
        $runtimeFence = (new \ReflectionProperty($runtime, 'sourceFence'))->getValue($runtime);
        self::assertNotSame($fence, $runtimeFence);
        (new \ReflectionProperty($runtimeFence, 'sourceHeld'))->setValue($runtimeFence, ['held' => true]);
        $app->forgetScopedInstances();
        $next = $app->make(PublicCoreAssistantRuntime::class);
        self::assertNotSame($runtime, $next);
        $nextFence = (new \ReflectionProperty($next, 'sourceFence'))->getValue($next);
        self::assertNotSame($runtimeFence, $nextFence);
        self::assertNull((new \ReflectionProperty($nextFence, 'sourceHeld'))->getValue($nextFence));
        self::assertFalse($nextFence->available());
        self::assertSame(0, $nativeCalls);
    }

    public function testAlreadyResolvedRuntimeCannotBeReplacedByLateBootstrap(): void
    {
        $app = $this->application();
        $runtime = $app->make(PublicCoreAssistantRuntime::class);
        self::assertFalse(AppRuntimeBootstrap::bind($app, new PublicCoreBackendAuthorityFence(), static fn (): null => null));
        self::assertSame($runtime, $app->make(PublicCoreAssistantRuntime::class));
    }

    public function testProtectedExecutableCannotBeLoadedFromUntrustedSource(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('runtime_not_activated');
        ProtectedRoleBootstrap::load(__FILE__);
    }

    public function testIncludingBootstrapDoesNotRunCliOrOpenAChannel(): void
    {
        $path = dirname(__DIR__, 4).'/docker/public-core/runtime.php';
        $script = 'require_once '.var_export($path, true).'; echo "included";';
        $process = proc_open([PHP_BINARY, '-r', $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process));
        self::assertSame('included', $stdout);
        self::assertSame('', $stderr);
    }

    public function testRootManagedReaderMustConfigureExactlyOnceAndFailClosed(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Protected root-owned file qualification needs an isolated Linux test runtime.');
        }
        $scratch = getenv('PAPERCLIP_RUN_SCRATCH_DIR');
        self::assertIsString($scratch);
        $directory = $scratch.'/protected-reader-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0750));
        $file = $directory.'/bootstrap.php';
        $fenceClass = '\\'.PublicCoreBackendAuthorityFence::class;
        $valid = '<?php return static function ($app, $configure) { $configure(new '.$fenceClass.'(), static fn (int $expiry) => null); };';
        try {
            foreach (['<?php return null;', '<?php return true;', '<?php invalid syntax',
                '<?php return static function ($app, $configure) {};',
                '<?php return static function ($app, $configure) { $configure(new '.$fenceClass.'(), static fn () => null); $configure(new '.$fenceClass.'(), static fn () => null); };'] as $bytes) {
                file_put_contents($file, $bytes);
                chmod($file, 0640);
                $app = $this->application();
                self::assertFalse(AppRuntimeBootstrap::register($app, $file));
                $runtime = $app->make(PublicCoreAssistantRuntime::class);
                self::assertNull((new \ReflectionProperty($runtime, 'nativePortFactory'))->getValue($runtime));
            }
            file_put_contents($file, $valid);
            chmod($file, 0640);
            $app = $this->application();
            $app->booted(static function (Application $app) use ($file): void {
                self::assertTrue(AppRuntimeBootstrap::register($app, $file));
            });
            $app->boot();
            self::assertInstanceOf(\Closure::class, (new \ReflectionProperty(PublicCoreAssistantRuntime::class, 'nativePortFactory'))->getValue(
                $app->make(PublicCoreAssistantRuntime::class),
            ));
            chmod($file, 0666);
            self::assertFalse(AppRuntimeBootstrap::register($this->application(), $file));
            chmod($file, 0640);
            chmod($directory, 0777);
            self::assertFalse(AppRuntimeBootstrap::register($this->application(), $file));
            chmod($directory, 0750);
            self::assertTrue(symlink($file, $directory.'/linked.php'));
            self::assertFalse(AppRuntimeBootstrap::register($this->application(), $directory.'/linked.php'));
        } finally {
            @unlink($directory.'/linked.php');
            unlink($file);
            rmdir($directory);
        }
    }
    public function testMissingProjectionCannotQualifyAProfileOrStartBuiltinReaders(): void
    {
        $projection = new \Most\PublicCore\RoleProjection('/absent/public-core/role');
        $this->expectException(LogicException::class);
        $projection->profile();
    }

    public function testSemanticGateAcceptsOnlyEvidenceBoundCanonicalText(): void
    {
        $provenance = ['unitRef' => 'unit', 'sourceGenerationRef' => 'generation'];
        $facts = [
            ['kind' => 'text', 'value' => ['utf8Text' => 'Бетон товарный В25 М350'],
                'provenance' => $provenance + ['fragmentVersion' => 'synthetic-material-field/title/1']],
            ['kind' => 'price', 'decimal' => '7800.00', 'currency' => 'RUB', 'perUnit' => 'm3', 'provenance' => $provenance],
        ];
        $value = ['text' => 'Бетон товарный В25 М350 стоит 7800.00 RUB за м³.',
            'claims' => [['value' => '7800.00', 'currency' => 'RUB', 'unit' => 'm3']]];
        $gate = \Most\PublicCore\ProcessorRuntimeBootstrap::semanticVerdict(...);
        self::assertSame(['status' => 'valid', 'reason' => 'none'], $gate($value, [], [['envelope' => ['facts' => $facts]]]));
        foreach (['Чужая цена 1 RUB.', $value['text'].' Частный телефон: +7...'] as $text) {
            self::assertSame('repair', $gate(array_replace($value, ['text' => $text]), [], [['envelope' => ['facts' => $facts]]])['status']);
        }
        $facts[0]['provenance']['unitRef'] = 'other';
        self::assertSame('repair', $gate($value, [], [['envelope' => ['facts' => $facts]]])['status']);
        self::assertSame('repair', $gate($value, [], [])['status']);
    }

    public function testRootCustodyParserCreatesOnlyRoleFilesAndRefusesRotationOrAmbiguity(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Needs isolated root-owned Linux fixture files, not production credentials.');
        }
        $directory = getenv('PAPERCLIP_RUN_SCRATCH_DIR').'/custody-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0755));
        foreach (['app' => 82, 'processor' => 41002, 'gateway' => 41003, 'gateway/credential' => 41003] as $role => $gid) {
            self::assertTrue(mkdir($directory.'/'.$role, 0750));
            self::assertTrue(chgrp($directory.'/'.$role, $gid));
        }
        $file = $directory.'/environment';
        $write = static function (string $bytes) use ($file): void { file_put_contents($file, $bytes); chmod($file, 0600); };
        $provision = \Most\PublicCore\RoleCredentialProvisioner::provision(...);
        try {
            $write("OTHER_KEY=unrelated\n");
            self::assertSame(['providerCredential' => false, 'controlKeys' => false], $provision($file, $directory));
            self::assertFileDoesNotExist($directory.'/app/control-key');
            $write("APP_KEY='".str_repeat('fixture-key-', 4)."'\nTIMEWEB_AI_API_KEY=fixture-provider-value\nTIMEWEB_API_KEY=lower-priority-fixture\n");
            self::assertSame(['providerCredential' => true, 'controlKeys' => true], $provision($file, $directory));
            self::assertSame('fixture-provider-value', file_get_contents($directory.'/gateway/credential/provider-key'));
            self::assertFileDoesNotExist($directory.'/app/provider-key');
            self::assertFileDoesNotExist($directory.'/processor/provider-key');
            self::assertNotSame(file_get_contents($directory.'/app/control-key'), file_get_contents($directory.'/processor/control-key'));
            foreach (['app/control-key' => 82, 'processor/control-key' => 41002, 'gateway/credential/provider-key' => 41003] as $name => $gid) {
                clearstatcache(true, $directory.'/'.$name);
                self::assertSame($gid, filegroup($directory.'/'.$name));
                self::assertSame(0640, fileperms($directory.'/'.$name) & 0777);
            }
            $before = hash_file('sha256', $directory.'/gateway/credential/provider-key');
            foreach (["TIMEWEB_AI_API_KEY=fixture-rotation\n", "TIMEWEB_AI_API_KEY=a\nTIMEWEB_AI_API_KEY=b\n",
                'TIMEWEB_AI_API_KEY=${OTHER_KEY}', "APP_KEY=short\n", 'TIMEWEB_AI_API_KEY="unterminated'] as $invalid) {
                $write($invalid);
                try { $provision($file, $directory); self::fail('Invalid custody input accepted'); }
                catch (\Throwable $failure) { self::assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $failure); }
                self::assertSame($before, hash_file('sha256', $directory.'/gateway/credential/provider-key'));
            }
            $write("TIMEWEB_AI_API_KEY=fixture-provider-value\n"); chmod($file, 0644);
            try { $provision($file, $directory); self::fail('World-readable environment accepted'); }
            catch (LogicException) { self::assertSame($before, hash_file('sha256', $directory.'/gateway/credential/provider-key')); }
        } finally {
            foreach (['app/control-key', 'processor/control-key', 'gateway/credential/provider-key', 'environment'] as $name) { @unlink($directory.'/'.$name); }
            foreach (['gateway/credential', 'gateway', 'processor', 'app'] as $role) { rmdir($directory.'/'.$role); }
            rmdir($directory);
        }
    }

    public function testProtectedProjectionPinsAnInodeAndBytesAndRejectsReplacements(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            self::markTestSkipped('Needs isolated Linux protected file metadata.');
        }
        $directory = getenv('PAPERCLIP_RUN_SCRATCH_DIR').'/projection-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0750));
        $file = $directory.'/profile.json';
        file_put_contents($file, '{"safe":true}'); chmod($file, 0640);
        try {
            $reader = new \Most\PublicCore\ProtectedRoleFile($directory);
            self::assertSame(['safe' => true], $reader->json('profile.json'));
            file_put_contents($directory.'/replacement', '{"safe":true}'); chmod($directory.'/replacement', 0640);
            rename($directory.'/replacement', $file);
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('profile_changed');
            $reader->json('profile.json');
        } finally { unlink($file); rmdir($directory); }
    }

    public function testProcessLifetimeUsesCurrentKernelIdentityAndRejectsGuessedRole(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid')) {
            self::markTestSkipped('Linux proc identity metadata required.');
        }
        $peer = ['pid' => (int)getmypid(), 'uid' => posix_geteuid(), 'gid' => posix_getegid()];
        $reference = \Most\PublicCore\ProcessIdentity::lifetime($peer);
        self::assertMatchesRegularExpression('/\Aref_[0-9a-f]{32}\z/D', $reference);
        self::assertSame($reference, \Most\PublicCore\ProcessIdentity::lifetime($peer));
        $this->expectException(LogicException::class);
        \Most\PublicCore\ProcessIdentity::lifetime(array_replace($peer, ['uid' => $peer['uid'] + 1]));
    }

}
