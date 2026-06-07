<?php

declare(strict_types=1);

namespace Tests\Unit\OneCExchange;

use App\Services\OneCExchange\Testing\FakeOneCClient;
use App\Services\OneCExchange\Testing\OneCDocumentExchangeFixtureFactory;
use PHPUnit\Framework\TestCase;

final class FakeOneCClientTest extends TestCase
{
    public function test_fake_client_accepts_ready_document_with_idempotency_key(): void
    {
        $client = FakeOneCClient::withScenario('happy_path');
        $document = OneCDocumentExchangeFixtureFactory::contract(id: 10, version: 3);

        $result = $client->sendDocument($document);

        self::assertTrue($result->accepted);
        self::assertSame('accepted', $result->syncStatus);
        self::assertSame('org:contract:10:3', $result->idempotencyKey);
        self::assertSame('1c-contract-10', $result->externalId);
    }

    public function test_fake_client_reports_mapping_and_timeout_failures_without_raw_payload(): void
    {
        $document = OneCDocumentExchangeFixtureFactory::payment(id: 55, version: 1);

        $mappingResult = FakeOneCClient::withScenario('missing_mapping')->sendDocument($document);
        self::assertFalse($mappingResult->accepted);
        self::assertSame('requires_mapping', $mappingResult->syncStatus);
        self::assertSame('mapping_missing', $mappingResult->safeErrorCode);
        self::assertNull($mappingResult->rawResponse);

        $timeoutResult = FakeOneCClient::withScenario('timeout')->sendDocument($document);
        self::assertFalse($timeoutResult->accepted);
        self::assertSame('failed', $timeoutResult->syncStatus);
        self::assertSame('timeout', $timeoutResult->safeErrorCode);
        self::assertTrue($timeoutResult->retryable);
        self::assertNull($timeoutResult->rawResponse);
    }

    public function test_document_fixtures_cover_acts_procurement_and_warehouse_exchange(): void
    {
        $client = FakeOneCClient::withScenario('happy_path');

        $actResult = $client->sendDocument(OneCDocumentExchangeFixtureFactory::act(id: 21, version: 2));
        $procurementResult = $client->sendDocument(OneCDocumentExchangeFixtureFactory::procurementOrder(id: 31, version: 4));
        $warehouseResult = $client->sendDocument(OneCDocumentExchangeFixtureFactory::warehouseMovement(id: 41, version: 5));

        self::assertSame('org:act:21:2', $actResult->idempotencyKey);
        self::assertSame('1c-act-21', $actResult->externalId);
        self::assertSame('org:purchase_order:31:4', $procurementResult->idempotencyKey);
        self::assertSame('1c-purchase-order-31', $procurementResult->externalId);
        self::assertSame('org:warehouse_movement:41:5', $warehouseResult->idempotencyKey);
        self::assertSame('1c-warehouse-movement-41', $warehouseResult->externalId);
    }
}
