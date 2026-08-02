<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use Generator;
use InvalidArgumentException;

final class DeterministicObjectSpool
{
    private mixed $stream;

    private mixed $hash;

    private mixed $identityStream;

    private int $count = 0;

    public function __construct()
    {
        $this->stream = tmpfile();
        if (! is_resource($this->stream)) {
            throw new InvalidArgumentException('report_spool_unavailable');
        }
        $this->identityStream = tmpfile();
        if (! is_resource($this->identityStream)) {
            throw new InvalidArgumentException('report_spool_unavailable');
        }
        $this->hash = hash_init('sha256');
        hash_update($this->hash, '[');
    }

    public function append(object $value, array $canonicalIdentity): void
    {
        $encoded = base64_encode(serialize($value))."\n";
        if (fwrite($this->stream, $encoded) !== strlen($encoded)) {
            throw new InvalidArgumentException('report_spool_write_failed');
        }
        $canonical = CanonicalJson::encode($canonicalIdentity);
        if (fwrite($this->identityStream, $canonical."\n") !== strlen($canonical) + 1) {
            throw new InvalidArgumentException('report_spool_write_failed');
        }
        if ($this->count > 0) {
            hash_update($this->hash, ',');
        }
        hash_update($this->hash, $canonical);
        $this->count++;
    }

    public function count(): int
    {
        return $this->count;
    }

    public function sha256(): string
    {
        $copy = hash_copy($this->hash);
        hash_update($copy, ']');

        return hash_final($copy);
    }

    public function items(): Generator
    {
        rewind($this->stream);
        while (($line = fgets($this->stream)) !== false) {
            $value = unserialize(base64_decode(trim($line), true), ['allowed_classes' => true]);
            if (! is_object($value)) {
                throw new InvalidArgumentException('report_spool_payload_invalid');
            }
            yield $value;
        }
    }

    public function updateCanonicalArrayHash(mixed $context): void
    {
        hash_update($context, '[');
        rewind($this->identityStream);
        $index = 0;
        while (($line = fgets($this->identityStream)) !== false) {
            if ($index++ > 0) {
                hash_update($context, ',');
            }
            hash_update($context, rtrim($line, "\r\n"));
        }
        hash_update($context, ']');
    }

    public function __destruct()
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
        if (is_resource($this->identityStream)) {
            fclose($this->identityStream);
        }
    }
}
