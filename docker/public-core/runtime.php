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

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $command = $argv[1] ?? '--help';
    if ($command === '--help') {
        fwrite(STDOUT, "Usage: php docker/public-core/runtime.php metadata|gateway [nonsecret-runtime.json]\n");
        exit(0);
    }
    if (! in_array($command, ['metadata', 'gateway'], true) || count($argv) > 3) {
        fwrite(STDERR, "public-core: invalid_command\n");
        exit(64);
    }
    try {
        require_once dirname(__DIR__, 2).'/vendor/autoload.php';
        $path = $argv[2] ?? GatewayRuntimeBootstrap::DEFAULT_CONFIGURATION;
        if ($command === 'metadata') {
            fwrite(STDOUT, json_encode(GatewayRuntimeBootstrap::inspect($path), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
            exit(0);
        }
        GatewayRuntimeBootstrap::serve($path);
        exit(0);
    } catch (Throwable) {
        // Keep paths, provider/config payloads and exception text out of logs.
        fwrite(STDERR, "public-core: runtime_unavailable\n");
        exit(78);
    }
}
