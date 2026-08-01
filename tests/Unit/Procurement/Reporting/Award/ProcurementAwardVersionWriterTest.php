<?php

declare(strict_types=1);

namespace Tests\Unit\Procurement\Reporting\Award;

use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalLine;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\BusinessModules\Features\Procurement\Models\SupplierRequestLine;
use App\BusinessModules\Features\Procurement\Reporting\Award\Support\ProcurementAwardVersionProjection;
use App\BusinessModules\Features\Procurement\Services\ProcurementAuditService;
use App\BusinessModules\Features\Procurement\Services\SupplierProposalVersionService;
use App\BusinessModules\Features\Procurement\Services\SupplierRequestVersionService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

final class ProcurementAwardVersionWriterTest extends TestCase
{
    public function test_proposal_version_payload_preserves_operational_terms_and_hashes_a_redacted_award_projection(): void
    {
        $proposal = new SupplierProposal([
            'proposal_number' => 'SP-1',
            'subtotal_amount' => '100.00',
            'delivery_amount' => '5.00',
            'vat_amount' => '20.00',
            'total_amount' => '125.00',
            'currency' => 'rub',
            'vat_mode' => 'included',
            'vat_rate' => '20.00',
            'lead_time_days' => 5,
            'payment_terms' => 'секретные условия',
            'delivery_terms' => 'секретная доставка',
            'notes' => 'секретная заметка',
        ]);
        $proposal->setRelation('lines', new Collection([
            new SupplierProposalLine([
                'id' => 20,
                'supplier_request_line_id' => 2,
                'quantity' => '1.000',
                'unit' => 'pcs',
                'unit_price' => '25.00',
                'total_amount' => '25.00',
                'comment' => 'секретный комментарий',
            ]),
            new SupplierProposalLine([
                'id' => 10,
                'supplier_request_line_id' => 1,
                'quantity' => '2.000',
                'unit' => 'kg',
                'unit_price' => '50.00',
                'total_amount' => '100.00',
            ]),
        ]));

        $service = new SupplierProposalVersionService($this->createMock(ProcurementAuditService::class));
        $payload = $service->commercialSnapshot($proposal);

        self::assertSame('100', $payload['subtotal_amount']);
        self::assertSame('RUB', $payload['currency']);
        self::assertSame([1, 2], array_column($payload['lines'], 'supplier_request_line_id'));
        self::assertSame('2', $payload['lines'][0]['quantity']);
        self::assertSame('секретный комментарий', $payload['lines'][1]['comment']);
        self::assertSame('секретные условия', $payload['payment_terms']);
        self::assertSame('секретная доставка', $payload['delivery_terms']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $service->contentHash($payload));
    }

    public function test_request_version_payload_has_stable_decimal_line_snapshot_and_hash(): void
    {
        $request = new SupplierRequest([
            'id' => 4,
            'request_number' => 'SR-1',
            'purchase_request_id' => 3,
            'comment' => 'не копировать',
            'metadata' => ['token' => 'не копировать'],
        ]);
        $purchaseRequest = new PurchaseRequest(['id' => 3, 'request_number' => 'PR-1']);
        $request->setRelation('purchaseRequest', $purchaseRequest);
        $secondLine = new SupplierRequestLine(['quantity' => '1.000', 'unit' => 'pcs', 'name' => 'B']);
        $secondLine->forceFill(['id' => 2]);
        $firstLine = new SupplierRequestLine(['quantity' => '2.500', 'unit' => 'kg', 'name' => 'A']);
        $firstLine->forceFill(['id' => 1]);
        $request->setRelation('lines', new Collection([$secondLine, $firstLine]));

        $service = new SupplierRequestVersionService($this->createMock(ProcurementAuditService::class));
        $payload = $service->versionPayload($request);

        self::assertSame([1, 2], array_column($payload['line_snapshot'], 'id'));
        self::assertSame('2.5', $payload['line_snapshot'][0]['quantity']);
        self::assertSame('не копировать', $payload['request_snapshot']['comment']);
        self::assertSame(['token' => 'не копировать'], $payload['request_snapshot']['metadata']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $payload['content_hash']);
    }

    public function test_award_projection_excludes_operational_text_without_removing_it_from_the_version_snapshot(): void
    {
        $snapshot = [
            'total_amount' => '100',
            'currency' => 'RUB',
            'payment_terms' => 'Оплата после поставки',
            'delivery_terms' => 'Разгрузка на объекте',
            'warranty_terms' => 'Гарантия 24 месяца',
            'lines' => [[
                'id' => 1,
                'supplier_request_line_id' => 10,
                'quantity' => '1',
                'unit' => 'pcs',
                'comment' => 'Текст условия поставки',
            ]],
        ];

        $projection = ProcurementAwardVersionProjection::proposal($snapshot);

        self::assertArrayNotHasKey('payment_terms', $projection);
        self::assertArrayNotHasKey('delivery_terms', $projection);
        self::assertArrayNotHasKey('warranty_terms', $projection);
        self::assertArrayNotHasKey('comment', $projection['lines'][0]);
        self::assertSame(
            ProcurementAwardVersionProjection::proposalHash($snapshot),
            ProcurementAwardVersionProjection::proposalHash([
                ...$snapshot,
                'payment_terms' => 'Другое условие',
                'lines' => [[...$snapshot['lines'][0], 'comment' => 'Другой текст']],
            ]),
        );
    }
}
