<?php

declare(strict_types=1);

namespace Tests\Unit\Privacy;

use App\Services\Privacy\Contracts\PrivacyDecision;
use App\Services\Privacy\PrivateProjectionFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/PrivateProjectionTest.php';

final class PrivacyDecisionTest extends TestCase
{
    public function testReadyDecisionNeverSerializesItsPrivatePayload(): void
    {
        $private = (new PrivateProjectionFactory(new TestProjectionAuthority()))->create(PrivateProjectionTest::input())->value();
        self::assertIsObject($private);
        $decision = PrivacyDecision::ready($private);
        self::assertSame($private, $decision->value());
        $export = json_decode(json_encode($decision, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['schemaVersion', 'status', 'reason', 'correlationRef'], array_keys($export));
        self::assertSame('ready', $export['status']);
        self::assertMatchesRegularExpression('/^ref_[a-f0-9]{32}$/D', $export['correlationRef']);
    }

    public function testDependencyErrorDoesNotExposeExceptionOrInput(): void
    {
        $authority = new TestProjectionAuthority();
        $authority->unavailable = true;
        $decision = (new PrivateProjectionFactory($authority))->create(PrivateProjectionTest::input('PRIVATE user question'));
        self::assertSame('blocked', $decision->status());
        self::assertSame('dependency_unavailable', $decision->reason());
        $json = json_encode($decision, JSON_THROW_ON_ERROR);
        foreach (['SECRET', 'PRIVATE', 'SQL', 'passport', '/private/customer', 'exception', 'body'] as $private) {
            self::assertStringNotContainsString($private, $json);
        }
    }

    public function testFreeformExceptionTextCannotBecomeAReasonCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported_privacy_reason');
        PrivacyDecision::blocked('SELECT raw private person');
    }

    public function testStaleAndUnsupportedDecisionsContainNoReadyValue(): void
    {
        foreach ([PrivacyDecision::stale(), PrivacyDecision::unsupported(), PrivacyDecision::blocked()] as $decision) {
            self::assertFalse($decision->isReady());
            self::assertNull($decision->value());
        }
        self::assertSame('stale', PrivacyDecision::stale()->status());
        self::assertSame('unsupported', PrivacyDecision::unsupported()->status());
    }
}
