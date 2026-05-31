<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Mail;

use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

use function trans_message;

class SupplierRequestLinkMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public SupplierRequest $supplierRequest,
        public string $publicUrl
    ) {
    }

    public function build(): self
    {
        $this->supplierRequest->loadMissing([
            'organization',
            'purchaseRequest',
            'lines',
            'supplier',
            'externalSupplierContact',
            'supplierParty',
        ]);

        return $this
            ->subject(trans_message('procurement.supplier_requests.email.subject', [
                'request_number' => $this->supplierRequest->request_number,
                'organization' => $this->supplierRequest->organization?->name ?? (string) config('app.name'),
            ]))
            ->view('procurement.emails.supplier-request-link')
            ->with([
                'supplierRequest' => $this->supplierRequest,
                'organization' => $this->supplierRequest->organization,
                'purchaseRequest' => $this->supplierRequest->purchaseRequest,
                'lines' => $this->supplierRequest->lines,
                'publicUrl' => $this->publicUrl,
                'supplierName' => $this->supplierName(),
            ]);
    }

    private function supplierName(): string
    {
        $snapshot = is_array($this->supplierRequest->supplier_snapshot)
            ? $this->supplierRequest->supplier_snapshot
            : [];

        $name = $snapshot['display_name']
            ?? $this->supplierRequest->supplierParty?->display_name
            ?? $this->supplierRequest->supplier?->name
            ?? $this->supplierRequest->externalSupplierContact?->name
            ?? null;

        $name = trim((string) $name);

        return $name !== ''
            ? $name
            : trans_message('procurement.supplier_requests.email.partner');
    }
}
