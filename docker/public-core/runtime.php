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

/** A pinned role projection: every use rechecks bytes, inode and protected parents. */
final class ProtectedRoleFile
{
    /** @var array<string, string> */
    private array $pins = [];

    public function __construct(private readonly string $directory) {}

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
            || $before['uid'] !== 0 || $before['gid'] !== posix_getegid() || ($before['mode'] & 0037) !== 0
            || ($parent['mode'] & 0170000) !== 0040000 || $parent['uid'] !== 0
            || $parent['gid'] !== posix_getegid() || ($parent['mode'] & 0027) !== 0
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

    public function __construct(string $directory) { $this->files = new ProtectedRoleFile($directory); }

    public function files(): ProtectedRoleFile { return $this->files; }

    public function profile(): \App\Services\Privacy\Gateway\Contracts\GatewayModelProfile
    {
        $profile = \App\Services\Privacy\Gateway\Contracts\GatewayModelProfile::fromArray($this->files->json('profile.json'));
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
        $pattern = $this->files->bytes('pattern.txt', 8192);
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
            $this->files->bytes('vocabulary.tiktoken', 16777216); $this->files->bytes('pattern.txt', 8192);
            $v = $selected->values();
            return ['inputTokens' => count($encoder->encode($bytes)), 'tokenizerId' => $v['tokenizerId'],
                'tokenizerRevision' => $v['tokenizerRevision'], 'mappingEvidenceRef' => $v['mappingEvidenceRef']];
        };
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
        if (!is_string($value['text'] ?? null) || !is_array($value['claims'] ?? null) || $value['claims'] === [] || $evidence === []) {
            return ['status' => 'repair', 'reason' => 'claims_invalid'];
        }
        $latest = $evidence[array_key_last($evidence)];
        $facts = $latest['envelope']['facts'] ?? null;
        if (!is_array($facts) || count($value['claims']) !== 1) { return ['status' => 'repair', 'reason' => 'claims_invalid']; }
        $claim = $value['claims'][0];
        foreach ($facts as $fact) {
            if (($fact['kind'] ?? null) !== 'price'
                || ($fact['decimal'] ?? null) !== ($claim['value'] ?? null)
                || ($fact['currency'] ?? null) !== ($claim['currency'] ?? null)
                || ($fact['perUnit'] ?? null) !== ($claim['unit'] ?? null)) { continue; }
            $unit = match ($claim['unit']) { 'm3' => 'м³', 'kg' => 'кг', 'm2' => 'м²', 'item' => 'шт.', default => null };
            if ($unit === null || $claim['currency'] !== 'RUB') { continue; }
            foreach ($facts as $title) {
                if (($title['kind'] ?? null) !== 'text'
                    || ($title['provenance']['fragmentVersion'] ?? null) !== 'synthetic-material-field/title/1'
                    || ($title['provenance']['unitRef'] ?? null) !== ($fact['provenance']['unitRef'] ?? null)
                    || ($title['provenance']['sourceGenerationRef'] ?? null) !== ($fact['provenance']['sourceGenerationRef'] ?? null)
                    || !is_string($title['value']['utf8Text'] ?? null)) { continue; }
                $text = $title['value']['utf8Text'].' стоит '.$claim['value'].' RUB за '.$unit.'.';
                if ($value['text'] === $text) { return ['status' => 'valid', 'reason' => 'none']; }
            }
        }
        return ['status' => 'repair', 'reason' => 'claims_invalid'];
    }

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
        fwrite(STDOUT, "Usage: php docker/public-core/runtime.php metadata|gateway|processor [protected-role-config]\n       php docker/public-core/runtime.php provision-credentials RELEASE_SHA (exact-image main CI only)\n");
        exit(0);
    }
    if (! in_array($command, ['metadata', 'gateway', 'processor', 'provision-credentials'], true) || count($argv) > 3) {
        fwrite(STDERR, "public-core: invalid_command\n");
        exit(64);
    }
    try {
        require_once dirname(__DIR__, 2).'/vendor/autoload.php';
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
