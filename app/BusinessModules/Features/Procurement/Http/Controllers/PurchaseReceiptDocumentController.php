<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Controllers;

use App\BusinessModules\Features\Procurement\Exceptions\IncomingUpdValidationException;
use App\BusinessModules\Features\Procurement\Http\Requests\UploadIncomingUpdRequest;
use App\BusinessModules\Features\Procurement\Http\Resources\PurchaseReceiptDocumentResource;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceiptDocument;
use App\BusinessModules\Features\Procurement\Services\PurchaseReceiptDocumentService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PurchaseReceiptDocumentController extends Controller
{
    public function __construct(private readonly PurchaseReceiptDocumentService $documents) {}

    public function uploadUpd(UploadIncomingUpdRequest $request, int $id): JsonResponse
    {
        $organizationId = (int) $request->attributes->get('current_organization_id');
        $order = PurchaseOrder::forOrganization($organizationId)->find($id);
        if (! $order instanceof PurchaseOrder) {
            return AdminResponse::error(trans_message('procurement.purchase_orders.not_found'), 404);
        }

        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            return AdminResponse::error(trans_message('procurement.upd.upload.file_required'), 422);
        }

        try {
            $document = $this->documents->uploadUpd(
                $order,
                $file,
                (int) $request->user()->id,
            );

            return AdminResponse::success(
                new PurchaseReceiptDocumentResource($document),
                trans_message('procurement.upd.upload.success'),
                201,
            );
        } catch (IncomingUpdValidationException $exception) {
            return AdminResponse::error(
                trans_message('procurement.upd.upload.validation_failed'),
                422,
                PurchaseReceiptDocumentResource::presentIssues($exception->errors),
                [
                    'code' => 'incoming_upd_validation_failed',
                    'warnings' => PurchaseReceiptDocumentResource::presentIssues($exception->warnings),
                ],
            );
        } catch (Throwable $exception) {
            Log::error('procurement.incoming_upd.upload_failed', [
                'organization_id' => $organizationId,
                'purchase_order_id' => $id,
                'user_id' => $request->user()?->id,
                'exception' => $exception::class,
            ]);

            return AdminResponse::error(trans_message('procurement.upd.upload.failed'), 500);
        }
    }

    public function download(Request $request, int $id, int $document): JsonResponse
    {
        $organizationId = (int) $request->attributes->get('current_organization_id');
        $stored = PurchaseReceiptDocument::query()
            ->whereKey($document)
            ->where('organization_id', $organizationId)
            ->where('purchase_order_id', $id)
            ->first();
        if (! $stored instanceof PurchaseReceiptDocument) {
            return AdminResponse::error(trans_message('procurement.upd.download.not_found'), 404);
        }

        try {
            return AdminResponse::success([
                'url' => $this->documents->temporaryDownloadUrl($stored),
                'filename' => $stored->original_name,
                'expires_in' => 300,
            ]);
        } catch (Throwable $exception) {
            Log::error('procurement.incoming_upd.download_failed', [
                'organization_id' => $organizationId,
                'purchase_order_id' => $id,
                'document_id' => $document,
                'user_id' => $request->user()?->id,
                'exception' => $exception::class,
            ]);

            return AdminResponse::error(trans_message('procurement.upd.download.failed'), 500);
        }
    }
}
