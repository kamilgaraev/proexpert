<?php

declare(strict_types=1);

namespace App\Services\Billing\Bank;

use App\Models\CommercialBankDocument;
use App\Models\CommercialBankInvoice;
use App\Services\Storage\FileService;
use App\Services\Storage\OrganizationStoragePath;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class BankDocumentService
{
    public function __construct(private readonly FileService $files) {}

    public function prepare(CommercialBankInvoice $invoice, string $kind, string $suffix = '', ?array $snapshot = null): CommercialBankDocument
    {
        if (! in_array($kind, ['invoice', 'closing', 'correction'], true)) {
            throw new RuntimeException('bank_document_kind_invalid');
        }

        return CommercialBankDocument::query()->firstOrCreate(['identity' => $invoice->public_id.':'.$kind.':'.$suffix], [
            'public_id' => (string) Str::uuid(), 'organization_id' => $invoice->organization_id,
            'commercial_bank_invoice_id' => $invoice->id, 'kind' => $kind,
            'snapshot' => $snapshot ?? $invoice->snapshot, 'issued_at' => now(),
        ]);
    }

    public function store(CommercialBankDocument $document): CommercialBankDocument
    {
        if ((int) $document->invoice->organization_id !== (int) $document->organization_id) {
            throw new RuntimeException('bank_document_scope_invalid');
        }
        $stored = Cache::lock('billing:bank-document:'.$document->id, 300)->block(2, function () use ($document): CommercialBankDocument {
            $document->refresh();
            if ($document->status === 'ready') {
                return $document;
            }
            $path = OrganizationStoragePath::forDomain((int) $document->organization_id, 'billing',
                'documents/'.$document->invoice->public_id, $document->public_id, 'pdf');
            if ($this->files->existsCurrent($path)) {
                $stream = $this->files->readCurrentBounded($path, 15, (int) config('bank_billing.maximum_document_bytes') + 1);
                try {
                    $content = stream_get_contents($stream, (int) config('bank_billing.maximum_document_bytes') + 1);
                } finally {
                    fclose($stream);
                }
            } else {
                $content = $this->render($document);
                $this->assertPdf($content);
                $this->files->putPrivate($path, $content, 'application/pdf', hash('sha256', $content));
            }
            $this->assertPdf($content);
            $document->forceFill(['storage_path' => $path, 'sha256' => hash('sha256', $content),
                'size_bytes' => strlen($content), 'status' => 'ready'])->save();

            return $document;
        });
        if ($stored->notified_at === null) {
            try {
                $account = $stored->invoice->order->commercialAccount;
                app(\App\Services\Billing\CommercialBillingNotificationService::class)->notify($account,
                    'bank_document_'.$stored->id, 'bank_billing.document_ready', ['document_id' => $stored->public_id, 'invoice_number' => $stored->invoice->number]);
                $stored->forceFill(['notified_at' => now()])->save();
            } catch (\Throwable $exception) {
                Log::error('Bank document notification failed.', ['document_id' => $stored->id, 'exception' => $exception::class]);
            }
        }

        return $stored;
    }

    public function render(CommercialBankDocument $document): string
    {
        $pdf = Pdf::loadView('billing.bank-document', ['document' => $document,
            'invoice' => $document->invoice, 'snapshot' => $document->snapshot]);
        try {
            return $pdf->setPaper('a4')->setOptions(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans'])->output();
        } finally {
            unset($pdf);
            gc_collect_cycles();
        }
    }

    public function read(CommercialBankDocument $document): mixed
    {
        $document = $this->store($document);
        $expected = OrganizationStoragePath::forDomain((int) $document->organization_id, 'billing',
            'documents/'.$document->invoice->public_id, $document->public_id, 'pdf');
        if (! hash_equals($expected, (string) $document->storage_path)
            || (int) $document->invoice->organization_id !== (int) $document->organization_id) {
            throw new RuntimeException('bank_document_scope_invalid');
        }

        return $this->files->readCurrent($expected);
    }

    private function assertPdf(mixed $content): void
    {
        if (! is_string($content) || ! str_starts_with($content, '%PDF-')
            || strlen($content) > (int) config('bank_billing.maximum_document_bytes')) {
            throw new RuntimeException('bank_document_invalid');
        }
    }
}
