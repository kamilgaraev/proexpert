<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Workflow;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SessionSnapshotEtag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SessionSnapshotEtagTest extends TestCase
{
    #[Test]
    public function tag_is_tenant_scoped_and_uses_valid_strong_syntax(): void
    {
        $etag = SessionSnapshotEtag::forRevision(17, 41, 'sha256:'.str_repeat('a', 64));

        self::assertMatchesRegularExpression('/^"eg-snapshot-v5-17-41-[0-9a-f]{64}"$/', $etag);
        self::assertFalse(SessionSnapshotEtag::matches(
            '"eg-snapshot-17-41-'.hash('sha256', "17\0"."41\0".'sha256:'.str_repeat('a', 64)).'"',
            $etag,
        ));
        self::assertNotSame($etag, SessionSnapshotEtag::forRevision(18, 41, 'sha256:'.str_repeat('a', 64)));
        self::assertNotSame($etag, SessionSnapshotEtag::forRevision(17, 42, 'sha256:'.str_repeat('a', 64)));
    }

    #[Test]
    #[DataProvider('matchingHeaders')]
    public function if_none_match_uses_weak_comparison_and_http_list_semantics(string $header): void
    {
        self::assertTrue(SessionSnapshotEtag::matches($header, '"eg-snapshot-17-41-abc"'));
    }

    public static function matchingHeaders(): array
    {
        return [
            'exact' => ['"eg-snapshot-17-41-abc"'],
            'weak' => ['W/"eg-snapshot-17-41-abc"'],
            'list' => ['"other", W/"eg-snapshot-17-41-abc", "last"'],
            'star' => ['*'],
            'whitespace' => ['  W/"eg-snapshot-17-41-abc"  '],
        ];
    }

    #[Test]
    #[DataProvider('nonMatchingHeaders')]
    public function malformed_or_different_headers_do_not_match(?string $header): void
    {
        self::assertFalse(SessionSnapshotEtag::matches($header, '"eg-snapshot-17-41-abc"'));
    }

    public static function nonMatchingHeaders(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'unquoted' => ['eg-snapshot-17-41-abc'],
            'unterminated' => ['"eg-snapshot-17-41-abc'],
            'different' => ['W/"eg-snapshot-17-41-def"'],
            'invalid wildcard list' => ['*, "other"'],
        ];
    }
}
