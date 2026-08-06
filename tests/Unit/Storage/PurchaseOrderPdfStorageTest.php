<?php

declare(strict_types=1);

namespace Tests\Unit\Storage;

use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Services\PurchaseOrderPdfService;
use App\Services\Storage\DTO\CurrentStoredFile;
use App\Services\Storage\FileService;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\TestCase;

final class PurchaseOrderPdfStorageTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_stores_immutable_pdf_inside_organization_and_actor_scope(): void
    {
        $content = '%PDF-1.4 purchase order';
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('putPrivate')
            ->once()
            ->with(
                Mockery::on(static fn (string $path): bool => preg_match(
                    '#^org-12/procurement/purchase-orders/user-34/order-56/[0-9a-f-]{36}\.pdf$#D',
                    $path,
                ) === 1),
                $content,
                'application/pdf',
                hash('sha256', $content),
            )
            ->andReturnUsing(static fn (string $path): CurrentStoredFile => new CurrentStoredFile(
                $path,
                'etag-1',
                strlen($content),
                hash('sha256', $content),
                'application/pdf',
            ));

        $service = new class($files, $content) extends PurchaseOrderPdfService
        {
            public function __construct(FileService $files, private readonly string $content)
            {
                parent::__construct($files);
            }

            public function generate(PurchaseOrder $order): string
            {
                return $this->content;
            }
        };
        $order = new PurchaseOrder;
        $order->setRawAttributes(['id' => 56, 'organization_id' => 12]);

        $stored = $service->store($order, 34);

        self::assertInstanceOf(CurrentStoredFile::class, $stored);
        self::assertMatchesRegularExpression(
            '#^org-12/procurement/purchase-orders/user-34/order-56/[0-9a-f-]{36}\.pdf$#D',
            $stored->key,
        );
        self::assertSame(hash('sha256', $content), $stored->sha256);
    }

    public function test_rejects_read_for_another_organization(): void
    {
        $files = Mockery::mock(FileService::class);
        $files->shouldNotReceive('readCurrent');
        $service = new PurchaseOrderPdfService($files);
        $order = new PurchaseOrder;
        $order->setRawAttributes(['id' => 56, 'organization_id' => 12]);

        $this->expectException(InvalidArgumentException::class);

        $service->read(
            $order,
            'org-13/procurement/purchase-orders/user-34/order-56/object.pdf',
        );
    }

    public function test_reads_owned_pdf_for_queued_mail_attachment(): void
    {
        $path = 'org-12/procurement/purchase-orders/user-34/order-56/object.pdf';
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, '%PDF-1.4 queued attachment');
        rewind($stream);

        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('readCurrent')
            ->once()
            ->with($path)
            ->andReturn($stream);
        $service = new PurchaseOrderPdfService($files);
        $order = new PurchaseOrder;
        $order->setRawAttributes(['id' => 56, 'organization_id' => 12]);

        self::assertSame('%PDF-1.4 queued attachment', $service->read($order, $path));
        self::assertFalse(is_resource($stream));
    }

    public function test_removes_owned_pdf_through_current_object_gateway(): void
    {
        $path = 'org-12/procurement/purchase-orders/user-34/order-56/object.pdf';
        $files = Mockery::mock(FileService::class);
        $files->shouldReceive('deleteCurrent')
            ->once()
            ->with($path);
        $service = new PurchaseOrderPdfService($files);
        $order = new PurchaseOrder;
        $order->setRawAttributes(['id' => 56, 'organization_id' => 12]);

        $service->remove($order, $path);

        self::addToAssertionCount(1);
    }
}
