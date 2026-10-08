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
        ParkedRoleBootstrap::assertReleased('gateway');
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

/** A pinned role projection: every use rechecks bytes, inode and protected parents. */
final class ProtectedRoleFile
{
    /** @var array<string, string> */
    private array $pins = [];

    public function __construct(private readonly string $directory, private readonly ?int $expectedGid = null) {}

    public function bytes(string $name, int $limit = 65536): string
    {
        if (preg_match('/\A[A-Za-z0-9_.-]+\z/D', $name) !== 1 || $name === '.' || $name === '..') {
            throw new LogicException('runtime_not_activated');
        }
        $path = $this->directory.'/'.$name;
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_getegid') || realpath($path) !== $path) {
            throw new LogicException('runtime_not_activated');
        }
        clearstatcache(true, $path);
        $before = @lstat($path);
        $parent = @lstat($this->directory);
        if (!is_array($before) || !is_array($parent) || ($before['mode'] & 0170000) !== 0100000
            || $before['uid'] !== 0 || $before['gid'] !== ($this->expectedGid ?? posix_getegid()) || ($before['mode'] & 0037) !== 0
            || ($parent['mode'] & 0170000) !== 0040000 || $parent['uid'] !== 0
            || $parent['gid'] !== ($this->expectedGid ?? posix_getegid()) || ($parent['mode'] & 0027) !== 0
            || $before['size'] < 1 || $before['size'] > $limit) {
            throw new LogicException('runtime_not_activated');
        }
        for ($ancestor = dirname($this->directory); ; $ancestor = dirname($ancestor)) {
            $stat = @lstat($ancestor);
            if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0040000 || $stat['uid'] !== 0
                || ($stat['mode'] & 0022) !== 0) { throw new LogicException('runtime_not_activated'); }
            if ($ancestor === '/') { break; }
        }
        $stream = @fopen($path, 'rb');
        if ($stream === false) { throw new LogicException('runtime_not_activated'); }
        try {
            $opened = fstat($stream);
            $bytes = stream_get_contents($stream, $limit + 1);
            clearstatcache(true, $path);
            $after = @lstat($path);
            if (!is_array($opened) || !is_array($after) || !is_string($bytes) || strlen($bytes) !== $before['size']) {
                throw new LogicException('runtime_not_activated');
            }
            foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $field) {
                if ($before[$field] !== $opened[$field] || $opened[$field] !== $after[$field]) {
                    throw new LogicException('runtime_not_activated');
                }
            }
            $pin = hash('sha256', json_encode([$before['dev'], $before['ino'], $before['mode'],
                $before['uid'], $before['gid'], $bytes], JSON_THROW_ON_ERROR));
            if (isset($this->pins[$name]) && !hash_equals($this->pins[$name], $pin)) {
                throw new LogicException('profile_changed');
            }
            $this->pins[$name] = $pin;
            return $bytes;
        } finally { fclose($stream); }
    }

    /** @return array<mixed> */
    public function json(string $name): array
    {
        $value = json_decode($this->bytes($name), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value)) { throw new LogicException('runtime_not_activated'); }
        return $value;
    }
}

/** Only existing profile/proof schemas are consumed; no proof is synthesized here. */
final class RoleProjection
{
    private ProtectedRoleFile $files;
    /** @var array<string, string> */
    private array $lifetimes = [];

    public function __construct(private readonly string $directory) { $this->files = new ProtectedRoleFile($directory); }

    public function files(): ProtectedRoleFile { return $this->files; }

    public function profile(): \App\Services\Privacy\Gateway\Contracts\GatewayModelProfile
    {
        $generation = $this->files->json('generation.json');
        $request = \App\Services\Privacy\Gateway\Contracts\GatewayModelRequest::class;
        if (!$request::hasExactKeys($generation, ['schemaVersion', 'releaseSha', 'imageDigest', 'expiresAt', 'profileFingerprint', 'files', 'roleLifetimes'])
            || $generation['schemaVersion'] !== 'public-core-projection-generation/1' || !is_int($generation['expiresAt'])
            || $generation['expiresAt'] <= time() || !is_array($generation['files'])
            || !$request::hasExactKeys($generation['roleLifetimes'], ['processor', 'gateway'])) { throw new LogicException('runtime_not_activated'); }
        $names = ['profile.json', 'catalog.json', 'method.json', 'capacity.json', 'tokenizer.json', 'key.json', 'identity.json',
            'channel.json', 'egress.json', 'backendAuthority.json', 'nativeTransfer.json', 'gateway-peer.json'];
        $names = array_merge($names, basename($this->directory) === 'app' ? ['processor-peer.json'] : ['qualification.json', 'vocabulary.tiktoken', 'pattern.txt']);
        if (!$request::hasExactKeys($generation['files'], $names)
            || preg_match('/\A[0-9a-f]{40}\z/D', $generation['releaseSha'] ?? '') !== 1
            || preg_match('/\Asha256:[0-9a-f]{64}\z/D', $generation['imageDigest'] ?? '') !== 1) { throw new LogicException('runtime_not_activated'); }
        foreach ($generation['files'] as $name => $digest) {
            if (!is_string($name) || !$request::isDigest($digest) || hash('sha256', $this->files->bytes($name, 16777216)) !== $digest) {
                throw new LogicException('receipt_changed');
            }
        }
        $profile = \App\Services\Privacy\Gateway\Contracts\GatewayModelProfile::fromArray($this->files->json('profile.json'));
        if ($generation['profileFingerprint'] !== $profile->fingerprint()) { throw new LogicException('profile_changed'); }
        if (!$profile->isActualProfile()) { throw new LogicException('model_profile_unqualified'); }
        $kinds = ['catalog', 'method', 'capacity', 'tokenizer', 'key', 'identity', 'channel', 'egress', 'backendAuthority', 'nativeTransfer'];
        $proofs = [];
        foreach ($kinds as $kind) { $proofs[$kind] = $this->evidence($kind, $profile); }
        $v = $profile->values();
        if ($proofs['method']['ref'] !== $v['capabilityEvidenceRef'] || $proofs['capacity']['ref'] !== $v['capacityEvidenceRef']
            || $proofs['tokenizer']['ref'] !== $v['mappingEvidenceRef']
            || ($proofs['method']['details']['endpoint'] ?? null) !== \App\Services\Privacy\Gateway\GatewayPublicCoreHttpSender::ENDPOINT
            || ($proofs['method']['details']['templateVersion'] ?? null) !== 'chat-completions-action/1'
            || ($proofs['catalog']['details']['modelRevision'] ?? null) !== $v['modelRevision']
            || !is_int($proofs['capacity']['details']['contextWindow'] ?? null)
            || $v['contextWindow'] > $proofs['capacity']['details']['contextWindow']
            || !is_int($proofs['capacity']['details']['maxOutputTokens'] ?? null)
            || $v['maxOutputTokens'] > $proofs['capacity']['details']['maxOutputTokens']
            || ($proofs['egress']['details']['allowedEndpoint'] ?? null) !== \App\Services\Privacy\Gateway\GatewayPublicCoreHttpSender::ENDPOINT
            || !\App\Services\Privacy\Gateway\Contracts\GatewayModelRequest::isDigest($proofs['egress']['details']['policyDigest'] ?? null)) {
            throw new LogicException('runtime_not_activated');
        }
        return $profile;
    }

    /** @return array<mixed> */
    public function evidence(string $kind, \App\Services\Privacy\Gateway\Contracts\GatewayModelProfile $profile): array
    {
        $proof = $this->files->json($kind.'.json');
        $request = \App\Services\Privacy\Gateway\Contracts\GatewayModelRequest::class;
        if (!$request::hasExactKeys($proof, ['schemaVersion', 'kind', 'ref', 'status', 'profileFingerprint',
            'modelId', 'apiMethod', 'issuedAt', 'expiresAt', 'details'])
            || $proof['schemaVersion'] !== 'public-core-runtime-evidence/1' || $proof['kind'] !== $kind
            || !$request::isReference($proof['ref']) || $proof['status'] !== 'verified'
            || $proof['profileFingerprint'] !== $profile->fingerprint() || $proof['modelId'] !== $profile->values()['modelId']
            || $proof['apiMethod'] !== $profile->values()['apiMethod'] || !is_int($proof['issuedAt'])
            || !is_int($proof['expiresAt']) || $proof['issuedAt'] < 1 || $proof['issuedAt'] > time()
            || $proof['expiresAt'] <= time() || !is_array($proof['details'])) {
            throw new LogicException('runtime_not_activated');
        }
        return $proof;
    }

    /** @return array{pid: int, uid: int, gid: int} */
    public function peer(string $role): array
    {
        $peer = $this->files->json($role.'-peer.json');
        $uid = match ($role) { 'processor' => 41002, 'gateway' => 41003, default => throw new LogicException('gateway_identity_unavailable') };
        if (!\App\Services\Privacy\Gateway\Contracts\GatewayModelRequest::hasExactKeys($peer, ['pid', 'uid', 'gid'])
            || !is_int($peer['pid']) || $peer['pid'] < 1 || $peer['uid'] !== $uid || $peer['gid'] !== $uid) {
            throw new LogicException('gateway_identity_unavailable');
        }
        $lifetime = ProcessIdentity::lifetime($peer);
        if (($this->files->json('generation.json')['roleLifetimes'][$role] ?? null) !== $lifetime) { throw new LogicException('gateway_identity_unavailable'); }
        if (isset($this->lifetimes[$role]) && $this->lifetimes[$role] !== $lifetime) {
            throw new LogicException('gateway_identity_unavailable');
        }
        $this->lifetimes[$role] = $lifetime;
        return $peer;
    }

    /** @return array<mixed> */
    public function qualification(\App\Services\Privacy\Gateway\Contracts\GatewayModelProfile $profile): array
    {
        if ($this->profile()->fingerprint() !== $profile->fingerprint()) { throw new LogicException('profile_changed'); }
        return $this->files->json('qualification.json'); // PublicCoreRuntimeReadiness validates the exact schema/refs.
    }

    public function encoder(\App\Services\Privacy\Gateway\Contracts\GatewayModelProfile $profile): \Closure
    {
        $proof = $this->evidence('tokenizer', $profile);
        $details = $proof['details'];
        $v = $profile->values();
        $vocabulary = $this->files->bytes('vocabulary.tiktoken', 16777216);
        $pattern = $this->files->bytes('pattern.txt', 32768);
        if (($details['countMethod'] ?? null) !== 'full_wire_json_bpe_upper_bound'
            || ($details['tokenizerId'] ?? null) !== $v['tokenizerId']
            || ($details['tokenizerRevision'] ?? null) !== $v['tokenizerRevision']
            || ($details['modelRevision'] ?? null) !== $v['modelRevision'] || $proof['ref'] !== $v['mappingEvidenceRef']
            || ($details['vocabularySha256'] ?? null) !== hash('sha256', $vocabulary)
            || ($details['patternSha256'] ?? null) !== hash('sha256', $pattern)) {
            throw new LogicException('tokenizer_unqualified');
        }
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) { throw new LogicException('tokenizer_unqualified'); }
        try {
            fwrite($stream, $vocabulary); rewind($stream);
            $encoder = new \Yethee\Tiktoken\Encoder\NativeEncoder($v['tokenizerId'], \Yethee\Tiktoken\Vocab\Vocab::fromStream($stream), $pattern);
        } finally { fclose($stream); }
        return function (string $bytes, \App\Services\Privacy\Gateway\Contracts\GatewayModelProfile $selected) use ($encoder, $profile): array {
            if ($selected->fingerprint() !== $profile->fingerprint() || $this->profile()->fingerprint() !== $profile->fingerprint()) {
                throw new LogicException('profile_changed');
            }
            // Pin the BPE files for every count, not only construction.
            $this->files->bytes('vocabulary.tiktoken', 16777216); $this->files->bytes('pattern.txt', 32768);
            $v = $selected->values();
            return ['inputTokens' => count($encoder->encode($bytes)), 'tokenizerId' => $v['tokenizerId'],
                'tokenizerRevision' => $v['tokenizerRevision'], 'mappingEvidenceRef' => $v['mappingEvidenceRef']];
        };
    }
}

/** Root compiler parity with the immutable Gateway validator; never opens a listener/key. */
final class GatewayProjectionSnapshot
{
    /** @var array<string, ProtectedRoleFile> */
    private array $readers = [];
    /** @var array<string, string> */
    private array $inputs = [];
    /** @var array<string, array<mixed>> */
    private array $metadata = [];

    public function __construct(private readonly string $gatewayDirectory) {}

    public function bytes(string $path, int $limit): string
    {
        if (!str_starts_with($path, $this->gatewayDirectory.'/') || realpath($path) !== $path) {
            throw new LogicException('runtime_not_activated');
        }
        $directory = dirname($path);
        $this->readers[$directory] ??= new ProtectedRoleFile($directory, 41003);
        $bytes = $this->readers[$directory]->bytes(basename($path), $limit);
        $this->inputs[$path] = $bytes;
        return $bytes;
    }

    public function recheck(): void
    {
        foreach ($this->metadata as $path => $before) {
            clearstatcache(true, $path); $after = @lstat($path);
            if (!is_array($after)) { throw new LogicException('runtime_not_activated'); }
            foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $field) {
                if ($before[$field] !== $after[$field]) { throw new LogicException('runtime_not_activated'); }
            }
        }
        foreach ($this->inputs as $path => $bytes) {
            if ($this->bytes($path, max(strlen($bytes), 1)) !== $bytes) { throw new LogicException('profile_changed'); }
        }
    }

    public function configuration(string $path): array
    {
        $configuration = json_decode($this->bytes($path, 65536), true, 64, JSON_THROW_ON_ERROR);
        if (! \App\Services\Privacy\Gateway\Contracts\GatewayModelRequest::hasExactKeys($configuration, [
            'schemaVersion', 'activation', 'gatewayUid', 'gatewayGid', 'processorPeer', 'socketPath',
            'profile', 'evidenceDirectory', 'evidence', 'credentialFile', 'tokenizerFile',
            'tokenizerSha256', 'tokenizerPattern', 'tokenizerPatternSha256', 'tokenizerVocabulary',
            'deadlineMs', 'maxRequests',
        ]) || $configuration['schemaVersion'] !== 'public-core-gateway-runtime/1'
            || $configuration['activation'] !== 'approved'
            || $configuration['gatewayUid'] !== GatewayRuntimeBootstrap::GATEWAY_UID
            || $configuration['gatewayGid'] !== GatewayRuntimeBootstrap::GATEWAY_GID
            || ! \App\Services\Privacy\Gateway\Contracts\GatewayModelRequest::hasExactKeys($configuration['processorPeer'], ['uid', 'gid', 'pid'])
            || ! is_int($configuration['processorPeer']['uid']) || $configuration['processorPeer']['uid'] < 1
            || $configuration['processorPeer']['uid'] === $configuration['gatewayUid']
            || ! is_int($configuration['processorPeer']['gid']) || $configuration['processorPeer']['gid'] < 1
            || ($configuration['processorPeer']['pid'] !== null && (! is_int($configuration['processorPeer']['pid']) || $configuration['processorPeer']['pid'] < 1))
            || ! is_int($configuration['deadlineMs']) || $configuration['deadlineMs'] < 12000 || $configuration['deadlineMs'] > 30000
            || ! is_int($configuration['maxRequests']) || $configuration['maxRequests'] < 1 || $configuration['maxRequests'] > 128) {
            throw new LogicException('runtime_not_activated');
        }
        $profile = \App\Services\Privacy\Gateway\Contracts\GatewayModelProfile::fromArray($configuration['profile']);
        if (! $profile->isActualProfile() || ! \App\Services\Privacy\Gateway\Contracts\GatewayModelRequest::isDigest($configuration['tokenizerSha256'])
            || ! \App\Services\Privacy\Gateway\Contracts\GatewayModelRequest::isDigest($configuration['tokenizerPatternSha256'])
            || ! is_string($configuration['tokenizerVocabulary']) || preg_match('/\A[A-Za-z0-9_.:-]{1,128}\z/D', $configuration['tokenizerVocabulary']) !== 1) {
            throw new LogicException('model_profile_unqualified');
        }
        foreach (['socketPath', 'evidenceDirectory', 'credentialFile', 'tokenizerFile', 'tokenizerPattern'] as $key) {
            if (! is_string($configuration[$key]) || ! str_starts_with($configuration[$key], '/') || str_contains($configuration[$key], "\0")) {
                throw new LogicException('runtime_not_activated');
            }
        }
        $kinds = ['catalog', 'method', 'capacity', 'tokenizer', 'key', 'identity', 'channel', 'egress', 'backendAuthority', 'nativeTransfer'];
        if (! \App\Services\Privacy\Gateway\Contracts\GatewayModelRequest::hasExactKeys($configuration['evidence'], $kinds)) {
            throw new LogicException('runtime_not_activated');
        }
        $proofs = [];
        foreach ($kinds as $kind) {
            $reference = $configuration['evidence'][$kind];
            if (! \App\Services\Privacy\Gateway\Contracts\GatewayModelRequest::hasExactKeys($reference, ['ref', 'file', 'sha256'])
                || ! \App\Services\Privacy\Gateway\Contracts\GatewayModelRequest::isReference($reference['ref']) || ! \App\Services\Privacy\Gateway\Contracts\GatewayModelRequest::isDigest($reference['sha256'])
                || ! is_string($reference['file']) || preg_match('/\A[A-Za-z0-9_-]{1,128}\.json\z/D', $reference['file']) !== 1) {
                throw new LogicException('runtime_not_activated');
            }
            $bytes = $this->bytes($configuration['evidenceDirectory'].'/'.$reference['file'], 65536);
            if (! hash_equals($reference['sha256'], hash('sha256', $bytes))) {
                throw new LogicException('runtime_not_activated');
            }
            $proof = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
            if (! \App\Services\Privacy\Gateway\Contracts\GatewayModelRequest::hasExactKeys($proof, [
                'schemaVersion', 'kind', 'ref', 'status', 'profileFingerprint', 'modelId', 'apiMethod', 'issuedAt', 'expiresAt', 'details',
            ]) || $proof['schemaVersion'] !== 'public-core-runtime-evidence/1'
                || $proof['kind'] !== $kind || $proof['ref'] !== $reference['ref'] || $proof['status'] !== 'verified'
                || $proof['profileFingerprint'] !== $profile->fingerprint() || $proof['modelId'] !== $profile->values()['modelId']
                || $proof['apiMethod'] !== $profile->values()['apiMethod'] || ! is_int($proof['issuedAt']) || $proof['issuedAt'] < 1
                || ! is_int($proof['expiresAt']) || $proof['expiresAt'] <= $proof['issuedAt']
                || $proof['issuedAt'] > time() || $proof['expiresAt'] <= time()
                || ! is_array($proof['details'])) {
                throw new LogicException('runtime_not_activated');
            }
            $proofs[$kind] = $proof['details'];
        }
        $settings = $profile->values();
        if ($configuration['evidence']['method']['ref'] !== $settings['capabilityEvidenceRef']
            || $configuration['evidence']['capacity']['ref'] !== $settings['capacityEvidenceRef']
            || $configuration['evidence']['tokenizer']['ref'] !== $settings['mappingEvidenceRef']
            || ($proofs['method']['endpoint'] ?? null) !== \App\Services\Privacy\Gateway\GatewayPublicCoreHttpSender::ENDPOINT
            || ($proofs['method']['templateVersion'] ?? null) !== 'chat-completions-action/1'
            || ($proofs['catalog']['modelRevision'] ?? null) !== $settings['modelRevision']
            || ! \App\Services\Privacy\Gateway\Contracts\GatewayModelRequest::isDigest($proofs['catalog']['catalogDigest'] ?? null)
            || ! is_int($proofs['capacity']['contextWindow'] ?? null) || $settings['contextWindow'] > $proofs['capacity']['contextWindow']
            || ! is_int($proofs['capacity']['maxOutputTokens'] ?? null) || $settings['maxOutputTokens'] > $proofs['capacity']['maxOutputTokens']
            || ($proofs['tokenizer']['tokenizerId'] ?? null) !== $settings['tokenizerId']
            || ($proofs['tokenizer']['tokenizerRevision'] ?? null) !== $settings['tokenizerRevision']
            || ($proofs['tokenizer']['modelRevision'] ?? null) !== $settings['modelRevision']
            || ($proofs['tokenizer']['countMethod'] ?? null) !== 'full_wire_json_bpe_upper_bound'
            || ($proofs['tokenizer']['vocabularySha256'] ?? null) !== $configuration['tokenizerSha256']
            || ($proofs['tokenizer']['patternSha256'] ?? null) !== $configuration['tokenizerPatternSha256']
            || ($proofs['key']['credentialFile'] ?? null) !== $configuration['credentialFile']
            || ($proofs['identity']['gatewayUid'] ?? null) !== $configuration['gatewayUid']
            || ($proofs['identity']['gatewayGid'] ?? null) !== $configuration['gatewayGid']
            || ($proofs['identity']['processorUid'] ?? null) !== $configuration['processorPeer']['uid']
            || ($proofs['identity']['processorGid'] ?? null) !== $configuration['processorPeer']['gid']
            || ! is_int($proofs['identity']['appUid'] ?? null) || $proofs['identity']['appUid'] < 1
            || in_array($proofs['identity']['appUid'], [$configuration['gatewayUid'], $configuration['processorPeer']['uid']], true)
            || ($proofs['channel']['protocol'] ?? null) !== AuthenticatedPublicCoreChannel::SCHEMA_VERSION
            || ($proofs['channel']['socketPath'] ?? null) !== $configuration['socketPath']
            || ($proofs['egress']['allowedEndpoint'] ?? null) !== \App\Services\Privacy\Gateway\GatewayPublicCoreHttpSender::ENDPOINT
            || ! \App\Services\Privacy\Gateway\Contracts\GatewayModelRequest::isDigest($proofs['egress']['policyDigest'] ?? null)
            || ! \App\Services\Privacy\Gateway\Contracts\GatewayModelRequest::isDigest($proofs['backendAuthority']['coverageDigest'] ?? null)
            || ! \App\Services\Privacy\Gateway\Contracts\GatewayModelRequest::isReference($proofs['backendAuthority']['strategyVersion'] ?? null)
            || ($proofs['backendAuthority']['releasePhase'] ?? null) !== 'guarded_upload'
            || ($proofs['nativeTransfer']['strategy'] ?? null) !== 'curl_multi_watchdog/1'
            || ($proofs['nativeTransfer']['phpVersionId'] ?? null) !== PHP_VERSION_ID
            || ($proofs['nativeTransfer']['curlVersionNumber'] ?? null) !== (function_exists('curl_version') ? (curl_version()['version_number'] ?? null) : null)
            || ($proofs['nativeTransfer']['uploadEvent'] ?? null) !== 'same_handle_xferinfo_complete'
            || ($proofs['nativeTransfer']['cancellation'] ?? null) !== 'verified_remove_destroy_no_reuse'
            || ($proofs['nativeTransfer']['uploadMaxMs'] ?? null) !== 2000) {
            throw new LogicException('runtime_not_activated');
        }
        if (PHP_INT_SIZE !== 8
            || ($proofs['nativeTransfer']['senderSourceSha256'] ?? null) !== hash_file('sha256', dirname(__DIR__, 2).'/app/Services/Privacy/Gateway/GatewayPublicCoreHttpSender.php')
            || ($proofs['nativeTransfer']['channelSourceSha256'] ?? null) !== hash_file('sha256', dirname(__DIR__, 2).'/app/Services/Privacy/PublicCore/Transport/AuthenticatedPublicCoreChannel.php')
            || ($proofs['nativeTransfer']['gatewaySourceSha256'] ?? null) !== hash_file('sha256', dirname(__DIR__, 2).'/app/Services/Privacy/Gateway/GatewayPublicCoreTransport.php')) {
            throw new LogicException('runtime_not_activated');
        }

        return $configuration;
    }


    /** @return array<string, string> */
    public function roleFiles(array $configuration): array
    {
        $files = ['profile.json' => json_encode($configuration['profile'], JSON_THROW_ON_ERROR)];
        foreach ($configuration['evidence'] as $kind => $ref) {
            $files[$kind.'.json'] = $this->bytes($configuration['evidenceDirectory'].'/'.$ref['file'], 65536);
        }
        $vocabulary = $this->bytes($configuration['tokenizerFile'], 16777216);
        $pattern = $this->bytes($configuration['tokenizerPattern'], 32768);
        if (!hash_equals($configuration['tokenizerSha256'], hash('sha256', $vocabulary))
            || !hash_equals($configuration['tokenizerPatternSha256'], hash('sha256', $pattern))) {
            throw new LogicException('tokenizer_unqualified');
        }
        $credential = $configuration['credentialFile'];
        $stat = @lstat($credential); $parent = @lstat(dirname($credential));
        if (!str_starts_with($credential, $this->gatewayDirectory.'/credential/') || realpath($credential) !== $credential
            || !is_array($stat) || !is_array($parent) || ($stat['mode'] & 0170000) !== 0100000
            || $stat['uid'] !== 0 || $stat['gid'] !== 41003 || ($stat['mode'] & 0037) !== 0
            || $stat['size'] < 1 || $stat['size'] > 4096 || $parent['uid'] !== 0 || $parent['gid'] !== 41003
            || ($parent['mode'] & 0170000) !== 0040000 || ($parent['mode'] & 0027) !== 0) {
            throw new LogicException('runtime_not_activated');
        }
        $this->metadata[$credential] = $stat; $this->metadata[dirname($credential)] = $parent;
        $this->recheck();
        return $files + ['vocabulary.tiktoken' => $vocabulary, 'pattern.txt' => $pattern];
    }
}

/** Exact-image root compiler. Publication is distinct from activation and model success. */
final class RoleProjectionPublisher
{
    public static function publish(string $root, string $releaseSha, string $imageDigest): bool
    {
        try { return self::compile($root, $releaseSha, $imageDigest); }
        catch (Throwable $error) {
            // This operation is only called behind managed drain/park barriers.
            // A failed preparation revokes the manifest; old active input is never restored.
            if (PHP_OS_FAMILY === 'Linux' && posix_geteuid() === 0) {
                try {
                    $snapshot = new GatewayProjectionSnapshot($root.'/gateway');
                    $path = $root.'/gateway/runtime.json'; $before = $snapshot->bytes($path, 65536);
                    $manifest = json_decode($before, true, 64, JSON_THROW_ON_ERROR);
                    if (is_array($manifest) && ($manifest['activation'] ?? null) === 'approved') {
                        $manifest['activation'] = 'inactive'; $bytes = json_encode($manifest, JSON_THROW_ON_ERROR);
                        $temporary = $root.'/gateway/.revoked-'.bin2hex(random_bytes(12));
                        try {
                            if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes) || !chgrp($temporary, 41003)
                                || !chmod($temporary, 0640)) { throw new LogicException('runtime_not_activated'); }
                            $snapshot->recheck();
                            if (!rename($temporary, $path)) { throw new LogicException('runtime_not_activated'); }
                        } finally { @unlink($temporary); }
                    }
                } catch (Throwable) { /* Invalid protected metadata cannot become a valid active input. */ }
            }
            throw $error;
        }
    }

    private static function compile(string $root, string $releaseSha, string $imageDigest): bool
    {
        if (PHP_OS_FAMILY !== 'Linux' || posix_geteuid() !== 0 || posix_getegid() !== 0
            || preg_match('/\A[0-9a-f]{40}\z/D', $releaseSha) !== 1
            || preg_match('/\Asha256:[0-9a-f]{64}\z/D', $imageDigest) !== 1) {
            throw new LogicException('runtime_not_activated');
        }
        $snapshot = new GatewayProjectionSnapshot($root.'/gateway');
        $manifest = json_decode($snapshot->bytes($root.'/gateway/runtime.json', 65536), true, 64, JSON_THROW_ON_ERROR);
        // Missing actual inputs on ordinary main deployment never creates ready projections.
        if (is_array($manifest) && ($manifest['activation'] ?? null) === 'inactive') { return false; }
        $configuration = $snapshot->configuration($root.'/gateway/runtime.json');
        $profile = \App\Services\Privacy\Gateway\Contracts\GatewayModelProfile::fromArray($configuration['profile']);
        $request = \App\Services\Privacy\Gateway\Contracts\GatewayModelRequest::class;
        $common = $snapshot->roleFiles($configuration);
        $controls = new ProtectedRoleFile($root.'/control', 0);
        // CI supplies real inspect/lifetime/acceptance facts; the compiler never manufactures them.
        $control = $controls->json('publication.json');
        if (!$request::hasExactKeys($control, ['schemaVersion', 'releaseSha', 'imageDigest', 'expiresAt',
            'consumersStopped', 'peers', 'acceptedReceipts', 'expectedOutputs'])
            || $control['schemaVersion'] !== 'public-core-projection-publication/1'
            || $control['releaseSha'] !== $releaseSha || $control['imageDigest'] !== $imageDigest
            || !is_int($control['expiresAt']) || $control['expiresAt'] <= time()
            || $control['consumersStopped'] !== true || !is_array($control['expectedOutputs'])
            || !$request::hasExactKeys($control['peers'], ['processor', 'gateway'])
            || !is_array($control['acceptedReceipts'])) { throw new LogicException('runtime_not_activated'); }
        $observed = $controls->json('observed-peers.json');
        if (($observed['schemaVersion'] ?? null) !== 'public-core-observed-peers/1'
            || ($observed['releaseSha'] ?? null) !== $releaseSha || ($observed['imageDigest'] ?? null) !== $imageDigest
            || ($observed['peers'] ?? null) != $control['peers'] || !is_int($observed['observedAt'] ?? null)
            || $observed['observedAt'] > time() || time() - $observed['observedAt'] > 30) { throw new LogicException('gateway_identity_unavailable'); }
        $qualification = $controls->json('qualification.json');
        $keys = ['schemaVersion', 'qualification', 'profileFingerprint', 'registryDigest', 'authorizationFenceEvidenceRef',
            'identityEvidenceRef', 'channelEvidenceRef', 'egressEvidenceRef', 'secretEvidenceRef', 'activationRef'];
        if (!$request::hasExactKeys($qualification, $keys) || $qualification['schemaVersion'] !== 'public-core-runtime-proof/1'
            || $qualification['qualification'] !== 'actual' || $qualification['profileFingerprint'] !== $profile->fingerprint()
            || $qualification['registryDigest'] !== \App\Services\Privacy\PublicCore\RegisteredPublicFixtureRegistry::compiled()->manifestDigest()) {
            throw new LogicException('runtime_not_activated');
        }
        $peers = []; $lifetimes = []; $expiry = $control['expiresAt'];
        foreach (['processor' => 41002, 'gateway' => 41003] as $role => $uid) {
            $observation = $control['peers'][$role];
            if (!$request::hasExactKeys($observation, ['containerId', 'imageDigest', 'service', 'hostPid', 'peer', 'lifetimeRef'])
                || preg_match('/\A[0-9a-f]{64}\z/D', $observation['containerId'] ?? '') !== 1
                || $observation['imageDigest'] !== $imageDigest || $observation['service'] !== 'public-core-'.$role
                || !is_int($observation['hostPid']) || $observation['hostPid'] < 1
                || !$request::hasExactKeys($observation['peer'], ['pid', 'uid', 'gid'])
                || !is_int($observation['peer']['pid']) || $observation['peer']['pid'] < 1
                || $observation['peer']['uid'] !== $uid || $observation['peer']['gid'] !== $uid) {
                throw new LogicException('gateway_identity_unavailable');
            }
            $peer = $observation['peer']; $pid = $peer['pid'];
            $cmdline = @file_get_contents('/proc/'.$pid.'/cmdline');
            // Same role PHP process is parked; exec-helper and unrelated PHP PIDs cannot qualify.
            if (!is_string($cmdline) || !str_contains($cmdline, "docker/public-core/runtime.php\0parked-".$role."\0")
                || ProcessIdentity::lifetime($peer) !== $observation['lifetimeRef']) {
                throw new LogicException('gateway_identity_unavailable');
            }
            $peers[$role] = $peer; $lifetimes[$role] = $observation['lifetimeRef'];
        }
        if ($configuration['processorPeer'] != $peers['processor']) { throw new LogicException('gateway_identity_unavailable'); }
        foreach (array_slice($keys, 4) as $key) {
            $ref = $qualification[$key];
            if (!$request::isReference($ref) || !isset($control['acceptedReceipts'][$key])
                || !$request::hasExactKeys($control['acceptedReceipts'][$key], ['ref', 'file', 'sha256'])
                || $control['acceptedReceipts'][$key]['ref'] !== $ref
                || preg_match('/\A[A-Za-z0-9_-]{1,128}\.json\z/D', $control['acceptedReceipts'][$key]['file'] ?? '') !== 1
                || !$request::isDigest($control['acceptedReceipts'][$key]['sha256'])) { throw new LogicException('runtime_not_activated'); }
            $bytes = $controls->bytes($control['acceptedReceipts'][$key]['file']);
            if (hash('sha256', $bytes) !== $control['acceptedReceipts'][$key]['sha256']) { throw new LogicException('runtime_not_activated'); }
            $receipt = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
            if (!$request::hasExactKeys($receipt, ['schemaVersion', 'kind', 'ref', 'status', 'profileFingerprint', 'modelId',
                'apiMethod', 'issuedAt', 'expiresAt', 'details']) || $receipt['schemaVersion'] !== 'public-core-runtime-evidence/1'
                || $receipt['kind'] !== $key || $receipt['ref'] !== $ref || $receipt['status'] !== 'verified'
                || $receipt['profileFingerprint'] !== $profile->fingerprint() || $receipt['modelId'] !== $profile->values()['modelId']
                || $receipt['apiMethod'] !== $profile->values()['apiMethod'] || !is_int($receipt['issuedAt'])
                || $receipt['issuedAt'] < 1 || $receipt['issuedAt'] > time() || !is_int($receipt['expiresAt'])
                || $receipt['expiresAt'] <= time() || $receipt['expiresAt'] <= $receipt['issuedAt']
                || !is_array($receipt['details']) || ($receipt['details']['releaseSha'] ?? null) !== $releaseSha
                || ($receipt['details']['imageDigest'] ?? null) !== $imageDigest
                || ($receipt['details']['registryDigest'] ?? null) !== $qualification['registryDigest']
                || ($receipt['details']['roleLifetimes'] ?? null) !== $lifetimes) { throw new LogicException('runtime_not_activated'); }
            $expiry = min($expiry, $receipt['expiresAt']);
        }
        foreach ($configuration['evidence'] as $kind => $ref) {
            $proof = json_decode($common[$kind.'.json'], true, 64, JSON_THROW_ON_ERROR);
            $expiry = min($expiry, $proof['expiresAt']);
        }
        $files = ['app' => array_diff_key($common, array_flip(['vocabulary.tiktoken', 'pattern.txt'])) +
            ['processor-peer.json' => json_encode($peers['processor'], JSON_THROW_ON_ERROR), 'gateway-peer.json' => json_encode($peers['gateway'], JSON_THROW_ON_ERROR)],
            'processor' => $common + ['qualification.json' => json_encode($qualification, JSON_THROW_ON_ERROR),
                'gateway-peer.json' => json_encode($peers['gateway'], JSON_THROW_ON_ERROR)], 'gateway' => []];
        $expected = $control['expectedOutputs']; $names = [];
        foreach ($files as $role => $outputs) {
            foreach ($outputs + ['generation.json' => ''] as $name => $_) { $names[] = $role.'/'.$name; }
        }
        if (!$request::hasExactKeys($expected, $names)) { throw new LogicException('receipt_changed'); }
        $temps = []; $invalidated = false;
        try {
            foreach ($files as $role => $outputs) {
                $gid = match ($role) { 'app' => 82, 'processor' => 41002, default => 41003 }; $directory = $root.'/'.$role;
                $stat = @lstat($directory);
                if (realpath($directory) !== $directory || !is_array($stat) || $stat['uid'] !== 0 || $stat['gid'] !== $gid
                    || ($stat['mode'] & 0170000) !== 0040000 || ($stat['mode'] & 0777) !== 0750) { throw new LogicException('runtime_not_activated'); }
                $reader = new ProtectedRoleFile($directory, $gid); $digests = [];
                foreach ($outputs as $name => $bytes) { $digests[$name] = hash('sha256', $bytes); }
                if ($role === 'gateway') { $digests['runtime.json'] = hash('sha256', $snapshot->bytes($root.'/gateway/runtime.json', 65536)); }
                $outputs['generation.json'] = json_encode(['schemaVersion' => 'public-core-projection-generation/1',
                    'releaseSha' => $releaseSha, 'imageDigest' => $imageDigest, 'expiresAt' => $expiry,
                    'profileFingerprint' => $profile->fingerprint(), 'files' => $digests, 'roleLifetimes' => $lifetimes], JSON_THROW_ON_ERROR);
                foreach ($outputs as $name => $bytes) {
                    self::comparePrior($reader, $directory, $name, $expected[$role.'/'.$name]);
                    $temporary = $directory.'/.publication-'.bin2hex(random_bytes(12));
                    $stream = fopen($temporary, 'x+b');
                    if ($stream === false) { throw new LogicException('runtime_not_activated'); }
                    $temps[$role.'/'.$name] = $temporary;
                    try {
                        if (!chmod($temporary, 0600) || fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream)
                            || !fsync($stream) || !chgrp($temporary, $gid) || !chmod($temporary, 0640)) { throw new LogicException('runtime_not_activated'); }
                    } finally { fclose($stream); }
                }
            }
            // Invalidate both barriers before any publication; no active rollback on a partial failure.
            foreach (['app' => 82, 'processor' => 41002, 'gateway' => 41003] as $role => $gid) {
                $directory = $root.'/'.$role; $reader = new ProtectedRoleFile($directory, $gid);
                self::comparePrior($reader, $directory, 'generation.json', $expected[$role.'/generation.json']);
                $invalidated = true;
                if (is_file($directory.'/generation.json') && !unlink($directory.'/generation.json')) { throw new LogicException('runtime_not_activated'); }
            }
            $snapshot->recheck(); $controls->json('publication.json'); $controls->json('qualification.json'); $controls->json('observed-peers.json');
            foreach ($control['acceptedReceipts'] as $ref) { $controls->bytes($ref['file']); }
            foreach ($peers as $role => $peer) { if (ProcessIdentity::lifetime($peer) !== $lifetimes[$role]) { throw new LogicException('gateway_identity_unavailable'); } }
            if ($expiry <= time()) { throw new LogicException('expired'); }
            foreach ($temps as $relative => $temporary) {
                if (str_ends_with($relative, '/generation.json')) { continue; }
                [$role, $name] = explode('/', $relative); $gid = match ($role) { 'app' => 82, 'processor' => 41002, default => 41003 };
                self::comparePrior(new ProtectedRoleFile($root.'/'.$role, $gid), $root.'/'.$role, $name, $expected[$relative]);
                if (!rename($temporary, $root.'/'.$relative)) {
                    throw new LogicException('runtime_not_activated');
                }
                unset($temps[$relative]);
            }
            // Read back every output before publishing the generation barriers last.
            foreach ($files as $role => $outputs) {
                $reader = new ProtectedRoleFile($root.'/'.$role, match ($role) { 'app' => 82, 'processor' => 41002, default => 41003 });
                foreach ($outputs as $name => $bytes) { if ($reader->bytes($name, max(strlen($bytes), 1)) !== $bytes) { throw new LogicException('receipt_changed'); } }
            }
            $snapshot->recheck();
            foreach ($peers as $role => $peer) { if (ProcessIdentity::lifetime($peer) !== $lifetimes[$role]) { throw new LogicException('gateway_identity_unavailable'); } }
            if ($expiry <= time()) { throw new LogicException('expired'); }
            foreach (['app', 'processor', 'gateway'] as $role) {
                $relative = $role.'/generation.json';
                if (@lstat($root.'/'.$relative) !== false || !rename($temps[$relative], $root.'/'.$relative)) { throw new LogicException('receipt_changed'); }
                unset($temps[$relative]);
            }
            return true;
        } catch (Throwable $error) {
            // All consumers remain held/stopped; absent barriers make partially published files unusable.
            if ($invalidated) { foreach (['app', 'processor', 'gateway'] as $role) { @unlink($root.'/'.$role.'/generation.json'); } }
            throw $error;
        } finally { foreach ($temps as $temporary) { @unlink($temporary); } }
    }

    private static function comparePrior(ProtectedRoleFile $reader, string $directory, string $name, mixed $expected): void
    {
        clearstatcache(true, $directory.'/'.$name);
        if ($expected === null && @lstat($directory.'/'.$name) === false) { return; }
        if (!\App\Services\Privacy\Gateway\Contracts\GatewayModelRequest::isDigest($expected)
            || hash('sha256', $reader->bytes($name, 16777216)) !== $expected) { throw new LogicException('receipt_changed'); }
    }
}

/** Bounded same-PID startup only; the wait never opens a channel or reads a provider key. */
final class ParkedRoleBootstrap
{
    public static function assertReleased(string $role): void
    {
        $uid = match ($role) { 'processor' => 41002, 'gateway' => 41003, default => throw new LogicException('gateway_identity_unavailable') };
        if (PHP_OS_FAMILY !== 'Linux' || posix_geteuid() !== $uid || posix_getegid() !== $uid) { throw new LogicException('gateway_identity_unavailable'); }
        $peer = ['pid' => (int)getmypid(), 'uid' => $uid, 'gid' => $uid];
        $files = new ProtectedRoleFile('/etc/most/public-core/'.$role); $generation = $files->json('generation.json');
        $request = \App\Services\Privacy\Gateway\Contracts\GatewayModelRequest::class;
        $release = json_decode(file_get_contents('/etc/most/release.json'), true, 8, JSON_THROW_ON_ERROR);
        if (!$request::hasExactKeys($generation, ['schemaVersion', 'releaseSha', 'imageDigest', 'expiresAt', 'profileFingerprint', 'files', 'roleLifetimes'])
            || $generation['schemaVersion'] !== 'public-core-projection-generation/1'
            || !is_int($generation['expiresAt']) || $generation['expiresAt'] <= time()
            || $generation['releaseSha'] !== ($release['sha'] ?? null)
            || !is_array($generation['files']) || !$request::isDigest($generation['profileFingerprint'])
            || preg_match('/\Asha256:[0-9a-f]{64}\z/D', $generation['imageDigest'] ?? '') !== 1
            || !$request::hasExactKeys($generation['roleLifetimes'], ['processor', 'gateway'])
            || $generation['roleLifetimes'][$role] !== ProcessIdentity::lifetime($peer)) { throw new LogicException('runtime_not_activated'); }
        foreach ($generation['files'] as $name => $digest) {
            if (!is_string($name) || !$request::isDigest($digest) || hash('sha256', $files->bytes($name, 16777216)) !== $digest) {
                throw new LogicException('receipt_changed');
            }
        }
        if ($role === 'gateway' && !$request::hasExactKeys($generation['files'], ['runtime.json'])) { throw new LogicException('runtime_not_activated'); }
    }

    public static function wait(string $role, int $timeoutMs = 30000): void
    {
        $uid = match ($role) { 'processor' => 41002, 'gateway' => 41003, default => throw new LogicException('gateway_identity_unavailable') };
        if (PHP_OS_FAMILY !== 'Linux' || posix_geteuid() !== $uid || posix_getegid() !== $uid || $timeoutMs < 1 || $timeoutMs > 30000) {
            throw new LogicException('gateway_identity_unavailable');
        }
        $peer = ['pid' => (int)getmypid(), 'uid' => $uid, 'gid' => $uid]; $lifetime = ProcessIdentity::lifetime($peer);
        $deadline = hrtime(true) + $timeoutMs * 1000000;
        while (hrtime(true) < $deadline) {
            try {
                self::assertReleased($role);
                if (ProcessIdentity::lifetime($peer) === $lifetime && hrtime(true) < $deadline) { return; }
            } catch (Throwable) { }
            usleep(10000);
        }
        throw new LogicException('runtime_not_activated');
    }
}

final class ProcessIdentity
{
    /** @param array{pid: int, uid: int, gid: int} $peer */
    public static function lifetime(array $peer): string
    {
        $directory = '/proc/'.$peer['pid'];
        $owner = @stat($directory);
        $status = @file_get_contents($directory.'/status');
        $before = @file_get_contents($directory.'/stat');
        if (!is_array($owner) || $owner['uid'] !== $peer['uid'] || !is_string($status) || !is_string($before)
            || preg_match('/^Uid:\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)$/m', $status, $uids) !== 1
            || preg_match('/^Gid:\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)$/m', $status, $gids) !== 1
            || count(array_unique(array_slice($uids, 1))) !== 1 || (int)$uids[1] !== $peer['uid']
            || count(array_unique(array_slice($gids, 1))) !== 1 || (int)$gids[1] !== $peer['gid']) {
            throw new LogicException('gateway_identity_unavailable');
        }
        $offset = strrpos($before, ')');
        $fields = $offset === false ? [] : explode(' ', trim(substr($before, $offset + 1)));
        $start = $fields[19] ?? null; // proc stat field22; state is field3.
        $boot = @file_get_contents('/proc/sys/kernel/random/boot_id');
        if (!is_string($start) || !ctype_digit($start) || !is_string($boot) || trim($boot) === '') {
            throw new LogicException('gateway_identity_unavailable');
        }
        // Recheck the stable start field after reading owner/status: never pin an exec-helper PID.
        $after = @file_get_contents($directory.'/stat');
        $end = is_string($after) ? strrpos($after, ')') : false;
        $current = $end === false ? [] : explode(' ', trim(substr($after, $end + 1)));
        if (($current[19] ?? null) !== $start) { throw new LogicException('gateway_identity_unavailable'); }
        return 'ref_'.substr(hash('sha256', json_encode([$peer, trim($boot), $start], JSON_THROW_ON_ERROR)), 0, 32);
    }

    public static function appFile(AuthenticatedPublicCoreChannel $channel): string
    {
        $local = ['pid' => getmypid(), 'uid' => posix_geteuid(), 'gid' => posix_getegid()];
        $peer = $channel->peer();
        if ($local['uid'] !== 82 || $local['gid'] !== 82 || $peer['uid'] !== 41002 || $peer['gid'] !== 41002) {
            throw new LogicException('gateway_identity_unavailable');
        }
        $base = '/run/most-public-core/app';
        $stat = @lstat($base);
        if (realpath($base) !== $base || !is_array($stat) || ($stat['mode'] & 0170000) !== 0040000
            || $stat['uid'] !== 82 || $stat['gid'] !== 82 || ($stat['mode'] & 0777) !== 0700) {
            throw new LogicException('gateway_identity_unavailable');
        }
        $directory = $base.'/'.$local['pid'];
        if (@lstat($directory) === false && !mkdir($directory, 0700)) { throw new LogicException('gateway_identity_unavailable'); }
        $stat = @lstat($directory);
        if (realpath($directory) !== $directory || !is_array($stat) || ($stat['mode'] & 0170000) !== 0040000
            || $stat['uid'] !== 82 || $stat['gid'] !== 82 || ($stat['mode'] & 0777) !== 0700) {
            throw new LogicException('gateway_identity_unavailable');
        }
        $bytes = json_encode(['schemaVersion' => 'public-core-app-processor-identity/1', 'localRole' => 'App',
            'peerRole' => 'Processor', 'localIdentityRef' => self::lifetime($local), 'peerIdentityRef' => self::lifetime($peer),
            'localKernel' => $local, 'peerKernel' => $peer], JSON_THROW_ON_ERROR);
        $path = $directory.'/identity.json';
        if (@lstat($path) === false) {
            $old = umask(0077);
            try {
                $stream = fopen($path, 'x+b');
                if ($stream === false) { throw new LogicException('gateway_identity_unavailable'); }
                try { if (fwrite($stream, $bytes) !== strlen($bytes) || !fflush($stream)) { throw new LogicException('gateway_identity_unavailable'); } }
                finally { fclose($stream); }
            } finally { umask($old); }
        }
        // Existing process lifetime data is immutable. A stale PID reuse cannot overwrite it.
        $stat = @lstat($path);
        if (realpath($path) !== $path || !is_array($stat) || ($stat['mode'] & 0170000) !== 0100000
            || $stat['uid'] !== 82 || $stat['gid'] !== 82 || ($stat['mode'] & 0777) !== 0600
            || $stat['size'] !== strlen($bytes) || file_get_contents($path) !== $bytes) {
            throw new LogicException('gateway_identity_unavailable');
        }
        return $path;
    }
}

/** Exact-image main CI only; no Laravel boot, network, eval or secret output. */
final class RoleCredentialProvisioner
{
    /** @return array{providerCredential: bool, controlKeys: bool} */
    public static function provision(string $environment, string $root): array
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid') || posix_geteuid() !== 0
            || !str_starts_with($root, '/') || realpath($root) !== $root) {
            throw new LogicException('runtime_not_activated');
        }
        clearstatcache(true, $environment);
        $stat = @lstat($environment);
        if (realpath($environment) !== $environment || !is_array($stat) || ($stat['mode'] & 0170000) !== 0100000
            || $stat['uid'] !== 0 || $stat['gid'] !== 0 || ($stat['mode'] & 0777) !== 0600
            || $stat['size'] < 1 || $stat['size'] > 1048576) {
            throw new LogicException('runtime_not_activated');
        }
        // Dotenv parses literal values without shell execution or process env interpolation.
        $stream = @fopen($environment, 'rb');
        if ($stream === false) { throw new LogicException('runtime_not_activated'); }
        try {
            $opened = fstat($stream);
            $bytes = stream_get_contents($stream, 1048577);
            clearstatcache(true, $environment);
            $after = @lstat($environment);
            if (!is_array($opened) || !is_array($after) || !is_string($bytes) || strlen($bytes) !== $stat['size']) {
                throw new LogicException('runtime_not_activated');
            }
            foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $field) {
                if ($stat[$field] !== $opened[$field] || $opened[$field] !== $after[$field]) {
                    throw new LogicException('runtime_not_activated');
                }
            }
        } finally { fclose($stream); }
        $entries = (new \Dotenv\Parser\Parser())->parse($bytes);
        $selected = [];
        foreach ($entries as $entry) {
            $name = $entry->getName();
            if (!in_array($name, ['APP_KEY', 'TIMEWEB_AI_API_KEY', 'TIMEWEB_API_KEY', 'TIMEWEB_AI_PROXY_KEY'], true)) { continue; }
            if (array_key_exists($name, $selected)) { throw new LogicException('runtime_not_activated'); }
            $value = $entry->getValue()->get();
            // No variable lookup (including APP_KEY); the existing custody input must be literal.
            if ($value->getVars() !== []) { throw new LogicException('runtime_not_activated'); }
            $selected[$name] = $value->getChars();
        }
        // Dotenv permits unfinished multiline entries to wait for another line.
        // A custody declaration must actually have produced one complete literal entry.
        preg_match_all('/^\s*(?:export\s+)?(APP_KEY|TIMEWEB_AI_API_KEY|TIMEWEB_API_KEY|TIMEWEB_AI_PROXY_KEY)\s*(?:=|$)/m', $bytes, $declared);
        if (count($declared[1]) !== count($selected)) { throw new LogicException('runtime_not_activated'); }
        $provider = '';
        foreach (['TIMEWEB_AI_API_KEY', 'TIMEWEB_API_KEY', 'TIMEWEB_AI_PROXY_KEY'] as $name) {
            $value = $selected[$name] ?? '';
            if ($value !== '') { $provider = $value; break; }
        }
        if ($provider !== '' && (strlen($provider) > 4096 || preg_match('/[\x00-\x20\x7f]/', $provider) !== 0)) {
            throw new LogicException('runtime_not_activated');
        }
        $appKey = $selected['APP_KEY'] ?? '';
        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            if ($decoded === false) { throw new LogicException('runtime_not_activated'); }
            $appKey = $decoded;
        }
        if ($appKey !== '' && strlen($appKey) < 32) { throw new LogicException('runtime_not_activated'); }
        // Validate the complete input before writing anything. Existing values cannot rotate here.
        $outputs = [];
        if ($provider !== '') { $outputs[] = [$root.'/gateway/credential', 'provider-key', 41003, $provider]; }
        if ($appKey !== '') {
            $outputs[] = [$root.'/app', 'control-key', 82, hash_hmac('sha256', 'most/public-core/app-ticket/1', $appKey)];
            $outputs[] = [$root.'/processor', 'control-key', 41002, hash_hmac('sha256', 'most/public-core/processor-ledger/1', $appKey)];
        }
        foreach ($outputs as [$directory, $name, $gid, $value]) {
            self::checkDirectory($directory, $gid);
            $path = $directory.'/'.$name;
            if (@lstat($path) !== false) { self::existing($path, $gid, $value); }
        }
        foreach ($outputs as [$directory, $name, $gid, $value]) {
            $path = $directory.'/'.$name;
            if (@lstat($path) !== false) { self::existing($path, $gid, $value); continue; }
            $temporary = $directory.'/.pending-'.bin2hex(random_bytes(12));
            $stream = fopen($temporary, 'x+b');
            if ($stream === false) { throw new LogicException('runtime_not_activated'); }
            try {
                if (!chmod($temporary, 0600) || fwrite($stream, $value) !== strlen($value) || !fflush($stream)
                    || !fsync($stream) || !chgrp($temporary, $gid) || !chmod($temporary, 0640)
                    || !link($temporary, $path)) { throw new LogicException('runtime_not_activated'); }
            } finally { fclose($stream); @unlink($temporary); }
        }
        return ['providerCredential' => $provider !== '', 'controlKeys' => $appKey !== ''];
    }

    private static function checkDirectory(string $directory, int $gid): void
    {
        if (realpath($directory) !== $directory) { throw new LogicException('runtime_not_activated'); }
        $stat = @lstat($directory);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0040000 || $stat['uid'] !== 0
            || $stat['gid'] !== $gid || ($stat['mode'] & 0777) !== 0750) {
            throw new LogicException('runtime_not_activated');
        }
        for ($path = dirname($directory); ; $path = dirname($path)) {
            $parent = @lstat($path);
            if (!is_array($parent) || ($parent['mode'] & 0170000) !== 0040000 || $parent['uid'] !== 0
                || ($parent['mode'] & 0022) !== 0) { throw new LogicException('runtime_not_activated'); }
            if ($path === '/') { break; }
        }
    }

    private static function existing(string $path, int $gid, string $expected): void
    {
        $stat = @lstat($path);
        if (realpath($path) !== $path || !is_array($stat) || ($stat['mode'] & 0170000) !== 0100000
            || $stat['uid'] !== 0 || $stat['gid'] !== $gid || ($stat['mode'] & 0777) !== 0640
            || $stat['size'] !== strlen($expected) || !hash_equals(hash('sha256', $expected), hash_file('sha256', $path))) {
            throw new LogicException('runtime_not_activated');
        }
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

    public static function configureProtected(\Illuminate\Foundation\Application $app, \Closure $configure): void
    {
        if (!AuthenticatedPublicCoreChannel::isNativeAvailable() || posix_geteuid() !== 82 || posix_getegid() !== 82) {
            throw new LogicException('runtime_not_activated');
        }
        $projection = new RoleProjection('/etc/most/public-core/app');
        $profile = $projection->profile();
        $key = $projection->files()->bytes('control-key', 4096);
        $cache = $app->make('cache')->store('redis');
        if (!$cache instanceof \Illuminate\Cache\Repository || !$cache->getStore() instanceof \Illuminate\Cache\RedisStore) {
            throw new LogicException('runtime_not_activated');
        }
        $connection = $app->make('db')->connection();
        $logging = $app->make(\App\Services\Logging\LoggingService::class);
        $fence = new \App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreBackendAuthorityFence($connection, $logging, $cache, $key);
        if (!$fence->available()) { throw new LogicException('runtime_not_activated'); }
        $configure($fence, static function (int $expiry) use ($projection, $profile) {
            if ($expiry <= time() || $expiry > time() + 30 || $projection->profile()->fingerprint() !== $profile->fingerprint()) {
                throw new LogicException('profile_changed');
            }
            $projection->files()->bytes('control-key', 4096);
            $processor = $projection->peer('processor');
            $gateway = $projection->peer('gateway');
            $channel = AuthenticatedPublicCoreChannel::connect('/run/most-public-core/processor/processor.sock',
                $processor, min(30000, ($expiry - time()) * 1000));
            try {
                return \App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreContextBindings::sourceNormalAppPort(
                    $channel, ProcessIdentity::appFile($channel), $profile, $gateway);
            } catch (Throwable $failure) { $channel->close(); throw $failure; }
        });
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

    public static function protectedListener(): \Closure
    {
        $projection = new RoleProjection('/etc/most/public-core/processor');
        $profile = $projection->profile();
        $key = $projection->files()->bytes('control-key', 4096);
        $directory = '/var/lib/most/public-core/processor';
        $stat = @lstat($directory);
        if (realpath($directory) !== $directory || !is_array($stat) || ($stat['mode'] & 0170000) !== 0040000
            || $stat['uid'] !== 41002 || $stat['gid'] !== 41002 || ($stat['mode'] & 0777) !== 0700) {
            throw new LogicException('runtime_not_activated');
        }
        $registry = \App\Services\Privacy\PublicCore\RegisteredPublicFixtureRegistry::compiled();
        $readiness = new \App\Services\Privacy\PublicCore\PublicCoreRuntimeReadiness($registry,
            $projection->profile(...), $projection->qualification(...), $projection->encoder($profile));
        if ($readiness->qualifiedProfile()?->isActualProfile() !== true) { throw new LogicException('runtime_not_activated'); }
        $store = new \App\Services\Privacy\PublicCore\PublicCoreReceiptStore($directory, $key);
        $appLifetimes = [];
        $peerSource = static function (array $peer) use ($projection, $profile, &$appLifetimes): array {
            if ($peer['uid'] !== 82 || $peer['gid'] !== 82 || $projection->profile()->fingerprint() !== $profile->fingerprint()) {
                throw new LogicException('gateway_identity_unavailable');
            }
            $projection->files()->bytes('control-key', 4096);
            $lifetime = ProcessIdentity::lifetime($peer);
            if (isset($appLifetimes[$peer['pid']]) && $appLifetimes[$peer['pid']] !== $lifetime) {
                throw new LogicException('gateway_identity_unavailable');
            }
            $appLifetimes[$peer['pid']] = $lifetime;
            return ['role' => 'app', 'identityRef' => $lifetime, 'kernelPeer' => $peer];
        };
        $holder = new class {
            public ?\App\Services\Privacy\PublicCore\PublicCoreProcessor $processor = null;
        };
        $attempts = [];
        $uploadBounds = static function (\App\Services\Privacy\Gateway\Contracts\GatewayModelRequest $packet) use ($holder, &$attempts): array {
            $expiry = $holder->processor?->normalDispatchExpiry();
            if ($expiry === null || $packet->expiresAt > $expiry || $expiry <= time()) { throw new LogicException('expired'); }
            $now = intdiv(hrtime(true), 1000000);
            $attempts[$packet->attemptRef] ??= [$now, $expiry, $packet->projectionDigest];
            [$start, $original, $digest] = $attempts[$packet->attemptRef];
            if ($original !== $expiry || $digest !== $packet->projectionDigest || $now < $start) { throw new LogicException('expired'); }
            return ['predicateRemainingMs' => max(0, 2000 - ($now - $start)),
                'loopRemainingMs' => max(0, min(($expiry - time()) * 1000, 30000 - ($now - $start)))];
        };
        $gatewayFactory = static function (\App\Services\Privacy\Gateway\Contracts\GatewayModelProfile $selected, array $request, int $expiry) use ($projection, $profile) {
            if ($selected->fingerprint() !== $profile->fingerprint() || $projection->profile()->fingerprint() !== $profile->fingerprint()
                || $expiry <= time()) { throw new LogicException('profile_changed'); }
            return AuthenticatedPublicCoreChannel::connect('/run/most-public-core/gateway/gateway.sock',
                $projection->peer('gateway'), min(30000, ($expiry - time()) * 1000));
        };
        $processor = \App\BusinessModules\Features\AIAssistant\Services\Runtime\PublicCoreRuntimeComposition::nativeProcessor(
            $store, $readiness, $peerSource, $gatewayFactory, $uploadBounds, self::semanticVerdict(...));
        $holder->processor = $processor;
        return static function () use ($processor, $projection, $profile): void {
            $listener = AuthenticatedPublicCoreChannel::listen('/run/most-public-core/processor/processor.sock');
            try {
                // One retained processor/store for the listener lifetime; a new channel per operation.
                for ($handled = 0; $handled < 128; $handled++) {
                    if ($projection->profile()->fingerprint() !== $profile->fingerprint()) { throw new LogicException('profile_changed'); }
                    $channel = null;
                    try {
                        $channel = AuthenticatedPublicCoreChannel::accept($listener, ['uid' => 82, 'gid' => 82, 'pid' => null], 30000);
                        $processor->serveAppChannel($channel);
                    } catch (Throwable) {
                    } finally { $channel?->close(); }
                }
            } finally { socket_close($listener); }
        };
    }

    /**
     * Unknown text is repaired; provenance/currency arithmetic stay in the existing validator.
     * @param array<string, mixed> $value
     * @param array<string, mixed> $payload
     * @param list<array<string, mixed>> $evidence
     * @return array{status: string, reason: string}
     */
    public static function semanticVerdict(array $value, array $payload, array $evidence): array
    {
        $repair = ['status' => 'repair', 'reason' => 'claims_invalid'];
        $valid = ['status' => 'valid', 'reason' => 'none'];
        if (!is_string($value['text'] ?? null) || !is_array($value['claims'] ?? null)
            || !is_array($value['sourceRefs'] ?? null) || $value['sourceRefs'] === []
            || !is_array($payload['messages'] ?? null)) { return $repair; }
        $current = array_values(array_filter($payload['messages'], static fn (array $message): bool =>
            ($message['ref'] ?? null) === ($payload['currentRef'] ?? null) && ($message['role'] ?? null) === 'user'));
        if (count($current) !== 1) { return $repair; }
        $registry = \App\Services\Privacy\PublicCore\RegisteredPublicFixtureRegistry::compiled();
        $selectors = array_values(array_filter($registry->catalog(), static fn (array $row): bool =>
            $row['display_text'] === ($current[0]['content'] ?? null)));
        if (count($selectors) !== 1) { return $repair; }
        $selection = $selectors[0];
        if ($selection['fixture_id'] === 'public-photo-metadata-v1') {
            // Only the registered transcript already present in the authenticated context.
            // The original response validator checks contextScope and its source aliases first.
            if ($value['claims'] !== []) { return $repair; }
            $records = $registry->records($selection['fixture_id'], $selection['fixture_version']);
            $transcript = $records[0]['text'] ?? null;
            foreach ($payload['messages'] as $message) {
                if (($message['ref'] ?? null) === $payload['currentRef'] || ($message['role'] ?? null) !== 'user'
                    || ($message['content'] ?? null) !== $transcript || !is_array($message['sourceRefs'] ?? null)
                    || $message['sourceRefs'] === [] || array_diff($value['sourceRefs'], $message['sourceRefs']) !== []
                    || !in_array($message['ref'], $value['claimScope']['unitRefs'] ?? [], true)) { continue; }
                $text = $selection['input_id'] === 'photo-explain' ? $transcript
                    : 'Второй пункт учебной расшифровки — арматурный каркас. Это текстовая расшифровка, не проверка пикселей изображения.';
                return $value['text'] === $text ? $valid : $repair;
            }
            return $repair;
        }
        $latest = $evidence === [] ? null : $evidence[array_key_last($evidence)];
        $envelope = $latest['envelope'] ?? null;
        $facts = $envelope['facts'] ?? null;
        $scope = $envelope['coverage']['claimScope'] ?? null;
        if (!is_array($facts) || !is_array($scope)
            || ($envelope['resultGenerationRef'] ?? null) !== $selection['source_generation_ref']
            || ($scope['sourceGenerationRef'] ?? null) !== $selection['source_generation_ref']
            || !in_array($scope['kind'] ?? null, ['search_subset', 'selected_entity'], true)
            || ($value['claimScope'] ?? null) !== ($latest['modelMetadata']['claimScope'] ?? null)) { return $repair; }
        if ($selection['input_id'] === 'no-results') {
            // A completed empty search proves only absence in this bounded search, not globally.
            return $value['claims'] === [] && $facts === [] && ($envelope['status'] ?? null) === 'no_data'
                && ($envelope['toolKind'] ?? null) === 'search' && $scope['kind'] === 'search_subset'
                && ($scope['unitRefs'] ?? null) === [] && ($envelope['coverage']['status'] ?? null) === 'complete'
                && ($envelope['coverage']['totalUnits'] ?? null) === 0
                && ($envelope['coverage']['inspectedUnits'] ?? null) === 0
                && ($envelope['coverage']['omittedUnitRefs'] ?? null) === []
                && $value['text'] === 'В учебном каталоге по выбранному запросу ничего не найдено.' ? $valid : $repair;
        }
        if (count($value['claims']) !== 1) { return $repair; }
        $claim = $value['claims'][0];
        $records = $registry->records($selection['fixture_id'], $selection['fixture_version']);
        $recordId = $selection['input_id'] === 'cement-price' ? 'cement-m500' : 'concrete-b25';
        $records = array_values(array_filter($records ?? [], static fn (array $row): bool => $row['id'] === $recordId));
        if (count($records) !== 1 || ($claim['currency'] ?? null) !== 'RUB'
            || ($claim['sourceRefs'] ?? null) !== $value['sourceRefs']) { return $repair; }
        $record = $records[0];
        foreach ($facts as $fact) {
            if (($fact['kind'] ?? null) !== 'price' || ($fact['decimal'] ?? null) !== $record['price']
                || ($fact['currency'] ?? null) !== 'RUB' || ($fact['perUnit'] ?? null) !== $record['price_basis']
                || ($fact['provenance']['sourceGenerationRef'] ?? null) !== $selection['source_generation_ref']
                || !in_array($fact['provenance']['unitRef'] ?? null, $scope['unitRefs'] ?? [], true)) { continue; }
            foreach ($facts as $title) {
                if (($title['kind'] ?? null) !== 'text' || ($title['value']['utf8Text'] ?? null) !== $record['title']
                    || ($title['provenance']['fragmentVersion'] ?? null) !== 'synthetic-material-field/title/1'
                    || ($title['provenance']['unitRef'] ?? null) !== $fact['provenance']['unitRef']
                    || ($title['provenance']['sourceGenerationRef'] ?? null) !== $selection['source_generation_ref']) { continue; }
                if ($selection['input_id'] === 'quote-12m3') {
                    // This selector has one immutable integer quantity; no model arithmetic is trusted.
                    $minor = (int) str_replace('.', '', $record['price']) * 12;
                    $total = intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
                    $text = 'Стоимость 12 м³ '.$record['title'].' по учебному каталогу: '.$total.' RUB.';
                    return array_key_exists('unit', $claim) && $claim['unit'] === null && ($claim['value'] ?? null) === $total
                        && $value['text'] === $text ? $valid : $repair;
                }
                $unit = $record['price_basis'] === 'm3' ? 'м³' : 'кг';
                $text = $record['title'].' стоит '.$record['price'].' RUB за '.$unit.'.';
                return ($claim['unit'] ?? null) === $record['price_basis'] && ($claim['value'] ?? null) === $record['price']
                    && $value['text'] === $text ? $valid : $repair;
            }
        }
        return $repair;
    }

    public static function serve(string $path): void
    {
        if (! AuthenticatedPublicCoreChannel::isNativeAvailable()
            || posix_geteuid() !== 41002 || posix_getegid() !== 41002) {
            throw new LogicException('gateway_identity_unavailable');
        }
        ParkedRoleBootstrap::assertReleased('processor');
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
        fwrite(STDOUT, "Usage: php docker/public-core/runtime.php metadata|gateway|processor [protected-role-config]\n       php docker/public-core/runtime.php provision-credentials RELEASE_SHA (exact-image main CI only)\n       php docker/public-core/runtime.php publish-projections RELEASE_SHA IMAGE_DIGEST (root, parked roles, checked inputs only)\n       php docker/public-core/runtime.php parked-gateway|parked-processor (bounded 30-second same-PID wait)\n");
        exit(0);
    }
    if (! in_array($command, ['metadata', 'gateway', 'processor', 'provision-credentials', 'publish-projections', 'parked-gateway', 'parked-processor'], true) || count($argv) > 4) {
        fwrite(STDERR, "public-core: invalid_command\n");
        exit(64);
    }
    try {
        require_once dirname(__DIR__, 2).'/vendor/autoload.php';
        if ($command === 'publish-projections') {
            $release = json_decode(file_get_contents('/etc/most/release.json'), true, 8, JSON_THROW_ON_ERROR);
            if (count($argv) !== 4 || ($release['sha'] ?? null) !== $argv[2]) { throw new LogicException('runtime_not_activated'); }
            $published = RoleProjectionPublisher::publish('/etc/most/public-core', $argv[2], $argv[3]);
            fwrite(STDOUT, $published ? "public-core: projections_prepared_not_activated\n" : "public-core: inactive_inputs_unavailable\n");
            exit(0);
        }
        if (str_starts_with($command, 'parked-')) {
            if (count($argv) !== 2) { throw new LogicException('runtime_not_activated'); }
            $role = substr($command, 7); ParkedRoleBootstrap::wait($role);
            $command = $role;
        }
        if (count($argv) > 3) { throw new LogicException('runtime_not_activated'); }
        if ($command === 'provision-credentials') {
            $release = json_decode(file_get_contents('/etc/most/release.json'), true, 8, JSON_THROW_ON_ERROR);
            if (count($argv) !== 3 || preg_match('/\A[0-9a-f]{40}\z/D', $argv[2]) !== 1
                || ($release['sha'] ?? null) !== $argv[2]) { throw new LogicException('runtime_not_activated'); }
            RoleCredentialProvisioner::provision('/run/most-ci/environment', '/etc/most/public-core');
            fwrite(STDOUT, "public-core: custody_source_prepared\n");
            exit(0);
        }
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
