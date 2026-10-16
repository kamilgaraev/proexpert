<?php

declare(strict_types=1);

namespace App\Services\Billing\Bank;

use App\Models\CommercialBankInvoice;
use App\Models\CommercialBankQrCode;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class BankQrService
{
    public function __construct(private readonly BankBillingAccess $access, private readonly TochkaClient $client) {}

    public function enabled(): bool
    {
        return config('bank_billing.sbp_b2b_enabled') === true
            && preg_match('/\A[A-Z0-9]{12}\z/', (string) config('bank_billing.sbp_merchant_id')) === 1;
    }

    public function get(User $actor, int $organizationId, string $publicId): array
    {
        $this->access->organization($actor, $organizationId, true);
        if (! $this->enabled() || ! $this->client->ready() || DB::transactionLevel() !== 0) {
            throw new InvalidArgumentException(trans_message('bank_billing.qr_unavailable'));
        }

        return Cache::lock('billing:bank-qr:'.$publicId, 120)->block(2, function () use ($organizationId, $publicId): array {
            $invoice = CommercialBankInvoice::query()->where('organization_id', $organizationId)->where('public_id', $publicId)->firstOrFail();
            $this->assertPayable($invoice);
            $ttl = min(129600, (int) floor(now()->diffInMinutes($invoice->expires_at)));
            $qr = CommercialBankQrCode::query()->firstOrCreate(['commercial_bank_invoice_id' => $invoice->id], [
                'organization_id' => $organizationId, 'customer_code' => config('bank_billing.customer_code'),
                'merchant_id' => config('bank_billing.sbp_merchant_id'), 'account_id' => config('bank_billing.account_id'),
                'amount_minor' => $invoice->amount_minor, 'expires_at' => now()->addMinutes($ttl),
                'request' => ['amount' => $invoice->amount_minor, 'paymentPurpose' => $invoice->snapshot['purpose'],
                    'sourceName' => 'MOST', 'takeTax' => false,
                    'ttl' => $ttl,
                    'uip' => str_replace('-', '', $invoice->public_id)],
            ]);
            if ((int) $qr->organization_id !== $organizationId || $qr->amount_minor !== (int) $invoice->amount_minor
                || $qr->expires_at->isPast() || mb_strlen($qr->request['paymentPurpose']) > 140) {
                throw new InvalidArgumentException(trans_message('bank_billing.qr_unavailable'));
            }
            if ($qr->qrc_id === null) {
                if ($qr->status !== 'pending') {
                    throw new RuntimeException('bank_qr_creation_unknown');
                }
                $qr->forceFill(['status' => 'creating'])->save();
                try {
                    $response = $this->client->createB2bQr($qr->merchant_id, $qr->account_id, $qr->request);
                    $data = $response['Data'] ?? [];
                    if (! is_string($data['qrcId'] ?? null) || preg_match('/\A[A-Z0-9]{20,100}\z/', $data['qrcId']) !== 1
                        || ! $this->validLink($data['payload'] ?? null)) {
                        throw new RuntimeException('bank_qr_response_invalid');
                    }
                } catch (\Throwable $exception) {
                    $rejected = $exception instanceof RuntimeException
                        && in_array($exception->getMessage(), ['tochka_http_400', 'tochka_http_401', 'tochka_http_403', 'tochka_http_422', 'tochka_http_429'], true);
                    $qr->forceFill(['status' => $rejected ? 'pending' : 'creation_unknown'])->save();
                    throw new RuntimeException('bank_qr_provider_failed');
                }
                $qr->forceFill(['qrc_id' => $data['qrcId'], 'payload' => $data['payload'], 'status' => 'pending'])->save();
            }
            if ($qr->image_base64 === null) {
                $data = $this->client->b2bQr($qr->qrc_id)['Data'] ?? [];
                $image = $data['image'] ?? [];
                $content = is_string($image['content'] ?? null) ? base64_decode($image['content'], true) : false;
                if (($data['qrcId'] ?? null) !== $qr->qrc_id || ($data['accountId'] ?? null) !== $qr->account_id
                    || ($data['merchantId'] ?? null) !== $qr->merchant_id || ($data['amount'] ?? null) !== $qr->amount_minor
                    || ($data['paymentPurpose'] ?? null) !== $qr->request['paymentPurpose'] || ($data['takeTax'] ?? null) !== false
                    || ($data['payload'] ?? null) !== $qr->payload || ($image['mediaType'] ?? null) !== 'image/png'
                    || ! is_string($content) || strlen($content) > 200_000 || ! str_starts_with($content, "\x89PNG\r\n\x1a\n")) {
                    throw new RuntimeException('bank_qr_identity_invalid');
                }
                $qr->forceFill(['image_base64' => base64_encode($content), 'status' => 'ready'])->save();
            }
            $this->assertPayable($invoice->refresh());

            return ['url' => $qr->payload, 'image' => 'data:image/png;base64,'.$qr->image_base64,
                'amount_minor' => $qr->amount_minor, 'expires_at' => $qr->expires_at->toJSON(), 'type' => 'sbp_b2b'];
        });
    }

    private function assertPayable(CommercialBankInvoice $invoice): void
    {
        if ($invoice->status !== 'awaiting_payment' || $invoice->paid_minor !== 0 || $invoice->paid_at !== null
            || $invoice->expires_at->lessThanOrEqualTo(now()->addMinute())
            || $invoice->amount_minor < 1 || $invoice->amount_minor > 100_000_000
            || ($invoice->snapshot['bank']['account'] ?? '').'/'.($invoice->snapshot['bank']['bik'] ?? '') !== config('bank_billing.account_id')) {
            throw new InvalidArgumentException(trans_message('bank_billing.qr_unavailable'));
        }
    }

    private function validLink(mixed $url): bool
    {
        return is_string($url) && strlen($url) <= 2083 && preg_match('#\Ahttps://qr\.nspk\.ru/[A-Za-z0-9/?=&%._~-]+\z#', $url) === 1;
    }
}
