<?php

declare(strict_types=1);

namespace Tests\Unit\Privacy\Gateway;

use LogicException;
use JsonException;
use Most\PublicCore\GatewayRuntimeBootstrap;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 4).'/docker/public-core/runtime.php';

final class PublicCoreRuntimeBootstrapTest extends TestCase
{
    private function example(): string
    {
        return dirname(__DIR__, 4).'/deploy/public-core-runtime.json.example';
    }

    public function testInactiveManifestCannotStartGateway(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('runtime_not_activated');
        GatewayRuntimeBootstrap::serve($this->example());
    }

    public function testMetadataInspectionCannotQualifyAnActualRuntime(): void
    {
        $metadata = GatewayRuntimeBootstrap::inspect($this->example());
        self::assertSame('inactive', $metadata['configurationState']);
        self::assertFalse($metadata['actualRuntimeVerified']);
        self::assertFalse($metadata['actualModelVerified']);
        self::assertArrayNotHasKey('credentialFile', $metadata);
        self::assertArrayNotHasKey('profile', $metadata);
    }

    public function testMissingManifestIsUnavailableWithoutCreatingIt(): void
    {
        $path = $this->example().'.not-present';
        self::assertSame('unavailable', GatewayRuntimeBootstrap::inspect($path)['configurationState']);
        self::assertFileDoesNotExist($path);
    }

    public function testInvalidJsonDoesNotReachGateway(): void
    {
        $this->expectException(JsonException::class);
        GatewayRuntimeBootstrap::serve(__FILE__);
    }

    public function testRoleIdsStayDistinctAndManifestContainsNoActualProofs(): void
    {
        $configuration = json_decode(file_get_contents($this->example()), true, 64, JSON_THROW_ON_ERROR);
        self::assertNotSame(82, $configuration['gatewayUid']);
        self::assertNotSame(82, $configuration['processorPeer']['uid']);
        self::assertNotSame($configuration['gatewayUid'], $configuration['processorPeer']['uid']);
        self::assertSame(GatewayRuntimeBootstrap::GATEWAY_UID, $configuration['gatewayUid']);
        self::assertSame(GatewayRuntimeBootstrap::GATEWAY_GID, $configuration['gatewayGid']);
        self::assertNull($configuration['profile']);
        self::assertNull($configuration['tokenizerSha256']);
        self::assertNull($configuration['tokenizerPatternSha256']);
        self::assertSame([], $configuration['evidence']);
    }
}
