<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\BusinessModules\Features\Procurement\Mail\PurchaseOrderSentMail;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Services\PurchaseOrderPdfService;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Mail\Attachment;
use Mockery;
use PHPUnit\Framework\TestCase;

final class PurchaseOrderSentMailQueueTest extends TestCase
{
    private ?Container $previousContainer = null;

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        Mockery::close();

        parent::tearDown();
    }

    public function test_serialized_mail_payload_contains_private_key_not_url_and_resolves_attachment_via_container(): void
    {
        $path = 'org-12/procurement/purchase-orders/user-34/order-56/object.pdf';
        QueueRestorablePurchaseOrder::$restoredAttributes = [
            'id' => 56,
            'organization_id' => 12,
            'order_number' => 'PO-56',
        ];
        $order = new QueueRestorablePurchaseOrder;
        $order->setRawAttributes(QueueRestorablePurchaseOrder::$restoredAttributes);
        $mail = new PurchaseOrderSentMail($order, $path);

        $payload = serialize($mail);

        self::assertStringContainsString($path, $payload);
        self::assertStringNotContainsString('pdfUrl', $payload);
        self::assertStringNotContainsString('https://', $payload);

        $restored = unserialize($payload, ['allowed_classes' => true]);
        self::assertInstanceOf(PurchaseOrderSentMail::class, $restored);

        $pdfs = Mockery::mock(PurchaseOrderPdfService::class);
        $pdfs->shouldReceive('read')
            ->once()
            ->with(Mockery::type(PurchaseOrder::class), $path)
            ->andReturn('%PDF-1.4 from worker');
        $this->previousContainer = Container::getInstance();
        $container = new Container;
        $container->instance(PurchaseOrderPdfService::class, $pdfs);
        Container::setInstance($container);

        $attachment = $restored->attachments()[0];
        $resolved = $attachment->attachWith(
            static fn (): never => self::fail('Queued attachment must not use a filesystem path or URL.'),
            static fn (Closure $data, Attachment $metadata): array => [
                $data(),
                $metadata->as,
                $metadata->mime,
            ],
        );

        self::assertSame(
            ['%PDF-1.4 from worker', 'Заказ_PO-56.pdf', 'application/pdf'],
            $resolved,
        );
    }
}

final class QueueRestorablePurchaseOrder extends PurchaseOrder
{
    public static array $restoredAttributes = [];

    public function newQueryForRestoration($ids)
    {
        $this->setRawAttributes(self::$restoredAttributes);

        return new class($this)
        {
            public function __construct(private readonly PurchaseOrder $model) {}

            public function useWritePdo(): self
            {
                return $this;
            }

            public function firstOrFail(): PurchaseOrder
            {
                return $this->model;
            }
        };
    }

    public function load($relations): static
    {
        return $this;
    }
}
