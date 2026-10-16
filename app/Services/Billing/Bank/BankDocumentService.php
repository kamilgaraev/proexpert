<?php

declare(strict_types=1);

namespace App\Services\Billing\Bank;

use App\Models\CommercialBankDocument;
use App\Models\CommercialBankInvoice;
use App\Services\Storage\FileService;
use App\Services\Storage\OrganizationStoragePath;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
            'provider' => $kind === 'correction' ? 'local_notice' : 'tochka',
            'provider_customer_code' => $kind === 'correction' ? null : config('bank_billing.customer_code'),
            'snapshot' => $snapshot ?? $invoice->snapshot, 'issued_at' => now(),
        ]);
    }

    public function store(CommercialBankDocument $document): CommercialBankDocument
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuntimeException('bank_document_transaction_invalid');
        }
        if ((int) $document->invoice->organization_id !== (int) $document->organization_id) {
            throw new RuntimeException('bank_document_scope_invalid');
        }
        $stored = Cache::lock('billing:bank-document:'.$document->id, 300)->block(2, function () use ($document): CommercialBankDocument {
            $document->refresh();
            if ($document->status === 'ready') {
                return $document;
            }
            if ($document->provider === 'tochka') {
                $this->createRemote($document);
            }
            $path = $this->path($document);
            if ($this->files->existsCurrent($path)) {
                $stream = $this->files->readCurrentBounded($path, 15, (int) config('bank_billing.maximum_document_bytes') + 1);
                try {
                    $content = stream_get_contents($stream, (int) config('bank_billing.maximum_document_bytes') + 1);
                } finally {
                    fclose($stream);
                }
            } else {
                $content = $document->provider === 'tochka'
                    ? app(TochkaClient::class)->documentPdf($document->kind, $document->provider_customer_code, $document->provider_document_id)
                    : $this->render($document);
                $this->assertPdf($content);
                $this->files->putPrivate($path, $content, 'application/pdf', hash('sha256', $content));
            }
            $this->assertPdf($content);
            $document->forceFill(['storage_path' => $path, 'sha256' => hash('sha256', $content),
                'size_bytes' => strlen($content), 'status' => 'ready', 'failure_code' => null, 'next_attempt_at' => null])->save();

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
        if ($document->provider === 'tochka') {
            throw new RuntimeException('bank_document_local_fallback_forbidden');
        }
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
        $expected = $this->path($document);
        if (! hash_equals($expected, (string) $document->storage_path)
            || (int) $document->invoice->organization_id !== (int) $document->organization_id) {
            throw new RuntimeException('bank_document_scope_invalid');
        }

        return $this->files->readCurrent($expected);
    }

    private function path(CommercialBankDocument $document): string
    {
        return OrganizationStoragePath::forDomain((int) $document->organization_id, 'billing',
            'documents/'.$document->invoice->public_id.($document->provider === 'tochka' ? '/tochka' : ''), $document->public_id, 'pdf');
    }

    private function createRemote(CommercialBankDocument $document): void
    {
        if ($document->provider_document_id !== null) {
            return;
        }
        if ($document->status === 'creating') {
            $document->forceFill(['status' => 'creation_unknown', 'failure_code' => 'creation_unknown'])->save();
        }
        if (in_array($document->status, ['creation_unknown', 'blocked'], true)
            || $document->next_attempt_at?->isFuture()) {
            throw new RuntimeException('bank_document_action_required');
        }
        $client = app(TochkaClient::class);
        if (! $client->ready()) {
            throw new RuntimeException('bank_document_provider_unavailable');
        }
        $parentId = null;
        if ($document->kind === 'closing') {
            $parent = $document->invoice->documents()->where('kind', 'invoice')->first();
            if ($parent?->provider === 'tochka') {
                $parentId = $this->store($parent)->provider_document_id;
            }
        }
        $payload = $document->provider_request ?? app(TochkaDocumentPayload::class)->build($document, $parentId);
        $document->forceFill(['status' => 'creating', 'provider_request' => $payload,
            'provider_customer_code' => $payload['Data']['customerCode'], 'creation_started_at' => now(),
            'failure_code' => null, 'next_attempt_at' => null])->save();
        try {
            $id = $client->createDocument($document->kind, $payload);
        } catch (\Throwable $exception) {
            $code = $exception instanceof RuntimeException ? $exception->getMessage() : '';
            $denied = in_array($code, ['tochka_http_401', 'tochka_http_403', 'tochka_http_429'], true);
            $invalid = in_array($code, ['tochka_http_400', 'tochka_http_422'], true);
            $document->forceFill(['status' => $denied ? 'pending' : ($invalid ? 'blocked' : 'creation_unknown'),
                'failure_code' => $denied ? 'permission_or_rate_limit' : ($invalid ? 'request_rejected' : 'creation_unknown'),
                'next_attempt_at' => $denied ? now()->addMinutes(15) : null])->save();
            throw new RuntimeException('bank_document_provider_failed');
        }
        $document->forceFill(['provider_document_id' => $id, 'status' => 'pending'])->save();
    }

    private function assertPdf(mixed $content): void
    {
        if (! is_string($content) || ! str_starts_with($content, '%PDF-')
            || strlen($content) > (int) config('bank_billing.maximum_document_bytes')) {
            throw new RuntimeException('bank_document_invalid');
        }
    }
}
