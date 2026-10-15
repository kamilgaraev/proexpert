<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Requests\Billing\BankInvoiceListRequest;
use App\Http\Requests\Billing\BankRefundRequest;
use App\Http\Responses\AdminResponse;
use App\Http\Responses\LandingResponse;
use App\Models\User;
use App\Services\Billing\Bank\BankBillingService;
use App\Services\Billing\Bank\BankDocumentService;
use App\Services\Billing\Bank\BankRefundService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class BankBillingController
{
    public function __construct(private readonly BankBillingService $billing) {}

    public function capabilities(Request $request): Response
    {
        return $this->respond($request, fn (User $actor, int $org) => $this->billing->capabilities($actor, $org));
    }

    public function index(BankInvoiceListRequest $request): Response
    {
        $validated = $request->validated();

        return $this->respond($request, fn (User $actor, int $org) => $this->billing->invoices($actor, $org,
            (int) ($validated['page'] ?? 1), (int) ($validated['per_page'] ?? 20)));
    }

    public function cancel(Request $request, string $invoice): Response
    {
        return $this->respond($request, function (User $actor, int $org) use ($invoice): array {
            $this->billing->cancel($actor, $org, $invoice);

            return ['canceled' => true];
        });
    }

    public function refund(BankRefundRequest $request, string $invoice): Response
    {
        return $this->respond($request, function (User $actor, int $org) use ($request, $invoice): array {
            $data = $request->validated();

            return app(BankRefundService::class)->request($actor, $org, $invoice, (int) $data['amount_minor'],
                $data['kind'], $data['reason'], $data['client_idempotency_key']);
        });
    }

    public function download(Request $request, string $document): Response
    {
        return $this->respond($request, function (User $actor, int $org) use ($document): Response {
            $record = $this->billing->document($actor, $org, $document);
            $stream = app(BankDocumentService::class)->read($record);

            return response()->streamDownload(function () use ($stream): void {
                try {
                    fpassthru($stream);
                } finally {
                    fclose($stream);
                }
            }, 'MOST-'.$record->public_id.'.pdf', ['Content-Type' => 'application/pdf',
                'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        });
    }

    private function respond(Request $request, callable $action): Response
    {
        $response = $request->is('api/v1/admin/*') ? AdminResponse::class : LandingResponse::class;
        try {
            $actor = $request->user();
            $org = $request->attributes->get('current_organization_id');
            if (! $actor instanceof User || ! is_numeric($org)) {
                throw new AuthorizationException;
            }
            $result = $action($actor, (int) $org);

            return $result instanceof Response ? $result : $response::success($result, trans_message('bank_billing.loaded'));
        } catch (AuthorizationException) {
            return $response::error(trans_message('bank_billing.forbidden'), 403);
        } catch (ModelNotFoundException) {
            return $response::error(trans_message('bank_billing.not_found'), 404);
        } catch (InvalidArgumentException) {
            return $response::error(trans_message('bank_billing.invalid'), 422);
        } catch (Throwable $exception) {
            Log::error('Bank billing request failed.', ['exception' => $exception::class]);

            return $response::error(trans_message('bank_billing.failed'), 503);
        }
    }
}
