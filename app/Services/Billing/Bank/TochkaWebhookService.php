<?php

declare(strict_types=1);

namespace App\Services\Billing\Bank;

use App\Models\CommercialBankWebhookEvent;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class TochkaWebhookService
{
    public function __construct(private readonly TochkaWebhookVerifier $verifier) {}

    public function receive(string $jwt): void
    {
        $payload = $this->verifier->verify($jwt);
        $type = $payload['webhookType'] ?? null;
        if (! in_array($type, ['incomingPayment', 'outgoingPayment'], true)) {
            return;
        }
        $side = $type === 'incomingPayment' ? 'SideRecipient' : 'SidePayer';
        if (($payload['customerCode'] ?? null) !== config('bank_billing.customer_code')
            || ($payload[$side]['account'] ?? null) !== config('bank_billing.seller_account')
            || ($payload[$side]['bankCode'] ?? null) !== config('bank_billing.seller_bik')) {
            return;
        }
        $date = $payload['date'] ?? null;
        $payment = $payload['paymentId'] ?? null;
        if (! is_string($date) || ! preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date)
            || ! is_string($payment) || $payment === '' || strlen($payment) > 180) {
            throw new InvalidArgumentException('tochka_webhook_invalid');
        }
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->toDateString() !== $date) {
            throw new InvalidArgumentException('tochka_webhook_invalid');
        }
        if ($date < (string) config('bank_billing.start_date') || $parsed->greaterThan(now()->addDay())) {
            return;
        }
        CommercialBankWebhookEvent::query()->firstOrCreate(['fingerprint' => hash('sha256', $jwt)], [
            'event_type' => $type, 'payment_id' => $payment, 'operation_date' => $date,
        ]);
    }
}
