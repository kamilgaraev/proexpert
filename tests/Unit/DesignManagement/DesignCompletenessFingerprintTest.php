<?php

declare(strict_types=1);

namespace Tests\Unit\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Support\DesignCompletenessFingerprint;
use JsonException;
use PHPUnit\Framework\TestCase;

final class DesignCompletenessFingerprintTest extends TestCase
{
    public function test_streamed_rows_keep_native_json_bytes(): void
    {
        $datasets = ['sections' => [['id' => 7, 'metadata' => ['text' => 'Проверка / Unicode', 'values' => [null, true, false, 1.0, 0.125, 9007199254740991]]], []],
            'artifacts' => [], 'versions' => [['revision' => null]], 'sheets' => [],
            'comments' => [['body' => "line\nbreak"]], 'issues' => [], 'exclusions' => []];

        self::assertSame(hash('sha256', json_encode($datasets, JSON_THROW_ON_ERROR)), $this->stream($datasets));
    }

    public function test_null_and_empty_datasets_remain_distinct(): void
    {
        $empty = ['sections' => [], 'artifacts' => [], 'versions' => [], 'sheets' => [], 'comments' => [], 'issues' => [], 'exclusions' => []];
        $missing = array_replace($empty, ['exclusions' => null]);

        self::assertSame(hash('sha256', json_encode($empty, JSON_THROW_ON_ERROR)), $this->stream($empty));
        self::assertSame(hash('sha256', json_encode($missing, JSON_THROW_ON_ERROR)), $this->stream($missing));
        self::assertNotSame($this->stream($empty), $this->stream($missing));
    }

    public function test_streaming_preserves_native_envelope_depth_limit(): void
    {
        for ($depth = 504; $depth <= 514; $depth++) {
            $nested = 'value';
            for ($level = 0; $level < $depth; $level++) {
                $nested = ['child' => $nested];
            }
            $datasets = ['sections' => [['id' => 1, 'metadata' => $nested]], 'artifacts' => [], 'versions' => [],
                'sheets' => [], 'comments' => [], 'issues' => [], 'exclusions' => []];
            $expectedError = null;
            $expected = null;
            try {
                $expected = hash('sha256', json_encode($datasets, JSON_THROW_ON_ERROR));
            } catch (JsonException $error) {
                $expectedError = $error->getCode();
            }
            try {
                $actual = $this->stream($datasets);
                self::assertNull($expectedError, 'Native encoder rejected depth '.$depth);
                self::assertSame($expected, $actual);
            } catch (JsonException $error) {
                self::assertNotNull($expectedError, 'Streaming encoder rejected accepted depth '.$depth);
                self::assertSame($expectedError, $error->getCode());
            }
        }
    }

    public function test_streaming_preserves_invalid_utf8_exception(): void
    {
        $datasets = ['sections' => [['metadata' => ['text' => "\xB1\x31"]]], 'artifacts' => [], 'versions' => [],
            'sheets' => [], 'comments' => [], 'issues' => [], 'exclusions' => []];
        try {
            json_encode($datasets, JSON_THROW_ON_ERROR);
            self::fail('Native JSON encoder must reject invalid UTF-8');
        } catch (JsonException $error) {
            self::assertSame(JSON_ERROR_UTF8, $error->getCode());
        }

        $this->expectException(JsonException::class);
        $this->expectExceptionCode(JSON_ERROR_UTF8);
        $this->stream($datasets);
    }

    private function stream(array $datasets): string
    {
        $fingerprint = new DesignCompletenessFingerprint;
        foreach ($datasets as $name => $rows) {
            $fingerprint->beginDataset($name, $rows === null);
            foreach ($rows ?? [] as $row) {
                $fingerprint->append($row);
            }
            $fingerprint->endDataset();
        }

        return $fingerprint->finish();
    }
}
