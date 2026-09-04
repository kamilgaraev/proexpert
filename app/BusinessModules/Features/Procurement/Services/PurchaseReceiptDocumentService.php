<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\DTOs\IncomingUpdValidationResult;
use App\BusinessModules\Features\Procurement\Enums\PurchaseReceiptDocumentStatusEnum;
use App\BusinessModules\Features\Procurement\Exceptions\IncomingUpdAttachmentException;
use App\BusinessModules\Features\Procurement\Exceptions\IncomingUpdValidationException;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceipt;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceiptDocument;
use App\Services\Storage\FileService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class PurchaseReceiptDocumentService
{
    private const DOWNLOAD_TTL_SECONDS = 300;

    public function __construct(
        private readonly IncomingUpdXmlValidator $validator,
        private readonly IncomingUpdOrderMatcher $matcher,
        private readonly FileService $files,
    ) {}

    public function uploadUpd(
        PurchaseOrder $order,
        UploadedFile $file,
        int $actorId,
    ): PurchaseReceiptDocument {
        $contents = $file->getContent();
        $sha256 = hash('sha256', $contents);
        $validation = $this->validator->validate($contents, $file->getClientOriginalName());
        $match = $this->matcher->match($order, $validation);

        if (! $match->isValid()) {
            throw new IncomingUpdValidationException($match->errors, $match->warnings);
        }

        $existing = PurchaseReceiptDocument::query()
            ->where('organization_id', $order->organization_id)
            ->where('purchase_order_id', $order->id)
            ->where('sha256', $sha256)
            ->first();
        if ($existing instanceof PurchaseReceiptDocument) {
            return $existing;
        }

        $storageKey = sprintf(
            'org-%d/procurement/receipt-documents/%d/%s.xml',
            $order->organization_id,
            $order->id,
            Str::uuid()->toString(),
        );
        $stored = $this->files->putPrivate($storageKey, $contents, 'application/xml', $sha256);

        try {
            return PurchaseReceiptDocument::query()->create([
                'organization_id' => $order->organization_id,
                'purchase_order_id' => $order->id,
                'uploaded_by_user_id' => $actorId,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'storage_key' => $stored->key,
                'storage_etag' => $stored->etag,
                'mime_type' => $stored->mime,
                'size_bytes' => $stored->sizeBytes,
                'sha256' => $stored->sha256,
                'format_version' => $validation->formatVersion,
                'document_function' => $validation->function,
                'document_number' => $validation->number,
                'document_date' => $this->documentDate($validation),
                'seller_inn' => $validation->seller['inn'],
                'buyer_inn' => $validation->buyer['inn'],
                'currency_code' => $validation->currencyCode,
                'validated_snapshot' => $this->snapshot($validation, $match->matchedItems),
                'validation_warnings' => $match->warnings === [] ? null : $match->warnings,
                'validated_at' => now(),
            ]);
        } catch (QueryException $exception) {
            $this->deleteStoredFileQuietly($stored->key, $order);
            $duplicate = PurchaseReceiptDocument::query()
                ->where('organization_id', $order->organization_id)
                ->where('purchase_order_id', $order->id)
                ->where('sha256', $sha256)
                ->first();
            if ($duplicate instanceof PurchaseReceiptDocument) {
                return $duplicate;
            }

            throw $exception;
        } catch (Throwable $exception) {
            $this->deleteStoredFileQuietly($stored->key, $order);
            throw $exception;
        }
    }

    /**
     * @param  array<int, array{item_id: int, quantity_received: int|float|string, price: int|float|string}>  $receivedItems
     */
    public function attachValidatedUpd(
        PurchaseOrder $order,
        PurchaseReceipt $receipt,
        int $documentId,
        array $receivedItems,
    ): PurchaseReceiptDocument {
        $document = PurchaseReceiptDocument::query()
            ->whereKey($documentId)
            ->where('organization_id', $order->organization_id)
            ->where('purchase_order_id', $order->id)
            ->lockForUpdate()
            ->first();

        if (! $document instanceof PurchaseReceiptDocument) {
            throw new IncomingUpdAttachmentException('document_not_found');
        }
        if ($document->purchase_receipt_id !== null) {
            if ((int) $document->purchase_receipt_id === (int) $receipt->id) {
                return $document;
            }

            throw new IncomingUpdAttachmentException('document_already_attached');
        }
        if ($document->status !== PurchaseReceiptDocumentStatusEnum::VALIDATED) {
            throw new IncomingUpdAttachmentException('document_not_ready');
        }
        if (! $this->receiptItemsMatch($document, $receivedItems)) {
            throw new IncomingUpdAttachmentException('receipt_items_mismatch');
        }

        $document->forceFill([
            'purchase_receipt_id' => $receipt->id,
            'status' => PurchaseReceiptDocumentStatusEnum::ATTACHED,
            'attached_at' => now(),
        ])->save();

        return $document;
    }

    public function temporaryDownloadUrl(PurchaseReceiptDocument $document): string
    {
        return $this->files->temporaryDownloadUrl($document->storage_key, self::DOWNLOAD_TTL_SECONDS);
    }

    private function deleteStoredFileQuietly(string $storageKey, PurchaseOrder $order): void
    {
        try {
            $this->files->deleteCurrent($storageKey);
        } catch (Throwable $cleanupException) {
            Log::warning('procurement.incoming_upd.cleanup_failed', [
                'organization_id' => $order->organization_id,
                'purchase_order_id' => $order->id,
                'storage_key' => $storageKey,
                'exception' => $cleanupException::class,
            ]);
        }
    }

    private function documentDate(IncomingUpdValidationResult $validation): string
    {
        $date = CarbonImmutable::createFromFormat('!d.m.Y', (string) $validation->date);
        if (! $date instanceof CarbonImmutable || $date->format('d.m.Y') !== $validation->date) {
            throw new IncomingUpdValidationException([['code' => 'document_date_invalid']]);
        }

        return $date->format('Y-m-d');
    }

    /**
     * @param  array<int, array<string, mixed>>  $matchedItems
     * @return array<string, mixed>
     */
    private function snapshot(IncomingUpdValidationResult $validation, array $matchedItems): array
    {
        return [
            'file_id' => $validation->fileId,
            'format_version' => $validation->formatVersion,
            'function' => $validation->function,
            'number' => $validation->number,
            'date' => $validation->date,
            'currency_code' => $validation->currencyCode,
            'seller' => $validation->seller,
            'buyer' => $validation->buyer,
            'items' => $matchedItems,
            'totals' => $validation->totals,
        ];
    }

    /**
     * @param  array<int, array{item_id: int, quantity_received: int|float|string, price: int|float|string}>  $receivedItems
     */
    private function receiptItemsMatch(PurchaseReceiptDocument $document, array $receivedItems): bool
    {
        $expected = collect($document->validated_snapshot['items'] ?? [])
            ->mapWithKeys(fn (array $item): array => [
                (int) ($item['purchase_order_item_id'] ?? 0) => [
                    'quantity' => (float) ($item['quantity'] ?? 0),
                    'price' => (float) ($item['price'] ?? 0),
                ],
            ]);
        $actual = collect($receivedItems)
            ->mapWithKeys(fn (array $item): array => [
                (int) $item['item_id'] => [
                    'quantity' => (float) $item['quantity_received'],
                    'price' => (float) $item['price'],
                ],
            ]);

        if ($expected->keys()->sort()->values()->all() !== $actual->keys()->sort()->values()->all()) {
            return false;
        }

        foreach ($expected as $itemId => $values) {
            $received = $actual->get($itemId);
            if (
                ! is_array($received)
                || abs($values['quantity'] - $received['quantity']) > 0.000000001
                || abs($values['price'] - $received['price']) >= 0.005
            ) {
                return false;
            }
        }

        return true;
    }
}
