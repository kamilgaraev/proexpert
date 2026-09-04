<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Resources;

use App\BusinessModules\Features\Procurement\Models\PurchaseReceiptDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PurchaseReceiptDocument */
final class PurchaseReceiptDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $snapshot = is_array($this->validated_snapshot) ? $this->validated_snapshot : [];

        return [
            'id' => (int) $this->id,
            'type' => 'upd_xml',
            'status' => $this->status->value,
            'original_name' => $this->original_name,
            'size_bytes' => (int) $this->size_bytes,
            'sha256' => $this->sha256,
            'format_version' => $this->format_version,
            'function' => $this->document_function,
            'number' => $this->document_number,
            'date' => $this->document_date?->format('Y-m-d'),
            'currency_code' => $this->currency_code,
            'seller' => $snapshot['seller'] ?? [],
            'buyer' => $snapshot['buyer'] ?? [],
            'items' => $snapshot['items'] ?? [],
            'totals' => $snapshot['totals'] ?? [],
            'warnings' => self::presentIssues($this->validation_warnings ?? []),
            'is_valid' => true,
            'is_attached' => $this->purchase_receipt_id !== null,
        ];
    }

    /**
     * @param  array<int, array{code: string, line_number?: string|null}>  $issues
     * @return array<int, array{code: string, message: string, line_number?: string|null}>
     */
    public static function presentIssues(array $issues): array
    {
        return array_map(static function (array $issue): array {
            $payload = [
                'code' => $issue['code'],
                'message' => trans_message('procurement.upd.issues.'.$issue['code']),
            ];

            if (array_key_exists('line_number', $issue)) {
                $payload['line_number'] = $issue['line_number'];
            }

            return $payload;
        }, $issues);
    }
}
