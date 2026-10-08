<?php

declare(strict_types=1);

namespace Most\PublicCore;

use App\Services\Privacy\Gateway\GatewayPublicCoreTransport;
use App\Services\Privacy\PublicCore\Transport\AuthenticatedPublicCoreChannel;
use LogicException;
use Throwable;

/** Gateway entrypoint; metadata inspection never opens a socket or reads a key. */
final class GatewayRuntimeBootstrap
{
    public const GATEWAY_UID = 41003;

    public const GATEWAY_GID = 41003;

    public const DEFAULT_CONFIGURATION = '/etc/most/public-core/gateway/runtime.json';

    private const SCHEMA = 'public-core-gateway-runtime/1';

    public static function configurationState(string $path): string
    {
        // Never accept a symlink or an oversized file as a runtime manifest.
        $stat = @lstat($path);
        if (! is_array($stat) || ($stat['mode'] & 0170000) !== 0100000
            || $stat['size'] < 1 || $stat['size'] > 65536) {
            throw new LogicException('runtime_not_activated');
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new LogicException('runtime_not_activated');
        }
        try {
            $opened = fstat($stream);
            if (! is_array($opened) || $opened['dev'] !== $stat['dev'] || $opened['ino'] !== $stat['ino']) {
                throw new LogicException('runtime_not_activated');
            }
            $bytes = stream_get_contents($stream, 65537);
            if (! is_string($bytes) || strlen($bytes) !== $stat['size']) {
                throw new LogicException('runtime_not_activated');
            }
            $configuration = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($configuration) || ($configuration['schemaVersion'] ?? null) !== self::SCHEMA
                || ! in_array($configuration['activation'] ?? null, ['inactive', 'approved'], true)) {
                throw new LogicException('runtime_not_activated');
            }

            // This is manifest metadata, never proof of actual runtime readiness.
            return $configuration['activation'];
        } finally {
            fclose($stream);
        }
    }

    /** @return array<string, bool|string> */
    public static function inspect(string $path): array
    {
        $state = 'unavailable';
        try {
            $state = self::configurationState($path);
        } catch (Throwable) {
        }

        return [
            'schemaVersion' => 'public-core-bootstrap-metadata/1',
            'configurationState' => $state,
            'actualRuntimeVerified' => false,
            'actualModelVerified' => false,
            'nativeChannelAvailable' => AuthenticatedPublicCoreChannel::isNativeAvailable(),
            'curlAvailable' => extension_loaded('curl'),
            'tokenizerImplementationAvailable' => class_exists(\Yethee\Tiktoken\Encoder\NativeEncoder::class),
            'gatewayIdentityMatches' => function_exists('posix_geteuid') && function_exists('posix_getegid')
                && posix_geteuid() === self::GATEWAY_UID && posix_getegid() === self::GATEWAY_GID,
        ];
    }

    public static function serve(string $path): void
    {
        if (self::configurationState($path) !== 'approved') {
            throw new LogicException('runtime_not_activated');
        }
        if (! AuthenticatedPublicCoreChannel::isNativeAvailable()
            || posix_geteuid() !== self::GATEWAY_UID || posix_getegid() !== self::GATEWAY_GID) {
            throw new LogicException('gateway_identity_unavailable');
        }

        // The original server validates root-owned config/key/evidence, actual
        // profile, tokenizer digests and authenticated Processor identity.
        // It remains the sole owner of credential loading and outbound writes.
        GatewayPublicCoreTransport::serveProtected($path);
    }
}

/** Only root-managed role code may supply the accepted constructor inputs. */
final class ProtectedRoleBootstrap
{
    public static function load(string $path): mixed
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! function_exists('posix_getegid')
            || ! str_starts_with($path, '/') || str_contains($path, "\0") || realpath($path) !== $path) {
            throw new LogicException('runtime_not_activated');
        }
        $parent = @lstat(dirname($path));
        $stat = @lstat($path);
        if (! is_array($parent) || ($parent['mode'] & 0170000) !== 0040000
            || $parent['uid'] !== 0 || $parent['gid'] !== posix_getegid() || ($parent['mode'] & 0027) !== 0
            || ! is_array($stat) || ($stat['mode'] & 0170000) !== 0100000
            || $stat['uid'] !== 0 || $stat['gid'] !== posix_getegid() || ($stat['mode'] & 0037) !== 0
            || $stat['size'] < 1 || $stat['size'] > 65536) {
            throw new LogicException('runtime_not_activated');
        }
        for ($ancestor = dirname(dirname($path)); ; $ancestor = dirname($ancestor)) {
            $directory = @lstat($ancestor);
            if (! is_array($directory) || ($directory['mode'] & 0170000) !== 0040000
                || $directory['uid'] !== 0 || ($directory['mode'] & 0022) !== 0) {
                throw new LogicException('runtime_not_activated');
            }
            if ($ancestor === '/') { break; }
        }
        // Parent and file are immutable to the role; root is the trust boundary.
        // Never eval manifest bytes or load executable code from App storage.
        return (static fn (string $file): mixed => require $file)($path);
    }
}

final class AppRuntimeBootstrap
{
    public const DEFAULT_BOOTSTRAP = '/etc/most/public-core/app/bootstrap.php';

    public static function register(\Illuminate\Foundation\Application $app,
        string $path = self::DEFAULT_BOOTSTRAP): bool
    {
        if ($app->resolved(\App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreAssistantRuntime::class)) {
            return false;
        }
        try {
            $bootstrap = ProtectedRoleBootstrap::load($path);
            if (! $bootstrap instanceof \Closure) {
                return false; // Missing/inactive readers preserve the unavailable binding.
            }
            $inputs = null;
            $calls = 0;
            $bootstrap($app, static function (
                \App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreBackendAuthorityFence $fence,
                \Closure $nativePortFactory,
            ) use (&$inputs, &$calls): void {
                if (++$calls !== 1 || get_class($fence) !== \App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreBackendAuthorityFence::class) {
                    throw new LogicException('runtime_not_activated');
                }
                $inputs = [$fence, $nativePortFactory];
            });
            if ($calls !== 1 || $inputs === null) {
                return false;
            }
            return self::bind($app, $inputs[0], $inputs[1]);
        } catch (Throwable) {
            return false;
        }
    }

    /** Trusted PHP startup supplies the existing fence and bounded native factory. */
    public static function bind(\Illuminate\Foundation\Application $app,
        \App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreBackendAuthorityFence $fence,
        \Closure $nativePortFactory): bool
    {
        $runtimeClass = \App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreAssistantRuntime::class;
        if ($app->resolved($runtimeClass)) {
            return false;
        }
        // Retain a pristine fence; mutable upload state must not cross request scopes.
        $pristineFence = clone $fence;
        $origin = static function () use ($app): \Illuminate\Http\Request {
            $request = $app->make('request');
            if (! $request instanceof \Illuminate\Http\Request) {
                throw new LogicException('authorization_changed');
            }
            return $request;
        };
        $app->scoped($runtimeClass, static fn () => new \App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreAssistantRuntime(
            clone $pristineFence, $nativePortFactory, $origin,
        ));
        return true;
    }
}

final class ProcessorRuntimeBootstrap
{
    public const DEFAULT_BOOTSTRAP = '/etc/most/public-core/processor/bootstrap.php';

    public static function serve(string $path): void
    {
        if (! AuthenticatedPublicCoreChannel::isNativeAvailable()
            || posix_geteuid() !== 41002 || posix_getegid() !== 41002) {
            throw new LogicException('gateway_identity_unavailable');
        }
        $serve = ProtectedRoleBootstrap::load($path);
        if (! $serve instanceof \Closure) {
            throw new LogicException('runtime_not_activated');
        }
        // The root-managed closure owns nativeProcessor + persistent ledger and
        // listen/accept/serveAppChannel lifetime, exactly as the source handoff.
        // No local fixture/model/profile readiness is inferred here.
        $serve();
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $command = $argv[1] ?? '--help';
    if ($command === '--help') {
        fwrite(STDOUT, "Usage: php docker/public-core/runtime.php metadata|gateway|processor [protected-role-config]\n");
        exit(0);
    }
    if (! in_array($command, ['metadata', 'gateway', 'processor'], true) || count($argv) > 3) {
        fwrite(STDERR, "public-core: invalid_command\n");
        exit(64);
    }
    try {
        require_once dirname(__DIR__, 2).'/vendor/autoload.php';
        $path = $argv[2] ?? ($command === 'processor' ? ProcessorRuntimeBootstrap::DEFAULT_BOOTSTRAP : GatewayRuntimeBootstrap::DEFAULT_CONFIGURATION);
        if ($command === 'metadata') {
            fwrite(STDOUT, json_encode(GatewayRuntimeBootstrap::inspect($path), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
            exit(0);
        }
        if ($command === 'processor') {
            ProcessorRuntimeBootstrap::serve($path);
        } else {
            GatewayRuntimeBootstrap::serve($path);
        }
        exit(0);
    } catch (Throwable) {
        // Keep paths, provider/config payloads and exception text out of logs.
        fwrite(STDERR, "public-core: runtime_unavailable\n");
        exit(78);
    }
}
