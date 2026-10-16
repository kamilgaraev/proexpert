<?php

declare(strict_types=1);

namespace App\Services\Billing\Bank;

use App\Models\CommercialBankInvoice;
use App\Models\User;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use InvalidArgumentException;

final class BankTransferQrService
{
    public function __construct(private readonly BankBillingAccess $access) {}

    public function get(User $actor, int $organizationId, string $invoiceId): array
    {
        $this->access->organization($actor, $organizationId);
        $invoice = CommercialBankInvoice::query()->where('organization_id', $organizationId)
            ->where('public_id', $invoiceId)->firstOrFail();

        return $this->forInvoice($invoice);
    }

    public function payable(CommercialBankInvoice $invoice): bool
    {
        return in_array($invoice->status, ['awaiting_payment', 'partially_paid'], true)
            && $invoice->currency === 'RUB' && $invoice->paid_at === null
            && $invoice->paid_minor >= 0 && $invoice->amount_minor > $invoice->paid_minor
            && $invoice->expires_at !== null && $invoice->expires_at->isFuture();
    }

    public function forInvoice(CommercialBankInvoice $invoice): array
    {
        if (! $this->payable($invoice)) {
            throw new InvalidArgumentException('bank_transfer_qr_not_payable');
        }
        $snapshot = $invoice->snapshot;
        $amount = $invoice->amount_minor - $invoice->paid_minor;
        if (($snapshot['amount_minor'] ?? null) !== $invoice->amount_minor) {
            throw new InvalidArgumentException('bank_transfer_qr_snapshot_invalid');
        }
        $fields = [
            'Name' => $this->text($snapshot['seller']['name'] ?? null, 160),
            'PersonalAcc' => $this->digits($snapshot['bank']['account'] ?? null, [20]),
            'BankName' => $this->text($snapshot['bank']['name'] ?? null, 45),
            'BIC' => $this->digits($snapshot['bank']['bik'] ?? null, [9]),
            'CorrespAcc' => $this->digits($snapshot['bank']['correspondent_account'] ?? null, [20]),
            'PayeeINN' => $this->digits($snapshot['seller']['inn'] ?? null, [10, 12]),
            'Sum' => (string) $amount,
            'Purpose' => $this->text($snapshot['purpose'] ?? null, 210),
        ];
        $payload = 'ST00012';
        foreach ($fields as $key => $value) {
            $payload .= '|'.$key.'='.$value;
        }
        $options = new QROptions(['outputType' => QROutputInterface::GDIMAGE_PNG,
            'outputBase64' => true, 'eccLevel' => EccLevel::M, 'scale' => 5,
            'addQuietzone' => true, 'quietzoneSize' => 4, 'imageTransparent' => false]);

        return ['type' => 'bank_transfer', 'invoice_id' => $invoice->public_id,
            'payload' => $payload, 'image' => (new QRCode($options))->render($payload),
            'amount_minor' => $amount, 'expires_at' => $invoice->expires_at->toJSON()];
    }

    private function text(mixed $value, int $maximum): string
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value, 'UTF-8') > $maximum
            || ! mb_check_encoding($value, 'UTF-8') || preg_match('/[|\x00-\x1f\x7f]/u', $value)) {
            throw new InvalidArgumentException('bank_transfer_qr_field_invalid');
        }

        return $value;
    }

    private function digits(mixed $value, array $lengths): string
    {
        if (! is_string($value) || ! ctype_digit($value) || ! in_array(strlen($value), $lengths, true)) {
            throw new InvalidArgumentException('bank_transfer_qr_field_invalid');
        }

        return $value;
    }
}
