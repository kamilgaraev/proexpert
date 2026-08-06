<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Mail;

use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Services\PurchaseOrderPdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PurchaseOrderSentMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public PurchaseOrder $order,
        public string $pdfPath,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Заказ поставщику №{$this->order->order_number} от ".$this->order->organization->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'procurement.emails.purchase-order-sent',
            with: [
                'order' => $this->order,
                'organization' => $this->order->organization,
                'supplier' => $this->order->supplier,
                'items' => $this->order->items,
            ],
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(
                fn (): string => app(PurchaseOrderPdfService::class)->read($this->order, $this->pdfPath),
                "Заказ_{$this->order->order_number}.pdf",
            )
                ->withMime('application/pdf'),
        ];
    }
}
