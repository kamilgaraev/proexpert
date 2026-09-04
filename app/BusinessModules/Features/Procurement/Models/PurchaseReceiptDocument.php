<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Models;

use App\BusinessModules\Features\Procurement\Enums\PurchaseReceiptDocumentStatusEnum;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PurchaseReceiptDocument extends Model
{
    protected $fillable = [
        'organization_id',
        'purchase_order_id',
        'purchase_receipt_id',
        'uploaded_by_user_id',
        'document_type',
        'status',
        'original_name',
        'storage_key',
        'storage_etag',
        'mime_type',
        'size_bytes',
        'sha256',
        'format_version',
        'document_function',
        'document_number',
        'document_date',
        'seller_inn',
        'buyer_inn',
        'currency_code',
        'validated_snapshot',
        'validation_warnings',
        'validated_at',
        'attached_at',
    ];

    protected $casts = [
        'status' => PurchaseReceiptDocumentStatusEnum::class,
        'document_date' => 'date',
        'size_bytes' => 'integer',
        'validated_snapshot' => 'array',
        'validation_warnings' => 'array',
        'validated_at' => 'immutable_datetime',
        'attached_at' => 'immutable_datetime',
    ];

    protected $attributes = [
        'document_type' => 'upd_xml',
        'status' => 'validated',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function purchaseReceipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class);
    }

    public function uploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
