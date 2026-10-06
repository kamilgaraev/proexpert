<?php

declare(strict_types=1);

namespace App\Services\Privacy\PublicCore;

use Closure;
use RuntimeException;
use Throwable;

final class PublicCoreReceiptStore
{
    private ?Closure $publicationBinding = null;

    public function __construct(private readonly ?string $directory = null, private readonly ?string $controlKey = null)
    {
    }

    public function available(): bool
    {
        return $this->directory !== null && $this->directory !== ''
            && $this->controlKey !== null && strlen($this->controlKey) >= 32;
    }

    public function ownerDigest(array $authorization): ?string
    {
        return $this->available() ? hash_hmac('sha256', RegisteredPublicFixtureRegistry::canonical($authorization), $this->controlKey) : null;
    }

    public function transaction(Closure $operation): ?array
    {
        if (!$this->available()) {
            return null;
        }
        $lock = null;
        try {
            if (is_link($this->directory) || (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true))) {
                return null;
            }
            $lockPath = $this->directory . '/authority.lock';
            $statePath = $this->directory . '/authority.json';
            if (is_link($lockPath) || is_link($statePath)) {
                return null;
            }
            $lock = @fopen($lockPath, 'c+b');
            if ($lock === false || !@chmod($lockPath, 0600) || !@flock($lock, LOCK_EX)) {
                return null;
            }
            $state = ['schemaVersion' => 'public-core-ledger/1', 'sessions' => [], 'requests' => [], 'receipts' => []];
            if (is_file($statePath)) {
                $bytes = @file_get_contents($statePath);
                if ($bytes === false || strlen($bytes) > 1048576) {
                    return null;
                }
                $envelope = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
                if (!is_array($envelope) || array_keys($envelope) !== ['state', 'mac']
                    || !is_array($envelope['state']) || !is_string($envelope['mac'])
                    || !hash_equals(hash_hmac('sha256', RegisteredPublicFixtureRegistry::canonical($envelope['state']), $this->controlKey), $envelope['mac'])
                    || array_keys($envelope['state']) !== ['schemaVersion', 'sessions', 'requests', 'receipts']
                    || $envelope['state']['schemaVersion'] !== 'public-core-ledger/1') {
                    return null;
                }
                $state = $envelope['state'];
            }
            $before = RegisteredPublicFixtureRegistry::canonical($state);
            $result = $operation($state);
            if ($before !== RegisteredPublicFixtureRegistry::canonical($state)) {
                $this->persist($statePath, $state);
            }
            return is_array($result) ? self::owned($result) : null;
        } catch (Throwable) {
            return null;
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function persist(string $statePath, array $state): void
    {
        $envelope = ['state' => $state, 'mac' => hash_hmac('sha256', RegisteredPublicFixtureRegistry::canonical($state), $this->controlKey)];
        $bytes = RegisteredPublicFixtureRegistry::canonical($envelope);
        if (strlen($bytes) > 1048576) {
            throw new RuntimeException('receipt_unavailable');
        }
        $temporary = $statePath . '.' . bin2hex(random_bytes(16)) . '.tmp';
        $file = null;
        try {
            $file = @fopen($temporary, 'x+b');
            if ($file === false || !@chmod($temporary, 0600) || @fwrite($file, $bytes) !== strlen($bytes) || !@fflush($file) || !@fsync($file)) {
                throw new RuntimeException('receipt_unavailable');
            }
            fclose($file);
            $file = null;
            if (!@rename($temporary, $statePath)) {
                throw new RuntimeException('receipt_unavailable');
            }
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function bound(Closure $publicationBinding): self
    {
        $bound = new self($this->directory, $this->controlKey);
        $bound->publicationBinding = $publicationBinding;
        return $bound;
    }

    public function publish(string $event, array $data = [], array $expected = []): array
    {
        if ($this->publicationBinding === null || !in_array($event, ['lineage', 'stage', 'commit', 'abort', 'final_guard'], true)) {
            return [];
        }
        return $this->transaction(function (array &$state) use ($event, $data, $expected): array {
            $binding = ($this->publicationBinding)($state);
            if (!is_array($binding) || !$this->validBinding($binding)) {
                return [];
            }
            $lineage = $binding['lineage'];
            if ($event === 'lineage') {
                return $expected === [] && array_keys($data) === ['scope', 'conversationRef']
                    && $data['scope'] === $binding['scope'] && $data['conversationRef'] === $lineage['conversationRef'] ? $lineage : [];
            }
            if (!$this->validReceipt($data, $expected, $binding)) {
                return [];
            }
            $ref = $data['contextRef'];
            $saved = $state['receipts'][$ref] ?? null;
            if ($event === 'abort') {
                if ($saved !== null && $saved['expected'] === $expected && $saved['requestRef'] === $lineage['requestRef']) {
                    unset($state['receipts'][$ref]);
                }
                return [];
            }
            if ($event === 'stage') {
                if ($saved !== null || count($state['receipts']) >= 256) {
                    return [];
                }
                $state['receipts'][$ref] = [
                    'status' => 'staged', 'requestRef' => $lineage['requestRef'], 'expected' => self::owned($expected),
                    'receipt' => self::owned($data), 'snapshotHash' => $binding['snapshotHash'],
                    'profileFingerprint' => $binding['profileFingerprint'], 'registryDigest' => $binding['registryDigest'],
                ];
                return ['schemaVersion' => 'assistant-context-receipt-ack/1', 'status' => 'staged'] + $expected;
            }
            if ($saved === null || $saved['expected'] !== $expected || $saved['receipt'] !== $data
                || $saved['snapshotHash'] !== $binding['snapshotHash']
                || $saved['profileFingerprint'] !== $binding['profileFingerprint']
                || $saved['registryDigest'] !== $binding['registryDigest'] || $saved['requestRef'] !== $lineage['requestRef']) {
                return [];
            }
            if ($event === 'commit') {
                if ($saved['status'] !== 'staged') {
                    return [];
                }
                $state['receipts'][$ref]['status'] = 'committed';
                return ['schemaVersion' => 'assistant-context-receipt-ack/1', 'status' => 'committed'] + $expected;
            }
            if ($saved['status'] !== 'committed') {
                return [];
            }
            return ['schemaVersion' => 'assistant-context-final-guard/1', 'status' => 'committed'] + $expected + [
                'snapshotHash' => $binding['snapshotHash'], 'profileFingerprint' => $binding['profileFingerprint'], 'lineage' => $lineage,
            ];
        }) ?? [];
    }

    public function authority(?string $contextRef): ?array
    {
        if ($contextRef === null || $this->publicationBinding === null) {
            return null;
        }
        return $this->transaction(function (array &$state) use ($contextRef): ?array {
            $saved = $state['receipts'][$contextRef] ?? null;
            $binding = ($this->publicationBinding)($state);
            if ($saved === null || $saved['status'] !== 'committed' || !is_array($binding)
                || !$this->validBinding($binding) || !$this->validReceipt($saved['receipt'], $saved['expected'], $binding)
                || $saved['snapshotHash'] !== $binding['snapshotHash'] || $saved['registryDigest'] !== $binding['registryDigest']) {
                return null;
            }
            return ['receipt' => $saved['receipt'], 'expected' => $saved['expected'], 'binding' => $binding];
        });
    }

    private function validBinding(array $binding): bool
    {
        $keys = ['lineage', 'scope', 'snapshotHash', 'profileFingerprint', 'registryDigest', 'aliases', 'sources'];
        if (array_keys($binding) !== $keys || !is_array($binding['lineage']) || !is_array($binding['scope'])
            || !is_array($binding['aliases']) || !is_array($binding['sources'])) {
            return false;
        }
        $lineage = $binding['lineage'];
        if (array_keys($lineage) !== ['requestRef', 'requestRevision', 'conversationRef', 'issuedAt', 'expiresAt', 'now']) {
            return false;
        }
        foreach (['requestRef', 'requestRevision', 'conversationRef'] as $key) {
            if (!is_string($lineage[$key]) || $lineage[$key] === '' || strlen($lineage[$key]) > 160) {
                return false;
            }
        }
        foreach (['issuedAt', 'expiresAt', 'now'] as $key) {
            if (!is_int($lineage[$key])) {
                return false;
            }
        }
        foreach (['snapshotHash', 'profileFingerprint', 'registryDigest'] as $key) {
            if (!is_string($binding[$key]) || preg_match('/^[a-f0-9]{64}$/D', $binding[$key]) !== 1) {
                return false;
            }
        }
        return $lineage['issuedAt'] <= $lineage['now'] && $lineage['now'] < $lineage['expiresAt']
            && $lineage['expiresAt'] - $lineage['issuedAt'] <= 300;
    }

    private function validReceipt(array $data, array $expected, array $binding): bool
    {
        $keys = ['schemaVersion', 'contextRef', 'currentRef', 'payloadDigest', 'scopeHash', 'scope', 'conversationRef',
            'profileRef', 'profileFingerprint', 'modelProfile', 'aliases', 'sources', 'lineage'];
        if (array_keys($data) !== $keys || array_keys($expected) !== ['contextRef', 'payloadDigest', 'receiptDigest']
            || $data['schemaVersion'] !== 'assistant-context-receipt/1' || !is_string($data['contextRef'])
            || $data['contextRef'] === '' || strlen($data['contextRef']) > 160 || !is_string($data['payloadDigest'])
            || preg_match('/^[a-f0-9]{64}$/D', $data['payloadDigest']) !== 1
            || $expected['contextRef'] !== $data['contextRef'] || $expected['payloadDigest'] !== $data['payloadDigest']
            || $expected['receiptDigest'] !== hash('sha256', RegisteredPublicFixtureRegistry::canonical($data))
            || $data['scope'] !== $binding['scope'] || $data['scopeHash'] !== hash('sha256', RegisteredPublicFixtureRegistry::canonical($binding['scope']))
            || $data['conversationRef'] !== $binding['lineage']['conversationRef']
            || $data['lineage'] !== array_diff_key($binding['lineage'], ['now' => true])
            || $data['profileFingerprint'] !== $binding['profileFingerprint']
            || $data['aliases'] !== $binding['aliases'] || $data['sources'] !== $binding['sources']) {
            return false;
        }
        if (!is_array($data['modelProfile'])
            || array_keys($data['modelProfile']) !== ['profileRef', 'qualification', 'adapterRevision', 'modelId',
                'modelRevision', 'tokenizerId', 'tokenizerRevision', 'contextWindow', 'maxOutputTokens', 'answerReserve', 'toolReserve']
            || $data['modelProfile']['qualification'] !== 'offline-synthetic'
            || $data['profileRef'] !== $data['modelProfile']['profileRef']
            || hash('sha256', RegisteredPublicFixtureRegistry::canonical($data['modelProfile'])) !== $binding['profileFingerprint']) {
            return false;
        }
        return true;
    }

    public static function owned(array $value): array
    {
        return json_decode(RegisteredPublicFixtureRegistry::canonical($value), true, 64, JSON_THROW_ON_ERROR);
    }
}
