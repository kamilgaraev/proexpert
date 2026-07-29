<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Cursors;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSortDirection;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Infrastructure\Cursors\SignedReportCursorCodec;
use DateInterval;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Reporting\FakeReportExecutionClock;
use Tests\Support\Reporting\ReportRunBuilder;

final class SignedReportCursorCodecTest extends TestCase
{
    private const RUN_ID = '01J00000000000000000000000';

    private DateTimeImmutable $now;

    private FakeReportExecutionClock $clock;

    private SignedReportCursorCodec $codec;

    private ReportWindowSort $sort;

    private Sha256Hash $queryHash;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2030-01-01T00:00:00+00:00');
        $this->clock = new FakeReportExecutionClock($this->now);
        $this->codec = new SignedReportCursorCodec(
            ['cursor-v1' => str_repeat('a', 64), 'cursor-v0' => str_repeat('b', 64)],
            'cursor-v1',
            $this->clock,
        );
        $this->sort = new ReportWindowSort('name', ReportSortDirection::ASC);
        $this->queryHash = new Sha256Hash(str_repeat('b', 64));
    }

    public function test_round_trip_returns_locked_plan_one_a_cursor(): void
    {
        $token = $this->token();

        $cursor = $this->decode($token);

        self::assertSame($token, $cursor->token);
        self::assertSame(self::RUN_ID, $cursor->runId);
        self::assertSame($this->queryHash->value, $cursor->queryHash->value);
        self::assertSame(str_repeat('c', 64), $cursor->sourceHash->value);
        self::assertSame('name', $cursor->sort->field);
        self::assertSame(ReportSortDirection::ASC, $cursor->sort->direction);
        self::assertSame('2030-01-01T00:05:00+00:00', $cursor->expiresAt->format(DATE_ATOM));
    }

    public function test_key_rotation_accepts_a_cursor_signed_by_a_still_trusted_old_key(): void
    {
        $oldCodec = new SignedReportCursorCodec(
            ['cursor-v1' => str_repeat('a', 64), 'cursor-v0' => str_repeat('b', 64)],
            'cursor-v0',
            $this->clock,
        );

        self::assertSame(self::RUN_ID, $this->decode($this->token($oldCodec))->runId);
    }

    #[DataProvider('identityMismatch')]
    public function test_rejects_wrong_immutable_identity(string $field, mixed $wrongValue): void
    {
        $token = $this->token();

        $this->expectCursorFailure(function () use ($field, $wrongValue, $token): void {
            $arguments = [
                'organizationId' => 1,
                'reportCode' => 'report',
                'runId' => self::RUN_ID,
                'snapshot' => (new ReportRunBuilder)->ready()->resultMetadata->snapshot,
                'queryHash' => $this->queryHash,
                'sort' => $this->sort,
            ];
            $arguments[$field] = $wrongValue;
            $this->codec->decode($token, ...$arguments);
        });
    }

    public static function identityMismatch(): iterable
    {
        yield 'organization' => ['organizationId', 2];
        yield 'report' => ['reportCode', 'other_report'];
        yield 'run' => ['runId', '01J00000000000000000000001'];
        yield 'snapshot' => [
            'snapshot',
            (new ReportRunBuilder)->sourceHash(new Sha256Hash(str_repeat('d', 64)))->ready()->resultMetadata->snapshot,
        ];
        yield 'query' => ['queryHash', new Sha256Hash(str_repeat('d', 64))];
        yield 'sort field' => ['sort', new ReportWindowSort('amount', ReportSortDirection::ASC)];
        yield 'sort direction' => ['sort', new ReportWindowSort('name', ReportSortDirection::DESC)];
    }

    public function test_rejects_invalid_signature(): void
    {
        $token = $this->token();
        $tampered = substr($token, 0, -1).($token[-1] === 'A' ? 'B' : 'A');

        $this->expectCursorFailure(fn () => $this->decode($tampered));
    }

    public function test_rejects_key_rotation_miss(): void
    {
        $token = $this->token();
        $rotated = new SignedReportCursorCodec(
            ['cursor-v2' => str_repeat('d', 64)],
            'cursor-v2',
            $this->clock,
        );

        $this->expectCursorFailure(fn () => $this->decode($token, $rotated));
    }

    public function test_rejects_expired_cursor(): void
    {
        $token = $this->token();
        $this->clock->advance(new DateInterval('PT5M1S'));

        $this->expectCursorFailure(fn () => $this->decode($token));
    }

    public function test_rejects_oversized_stable_row_key_before_emitting_an_undecodable_token(): void
    {
        $this->expectCursorFailure(fn () => $this->codec->encode(
            organizationId: 1,
            reportCode: 'report',
            runId: self::RUN_ID,
            snapshot: (new ReportRunBuilder)->ready()->resultMetadata->snapshot,
            queryHash: $this->queryHash,
            sort: $this->sort,
            lastSortValue: 'ООО Альфа',
            lastStableRowKey: str_repeat('r', 257),
            expiresAt: $this->now->modify('+5 minutes'),
        ));
    }

    public function test_every_emitted_cursor_respects_transport_limit_and_decodes_symmetrically(): void
    {
        $token = $this->codec->encode(
            organizationId: 1,
            reportCode: 'report',
            runId: self::RUN_ID,
            snapshot: (new ReportRunBuilder)->ready()->resultMetadata->snapshot,
            queryHash: $this->queryHash,
            sort: $this->sort,
            lastSortValue: str_repeat('v', 300),
            lastStableRowKey: str_repeat('r', 256),
            expiresAt: $this->now->modify('+5 minutes'),
        );

        self::assertLessThanOrEqual(2048, strlen($token));
        self::assertSame(self::RUN_ID, $this->decode($token)->runId);
    }

    public function test_rejects_signed_payload_with_noncanonical_timestamp(): void
    {
        [$encodedPayload] = explode('.', $this->token(), 2);
        $payload = json_decode($this->base64UrlDecode($encodedPayload), true, 64, JSON_THROW_ON_ERROR);
        $payload['issued_at'] = 'tomorrow';
        $encodedPayload = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $encodedPayload, str_repeat('a', 64), true));

        $this->expectCursorFailure(fn () => $this->decode($encodedPayload.'.'.$signature));
    }

    #[DataProvider('malformedTokens')]
    public function test_rejects_malformed_payload(string $token): void
    {
        $this->expectCursorFailure(fn () => $this->decode($token));
    }

    public static function malformedTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'one segment' => ['abc'];
        yield 'too many segments' => ['a.b.c'];
        yield 'invalid base64' => ['**.**'];
    }

    public function test_drill_down_page_cursor_round_trip_is_bound_to_parent_row(): void
    {
        $snapshot = (new ReportRunBuilder)->ready()->resultMetadata->snapshot;
        $token = $this->codec->encodeDrillDownPage(
            organizationId: 1,
            reportCode: 'report',
            runId: self::RUN_ID,
            snapshot: $snapshot,
            queryHash: $this->queryHash,
            parentRowKey: 'parent-row',
            lastStableRowKey: 'evidence-row-10',
            expiresAt: $this->now->modify('+5 minutes'),
        );

        self::assertSame(
            'evidence-row-10',
            $this->codec->decodeDrillDownPage(
                token: $token,
                organizationId: 1,
                reportCode: 'report',
                runId: self::RUN_ID,
                snapshot: $snapshot,
                queryHash: $this->queryHash,
                parentRowKey: 'parent-row',
            ),
        );
    }

    public function test_drill_down_page_cursor_rejects_another_parent_row(): void
    {
        $snapshot = (new ReportRunBuilder)->ready()->resultMetadata->snapshot;
        $token = $this->codec->encodeDrillDownPage(
            organizationId: 1,
            reportCode: 'report',
            runId: self::RUN_ID,
            snapshot: $snapshot,
            queryHash: $this->queryHash,
            parentRowKey: 'parent-row',
            lastStableRowKey: 'evidence-row-10',
            expiresAt: $this->now->modify('+5 minutes'),
        );

        $this->expectCursorFailure(fn () => $this->codec->decodeDrillDownPage(
            token: $token,
            organizationId: 1,
            reportCode: 'report',
            runId: self::RUN_ID,
            snapshot: $snapshot,
            queryHash: $this->queryHash,
            parentRowKey: 'another-parent',
        ));
    }

    private function token(?SignedReportCursorCodec $codec = null): string
    {
        return ($codec ?? $this->codec)->encode(
            organizationId: 1,
            reportCode: 'report',
            runId: self::RUN_ID,
            snapshot: (new ReportRunBuilder)->ready()->resultMetadata->snapshot,
            queryHash: $this->queryHash,
            sort: $this->sort,
            lastSortValue: 'ООО Альфа',
            lastStableRowKey: 'row-10',
            expiresAt: $this->now->modify('+5 minutes'),
        );
    }

    private function decode(string $token, ?SignedReportCursorCodec $codec = null)
    {
        return ($codec ?? $this->codec)->decode(
            token: $token,
            organizationId: 1,
            reportCode: 'report',
            runId: self::RUN_ID,
            snapshot: (new ReportRunBuilder)->ready()->resultMetadata->snapshot,
            queryHash: $this->queryHash,
            sort: $this->sort,
        );
    }

    private function expectCursorFailure(callable $callback): void
    {
        try {
            $callback();
            self::fail('Ожидалось отклонение cursor.');
        } catch (ReportContractException $exception) {
            self::assertSame(ReportErrorCode::REPORT_CURSOR_INVALID, $exception->errorCode);
            self::assertSame(['fields' => ['cursor']], $exception->safeFields);
        }
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        return base64_decode(
            strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4),
            true,
        );
    }
}
